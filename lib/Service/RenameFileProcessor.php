<?php

namespace OCA\NextRename\Service;

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

    public function readRenameFile(Folder $parent): ?string {
        $renameFile = $parent->get(self::RENAME_FILE_NAME);
        if ($renameFile instanceof File) {
            return $renameFile->getContent();
        } else {
            $this->logger->error('Error reading ' . self::RENAME_FILE_NAME . ' file: ' . $parent->getPath() . ' is not a file');
            return null;
        }
    }
    
    public function processRenameFile(File $file): ?string {
        $parent = $file->getParent();
        
        try {
            $contents = $this->readRenameFile($parent);
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->debug('No ' . self::RENAME_FILE_NAME . ' file found at ' . $parent->getPath() . ': ' . $e->getMessage());
            return null;
        }
        
        $rules = self::parseRules($contents);
        $this->logger->debug('Number of rules in ' . self::RENAME_FILE_NAME . ': ' . count($rules));

        return self::matchRules($rules, $file->getName());
    }

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

    private static function matchRules(array $rules, string $fileName): ?string {
        foreach ($rules as $rule) {
            $regex = '/' . $rule['pattern'] . '/';
            if (preg_match($regex, $fileName)) {
                return preg_replace($regex, $rule['replacement'], $fileName);
            }
        }
        return null;
    }
}