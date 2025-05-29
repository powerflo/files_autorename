<?php

namespace OCA\Files_AutoRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use DateTime;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use Smalot\PdfParser\Parser;

class RenameFileProcessor {
    public const RENAME_FILE_NAME = '.rename.conf';
    public const RENAME_USER_FILE_NAME = '.rename.user.conf';
    public const RENAME_GROUP_FILE_NAME = '.rename.groupfolder.conf';
    public const RULE_DELIMITER = ':';
    public const COMMENT_DELIMITER = '#';
    private const PATTERN_DELIMITER = '/';

    public function __construct(private LoggerInterface $logger, private IRootFolder $rootFolder) {}

    // If a config file is found, apply the rules to the file name and return the new file name
    public function processRenameFile(File $file): array {
        // Don't rename the configuration file itself
        if (in_array($file->getName(), [self::RENAME_FILE_NAME, self::RENAME_USER_FILE_NAME, self::RENAME_GROUP_FILE_NAME])) {
            return [null, null];
        }

        [$contents, $baseFolder] = $this->getRenameConfigContents($file);
        if ($contents === null) {
            // No rename config file found, return null
            return [null, null];
        }
        
        $rules = self::parseRules($contents);

        $currentName = ltrim($baseFolder->getRelativePath($file->getPath()), '/');
        $this->logger->debug('Base folder: ' . $baseFolder->getPath() . ', Current name: ' . $currentName, ['path' => $file->getPath()]);

        $newName = self::matchRules($rules, $currentName, $file);

        if ($newName === null) {
            $this->logger->info('No matching rule found for ' . $currentName, ['path' => $file->getPath()]);
            return [null, null];
        }

        $newName = self::applyTransformations($newName);
        
        $this->logger->info('New name: ' . $newName, ['path' => $file->getPath()]);
        return [$newName, $baseFolder];
    }

    private function getRenameConfigContents(File $file): array {
        $baseFolder = $file->getParent();
        $contents = $this->readRenameFile($baseFolder, self::RENAME_FILE_NAME);
    
        // Use local rename file if it exists
        if ($contents !== null) {
            return [$contents, $baseFolder];
        }

        $mountRoot = $this->getMountRoot($file);
        $this->logger->debug('Mount root: ' . $mountRoot->getPath(), ['path' => $file->getPath()]);
    
        if ($this->isInHomeStorage($mountRoot)) {
            $this->logger->debug('File is in home storage', ['path' => $file->getPath()]);
            $baseFolder = $mountRoot->get('files');
            return [$this->readRenameFile($baseFolder, self::RENAME_USER_FILE_NAME), $baseFolder];
        }

        if ($this->isInGroupFolder($mountRoot)) {
            $this->logger->debug('File is in a group folder', ['path' => $file->getPath()]);
            $baseFolder = $mountRoot;
            return [$this->readRenameFile($baseFolder, self::RENAME_GROUP_FILE_NAME), $baseFolder];
        }

        $this->logger->debug('No rename file option implemented for the storage at ' . $mountRoot->getPath(), ['path' => $file->getPath()]);

        // Otherwise, use the users rename file
        return [null, null];
    }

    private function getMountRoot(Node $node): Folder {
        while ($node->getInternalPath() !== '') {
            $node = $node->getParent();
        }
        return $node;
    }

    private function isInGroupFolder(Node $node): bool {
        $storage = $node->getStorage();
        $groupFolderStorageClass = 'OCA\\GroupFolders\\Mount\\GroupFolderStorage';
        return class_exists($groupFolderStorageClass) && $storage->instanceOfStorage($groupFolderStorageClass);
    }

    private function isInHomeStorage(Node $node): bool {
        $storage = $node->getStorage();
        return str_starts_with($storage->getId(), 'home::');
    }

    private function readRenameFile(Folder $baseFolder, string $filename): string | null {
        try {
            $renameFile = $baseFolder->get($filename);
            if ($renameFile instanceof File) {
                $this->logger->info('Read content from rename file: ' . $renameFile->getPath());
                return $renameFile->getContent();
            } else {
                $this->logger->warning('Expected rename file but found non-file: ' . $renameFile->getPath());
                return null;
            }
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->debug('No ' . $filename . ' file found in ' . $baseFolder->getPath());
            return null;
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
                $this->logger->warning('Invalid rule format: expected "pattern:replacement" in line: ' . $line);
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

        $this->logger->debug(count($rules) . ' rules parsed from rename file');
        return $rules;
    }

    private function applyPlaceholders(array $replacements, File $file): array {
        $replacements = self::applyDatePlaceholder($replacements);
        $replacements = self::applyFileMTimePlaceholder($replacements, $file);
        $replacements = self::applyExifDateTimeOriginalPlaceholder($replacements, $file);
        $replacements = self::applyPhotoDateTimePlaceholder($replacements, $file);
        $replacements = self::applyPdfPatternMatchPlaceholder($replacements, $file);
        return $replacements;
    }

    // Apply transformations like upper() and lower() to parts of the filename
    private static function applyTransformations(string $name): string {
        // unescape, e.g. \x20 -> space
        $name = stripcslashes($name);

        // upper/lower
        return preg_replace_callback('/(upper|lower)\((.*?)\)/', function ($matches) {
            return $matches[1] === 'upper'
                ? strtoupper($matches[2])
                : strtolower($matches[2]);
        }, $name);
    }

    // Replace {date|format} or {date} placeholders with the current date
    // The format should be a valid date format string for PHP's date() function
    private static function applyDatePlaceholder(array $replacement): array {
        return preg_replace_callback('/\{date(?:\|([^}]+))?\}/', function ($matches) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            return date($format);
        }, $replacement);
    }

    // Replace {fileModifiedAt|format} or {fileModifiedAt} with the file's last modified time
    // The format should be a valid date format string for PHP's date() function
    private static function applyFileMTimePlaceholder(array $replacement, File $file): array {
        return preg_replace_callback('/\{fileModifiedAt(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            return date($format, $file->getMTime());
        }, $replacement);
    }

    // Replace {exifDateTimeOriginal|format} or {exifDateTimeOriginal} with the DateTimeOriginal value from EXIF data
    // The format should be a valid date format string for PHP's date() function
    private function applyExifDateTimeOriginalPlaceholder(array $replacement, File $file): array {
        return preg_replace_callback('/\{exifDateTimeOriginal(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            $fallback = '';
            $metadata = $file->getMetadata();
            
            // Use EXIF data from photos app
            $exif = $metadata['photos-exif'] ?? null;
            if ($exif === null) {
                $this->logger->debug('No photos-exif found in metadata. Using fallback: ' . $fallback, ['path' => $file->getPath()]);
                return $fallback;
            }

            try {
                $dateTime = self::parseExifDate($exif);
            } catch (\Exception | \ValueError $e) {
                $this->logger->debug('Error parsing EXIF date: ' . $e->getMessage() . '. Using fallback: ' . $fallback, ['path' => $file->getPath()]);
                return $fallback;
            }

            return $dateTime->format($format);
        }, $replacement);
    }

    // Replace {photoDateTime|format} or {photoDateTime} with the DateTimeOriginal value from EXIF data or fallback to the file's last modified time
    // The format should be a valid date format string for PHP's date() function
    private function applyPhotoDateTimePlaceholder(array $replacement, File $file): array {
        return preg_replace_callback('/\{photoDateTime(?:\|([^}]+))?\}/', function ($matches) use ($file) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            $fallback = date($format, $file->getMTime());
            $metadata = $file->getMetadata();
            
            // Use EXIF data from photos app
            $exif = $metadata['photos-exif'] ?? null;
            if ($exif === null) {
                $this->logger->debug('No photos-exif found in metadata. Using fallback: ' . $fallback, ['path' => $file->getPath()]);
                return $fallback;
            }

            try {
                $dateTime = self::parseExifDate($exif);
            } catch (\Exception | \ValueError $e) {
                $this->logger->debug('Error parsing EXIF date: ' . $e->getMessage() . '. Using fallback: ' . $fallback, ['path' => $file->getPath()]);
                return $fallback;
            }

            return $dateTime->format($format);
        }, $replacement);
    }

    public function parseExifDate(array $exif): \DateTime {
        $exifDate = $exif['DateTimeOriginal'] ?? null;

        if (null === $exifDate || empty($exifDate) || !\is_string($exifDate)) {
            throw new \Exception('No date found in exif');
        }

        $parsedDate = \DateTime::createFromFormat('Y:m:d H:i:s', $exifDate);
        
        if (!$parsedDate) {
            throw new \Exception("Invalid date: {$exifDate}, expected format YYYY:MM:DD HH:MM:SS");
        }

        return $parsedDate;
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
     * @return array The filename string with placeholders replaced
     */
    private function applyPdfPatternMatchPlaceholder(array $replacement, File $file): array {
        return preg_replace_callback('/\{pdfPatternMatch\|(.)(.+?)\1(?:\|([^}]*))?}/', function ($matches) use ($file) {
            $delimiter = $matches[1];
            $pattern = $delimiter . $matches[2] . $delimiter;
            $fallback = $matches[3] ?? '';
            $this->logger->debug('Applying pdfPatternMatch placeholder with pattern ' . $pattern . ' and fallback ' . $fallback, ['path' => $file->getPath()]);
    
            $parser = new Parser();
            try {
                $pdf = $parser->parseContent($file->getContent());
                $text = $pdf->getText();
                $this->logger->debug('Extracted text from PDF: ' . substr($text, 0, 1000) . (strlen($text) > 500 ? '...' : ''), ['path' => $file->getPath()]);
            } catch (\Exception $e) {
                $this->logger->error('Error parsing PDF file: ' . $e->getMessage(), ['path' => $file->getPath()]);
                return $fallback;
            }

            if (preg_match($pattern, $text, $contentMatches)) {
                $contentMatch = $contentMatches[1] ?? $contentMatches[0];
                $this->logger->debug('PDF pattern match found: ' . $contentMatch, ['path' => $file->getPath()]);
                return $contentMatch;
            } else {
                $this->logger->debug('No PDF pattern match found, using fallback: ' . $fallback, ['path' => $file->getPath()]);
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
        return null;
    }
}