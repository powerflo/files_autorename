<?php

namespace OCA\Files_AutoRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

class RenameFileProcessor {
    private $logger;

    public const RENAME_FILE_NAME = '.rename.conf';
    public const RULE_DELIMITER = ':';
    public const COMMENT_DELIMITER = '#';

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    // If a .rename.conf file is found in the parent folder of the file,
    // apply the rules to the file name and return the new file name
    public function processRenameFile(File $file): ?string {
        $parent = $file->getParent();
        
        try {
            $contents = $this->readRenameFile($parent);
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->debug('No ' . self::RENAME_FILE_NAME . ' file found at ' . $parent->getPath() . ': ' . $e->getMessage());
            return null;
        }
        
        $rules = self::parseRules($contents);
        $rules = self::applyPlaceholders($rules);
        $this->logger->debug('Number of rules in ' . self::RENAME_FILE_NAME . ': ' . count($rules));

        return self::matchRules($rules, $file->getName());
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
    private static function parseRules(string $contents): array {
        $lines = explode("\n", $contents);
        $rules = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, self::RULE_DELIMITER) === false || strpos($line, self::COMMENT_DELIMITER) === 0) {
                continue;
            }
            list($pattern, $replacement) = explode(self::RULE_DELIMITER, $line, 2);
            $pattern = trim($pattern);
            $replacement = trim($replacement);
            if ($pattern !== '' && $replacement !== '') {
                $rules[] = [
                    'pattern'     => $pattern,
                    'replacement' => $replacement
                ];
            }
        }
        return $rules;
    }

    // Replace placeholders in the replacement string
    private static function applyPlaceholders(array $rules): array {
        foreach ($rules as &$rule) {
            $rule['replacement'] = self::applyDatePlaceholder($rule['replacement']);
        }
        return $rules;
    }

    // Replace {date|format} or {date} placeholders with the current date
    // The format should be a valid date format string for PHP's date() function
    private static function applyDatePlaceholder(string $replacement): string {
        return preg_replace_callback('/\{date(?:\|([^}]+))?\}/', function ($matches) {
            $format = $matches[1] ?? 'Y-m-d'; // Use 'Y-m-d' as the default format
            return date($format);
        }, $replacement);
    }

    // Match the file name against the rules and return the new file name
    private static function matchRules(array $rules, string $fileName): ?string {
        foreach ($rules as $rule) {
            $pattern = '/' . $rule['pattern'] . '/';
            if (preg_match($pattern, $fileName)) {
                return preg_replace($pattern, $rule['replacement'], $fileName);
            }
        }
        return null;
    }
}