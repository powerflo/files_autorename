<?php

namespace OCA\Files_AutoRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;
use DateTime;

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
        // Don't rename the configuration file itself
        if ($file->getName() === self::RENAME_FILE_NAME) {
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

        $newName = self::matchRules($rules, $file->getName());
        $newName = self::applyPlaceholders($newName, $file);
        $this->logger->debug('New name: ' . $newName);
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

    // Replace placeholders in the replacement string
    private static function applyPlaceholders(string|null $name, File $file): string|null {
        if ($name === null) {
            return null;
        }

        $name = self::applyDatePlaceholder($name);
        $name = self::applyFileMTimePlaceholder($name, $file);
        $name = self::applyExifDateTimeOriginalPlaceholder($name, $file);
        $name = self::applyPhotoDateTimePlaceholder($name, $file);
        return $name;
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
                // TODO: try to generate metadata because it may have not yet been generated
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

    // Match the file name against the rules and return the new file name
    private static function matchRules(array $rules, string $fileName): ?string {
        foreach ($rules as $rule) {
            if (preg_match($rule['patterns'][0], $fileName)) {
                return preg_replace($rule['patterns'], $rule['replacements'], $fileName);
            }
        }
        return null;
    }
}