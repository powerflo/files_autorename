<?php

namespace OCA\Files_AutoRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use DateTime;
use OCP\Files\IRootFolder;
use Smalot\PdfParser\Parser;

class RenameFileProcessor {
    public const RENAME_FILE_NAME = '.rename.conf';
    public const RENAME_USER_FILE_NAME = '.rename.user.conf';
    public const RULE_DELIMITER = ':';
    public const COMMENT_DELIMITER = '#';
    private const PATTERN_DELIMITER = '/';

    public function __construct(private LoggerInterface $logger, private IRootFolder $rootFolder) {}

    // If a config file is found, apply the rules to the file name and return the new file name
    public function processRenameFile(File $file): array {
        // Don't rename the configuration file itself
        if (in_array($file->getName(), [self::RENAME_FILE_NAME, self::RENAME_USER_FILE_NAME])) {
            return [null, null];
        }

        [$contents, $baseFolder] = $this->getRenameConfigContents($file);
        if ($contents === null) {
            // No rename config file found, return null
            return [null, null];
        }
        
        $rules = self::parseRules($contents);

        $currentName = ltrim($baseFolder->getRelativePath($file->getPath()), '/');
        $this->logger->debug('Base folder: ' . $baseFolder->getPath() . ' Current name: ' . $currentName, ['path' => $file->getPath()]);

        $newName = self::matchRules($rules, $currentName, $file);

        if ($newName === null) {
            return [null, null];
        }

        $newName = self::applyTransformations($newName);
        
        if ($newName === $currentName) {
            $this->logger->debug('File name is the same, no rename needed', ['path' => $file->getPath()]);
            return [null, null];
        }
        
        return [$newName, $baseFolder];
    }

    private function getRenameConfigContents(File $file): array {
        [$contents, $baseFolder] = $this->readLocalRenameFile($file);
    
        // Use local rename file if it exists
        if ($contents !== null) {
            return [$contents, $baseFolder];
        }
    
        // Otherwise, use the users rename file
        return $this->readUserRenameFile($file);
    }

    private function readLocalRenameFile(File $file): array {
        $baseFolder = $file->getParent();
        return $this->readRenameFile($baseFolder, self::RENAME_FILE_NAME);
    }

    private function readUserRenameFile(File $file): array {
        $parentFolder = $file->getParent();
    
        // Only if the file is in the home storage, otherwise we don't know where to look
        $storageId = $parentFolder->getStorage()->getId();
        if (!str_starts_with($storageId, 'home::')) {
            $this->logger->debug('File is not in the home storage, storageId: ' . $storageId, ['path' => $file->getPath()]);
            return [null, null];
        }
    
        // Look for the rename file in the user folder
        $owner = $parentFolder->getOwner();
        $baseFolder = $this->rootFolder->getUserFolder($owner->getUID());
    
        return $this->readRenameFile($baseFolder, self::RENAME_USER_FILE_NAME);
    }

    private function readRenameFile(Folder $baseFolder, string $filename): array {
        try {
            $renameFile = $baseFolder->get($filename);
            if ($renameFile instanceof File) {
                $this->logger->info('Rename configuration file found', ['path' => $renameFile->getPath()]);                return [$renameFile->getContent(), $baseFolder];
            } else {
                $this->logger->warning('Expected rename file but found non-file', ['path' => $renameFile->getPath()]);
                return [null, null];
            }
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->debug('Rename configuration file not found', ['path' => $baseFolder->getPath() . '/' . $filename, 'exception' => $e->getMessage()]);
            return [null, null];
        }
    }

    // Parse the contents of the .rename.conf file and return an array of rules
    private function parseRules(string $contents): array {
        $rules = [];
        $lines = explode("\n", $contents);
    
        $insideGroup = false;
        $groupPatterns = [];
        $groupReplacements = [];
    
        foreach ($lines as $line) {
            $line = trim($line);
    
            // Skip empty lines and comments
            if ($line === '' || strpos($line, self::COMMENT_DELIMITER) === 0) {
                continue;
            }
    
            if ($line === '{') {
                // Start of a grouped rule
                $insideGroup = true;
                $groupPatterns = [];
                $groupReplacements = [];
                continue;
            }
    
            if ($line === '}') {
                // End of a grouped rule
                if (!empty($groupPatterns) && !empty($groupReplacements)) {
                    $rules[] = [
                        'patterns'     => $groupPatterns,
                        'replacements' => $groupReplacements
                    ];
                }
                $insideGroup = false;
                continue;
            }
    
            // Split on the first unescaped colon
            $parts = preg_split('/(?<!\\\\):/', $line, 2);

            if (!isset($parts[0]) || !isset($parts[1])) {
                $this->logger->warning('Invalid rule format: expected "pattern:replacement"', ['line' => $line]);
                // Skip invalid lines
                continue;
            }

            $pattern = trim($parts[0]);
            $replacement = trim($parts[1]);
    
            // Escape the pattern and wrap it with delimiters
            $escapedPattern = self::PATTERN_DELIMITER . str_replace(self::PATTERN_DELIMITER, '\\' . self::PATTERN_DELIMITER, $pattern) . self::PATTERN_DELIMITER;

            if ($insideGroup) {
                $groupPatterns[] = $escapedPattern;
                $groupReplacements[] = $replacement;
            } else {
                $rules[] = [
                    'patterns'     => [$escapedPattern],
                    'replacements' => [$replacement]
                ];
            }
        }

        $this->logger->debug('Rules parsed', ['count' => count($rules)]);
        return $rules;
    }

    private function applyPlaceholders(array $stringsWithPlaceholders, File $file): array {
        $resolvedStrings = [];
    
        foreach ($stringsWithPlaceholders as $string) {
            $string = self::applyDatePlaceholder($string);
            $string = self::applyFileMTimePlaceholder($string, $file);
            $string = self::applyExifDateTimeOriginalPlaceholder($string, $file);
            $string = self::applyPhotoDateTimePlaceholder($string, $file);
            $string = self::applyPdfPatternMatchPlaceholder($string, $file);
            $resolvedStrings[] = $string;
        }
    
        return $resolvedStrings;
    }

    // Apply transformations like upper() and lower() to parts of the filename
    private static function applyTransformations(string $name): string {
        return preg_replace_callback('/(upper|lower)\((.*?)\)/', function ($matches) {
            return $matches[1] === 'upper'
                ? strtoupper($matches[2])
                : strtolower($matches[2]);
        }, $name);
    }

    // Replace {date|format} or {date} placeholders with the current date
    // The format should be a valid date format string for PHP's date() function
    private static function applyDatePlaceholder(string $replacement): string {
        return preg_replace_callback('/\{date(?:\|([^}]+))?\}/', function ($matches) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            return date($format);
        }, $replacement);
    }

    // Replace {fileModifiedAt|format} or {fileModifiedAt} with the file's last modified time
    // The format should be a valid date format string for PHP's date() function
    private static function applyFileMTimePlaceholder(string $replacement, File $file): string {
        return preg_replace_callback('/\{fileModifiedAt(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            return date($format, $file->getMTime());
        }, $replacement);
    }

    // Replace {exifDateTimeOriginal|format} or {exifDateTimeOriginal} with the DateTimeOriginal value from EXIF data
    // The format should be a valid date format string for PHP's date() function
    private static function applyExifDateTimeOriginalPlaceholder(string $replacement, File $file): string {
        return preg_replace_callback('/\{exifDateTimeOriginal(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            
            // The photos app writes the EXIF data to the metadata
            $metadata = $file->getMetadata();
            if (!isset($metadata['photos-exif']['DateTimeOriginal'])) {
                return '';
            }
            $exifDateTimeOriginal = $metadata['photos-exif']['DateTimeOriginal'];

            // Convert the EXIF dateTimeOriginal format to a DateTime object
            $dateTime = DateTime::createFromFormat('Y:m:d H:i:s', $exifDateTimeOriginal);
            return $dateTime ? $dateTime->format($format) : '';
        }, $replacement);
    }

    // Replace {photoDateTime|format} or {photoDateTime} with the DateTimeOriginal value from EXIF data or fallback to the file's last modified time
    // The format should be a valid date format string for PHP's date() function
    private static function applyPhotoDateTimePlaceholder(string $replacement, File $file): string {
        return preg_replace_callback('/\{photoDateTime(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            
            // The photos app writes the EXIF data to the metadata
            $metadata = $file->getMetadata();
            if (!isset($metadata['photos-exif']['DateTimeOriginal'])) {
                // fallback to file modified time
                return date($format, $file->getMTime());;
            }
            $exifDateTimeOriginal = $metadata['photos-exif']['DateTimeOriginal'];

            // Convert the EXIF dateTimeOriginal format to a DateTime object
            $dateTime = DateTime::createFromFormat('Y:m:d H:i:s', $exifDateTimeOriginal);
            
            return $dateTime->format($format);
        }, $replacement);
    }

    /**
     * Replaces placeholders of the form {pdfPatternMatch|/pattern/|fallback} in the filename.
     *
     * This function extracts the text content from the PDF file and applies a user-specified regular expression
     * to search for a match. If a match is found, the first capture group is returned (or the full match if no group is found).
     * If no match is found, or if an error occurs during PDF parsing, the optional fallback value is used instead.
     *
     * Placeholder syntax:
     *   {pdfPatternMatch|/pattern/} - Returns the matched string or an empty string if no match
     *   {pdfPatternMatch|/pattern/|fallback} - Returns the matched string or the fallback value if no match or on error
     *
     * Notes:
     * - The delimiter used in the regex pattern is user-defined (e.g., /pattern/, #pattern#, etc.).
     * - PDF parsing errors (e.g., invalid file contents) are caught, and fallback is returned in those cases.
     *
     * @param string $replacement The filename string containing the placeholder
     * @param File $file The file object, used to read the PDF contents
     * @return string The filename string with placeholders replaced
     */
    private function applyPdfPatternMatchPlaceholder(string $replacement, File $file): string {
        return preg_replace_callback('/\{pdfPatternMatch\|(.)(.+?)\1(?:\|([^}]*))?}/', function ($matches) use ($file) {
            $delimiter = $matches[1];
            $pattern = $delimiter . $matches[2] . $delimiter;
            $fallback = $matches[3] ?? '';
            $this->logger->debug('Applying pdfPatternMatch placeholder', ['pattern' => $pattern, 'fallback' => $fallback, 'path' => $file->getPath()]);
    
            $parser = new Parser();
            try {
                $pdf = $parser->parseContent($file->getContent());
                $text = $pdf->getText();
                $this->logger->debug('Extracted text from PDF', ['path' => $file->getPath(), 'text' => substr($text, 0, 1000) . (strlen($text) > 500 ? '...' : '')]);
            } catch (\Exception $e) {
                $this->logger->error('Error parsing PDF file', ['message' => $e->getMessage(), 'path' => $file->getPath()]);
                return $fallback;
            }

            if (preg_match($pattern, $text, $contentMatches)) {
                $contentMatch = $contentMatches[1] ?? $contentMatches[0];
                $this->logger->debug('PDF pattern match found', ['pattern' => $pattern, 'match' => $contentMatch, 'path' => $file->getPath()]);
                return $contentMatch;
            } else {
                $this->logger->debug('Using fallback due to no match found', ['pattern' => $pattern, 'fallback' => $fallback, 'path' => $file->getPath()]);
                return $fallback;
            }
        }, $replacement);
    }

    // Match the file name against the rules and return the new file name
    private function matchRules(array $rules, string $fileName, File $file): ?string {
        foreach ($rules as $rule) {
            if (preg_match($rule['patterns'][0], $fileName)) {
                $resolvedReplacements = self::applyPlaceholders($rule['replacements'], $file);
                $result = preg_replace($rule['patterns'], $resolvedReplacements, $fileName);

                $this->logger->debug('preg_replace', ['pattern' => $rule['patterns'], 'replacement' => $resolvedReplacements[0], 'subject' => $fileName, 'result' => $result, 'path' => $file->getPath()]);
                return $result;
            }
        }

        $this->logger->debug('No matching rule found', ['path' => $file->getPath()]);
        return null;
    }
}