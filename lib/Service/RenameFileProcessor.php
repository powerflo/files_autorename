<?php

namespace OCA\Files_AutoRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use DateTime;
use Smalot\PdfParser\Parser;

class RenameFileProcessor {
    private $logger;

    public const RENAME_FILE_NAME = '.rename.conf';
    public const RULE_DELIMITER = ':';
    public const COMMENT_DELIMITER = '#';
    private const PATTERN_DELIMITER = '/';

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    // If a .rename.conf file is found in the parent folder of the file,
    // apply the rules to the file name and return the new file name
    public function processRenameFile(File $file): ?string {
        $currentName = $file->getName();

        // Don't rename the configuration file itself
        if ($currentName === self::RENAME_FILE_NAME) {
            return null;
        }

        $parent = $file->getParent();
        try {
            $contents = $this->readRenameFile($parent);
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->debug('No ' . self::RENAME_FILE_NAME . ' file found at ' . $parent->getPath() . ': ' . $e->getMessage());
            return null;
        }
        
        $rules = self::parseRules($contents);
        $this->logger->debug('Number of rules in ' . self::RENAME_FILE_NAME . ': ' . count($rules));
        $this->logger->debug('Rules: ' . print_r($rules, true));

        $newName = self::matchRules($rules, $currentName, $file);

        if ($newName === null) {
            $this->logger->debug('No matching rename rule found for ' . $currentName);
            return null;
        }        

        $newName = self::applyTransformations($newName, $file);
        
        if ($newName === $currentName) {
            $this->logger->debug('File name is the same, no rename needed for ' . $currentName);
            return null;
        }
        
        $this->logger->debug('Matching rename rule found for ' . $currentName . ': ' . $newName);
        return $newName;
    }

    // Read the contents of the .rename.conf file
    private function readRenameFile(Folder $parent): ?string {
        $renameFile = $parent->get(self::RENAME_FILE_NAME);
        if ($renameFile instanceof File) {
            return $renameFile->getContent();
        } else {
            $this->logger->error('Error reading ' . self::RENAME_FILE_NAME . ' file: ' . $parent->getPath() . ' is not a file');
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
                $this->logger->warning('Invalid rule format: expected "pattern:replacement". Got: ' . $line);
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
            return $dateTime->format($format);
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
            $this->logger->debug('Applying pdfPatternMatch placeholder with pattern: ' . $pattern . ' and fallback: ' . $fallback);
    
            $parser = new Parser();
            try {
                $pdf = $parser->parseContent($file->getContent());
                $text = $pdf->getText();
                $this->logger->debug('Extracted text from ' . $file->getName() . ': ' . substr($text, 0, 1000) . (strlen($text) > 500 ? '...' : ''));
            } catch (\Exception $e) {
                $this->logger->error('Error parsing pdf file: ' . $e->getMessage());
                return $fallback;
            }

            if (preg_match($pattern, $text, $contentMatches)) {
                $contentMatch = $contentMatches[1] ?? $contentMatches[0];
                $this->logger->debug('Pattern match found: ' . $contentMatch);
                return $contentMatch;
            } else {
                $this->logger->debug('No match found for pattern: ' . $pattern);
                return $fallback;
            }
        }, $replacement);
    }

    // Match the file name against the rules and return the new file name
    private function matchRules(array $rules, string $fileName, File $file): ?string {
        foreach ($rules as $rule) {
            if (preg_match($rule['patterns'][0], $fileName)) {
                $resolvedReplacements = self::applyPlaceholders($rule['replacements'], $file);
                return preg_replace($rule['patterns'], $resolvedReplacements, $fileName);
            }
        }
        return null;
    }
}