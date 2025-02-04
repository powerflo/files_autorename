<?php

namespace OCA\NextRename\Service;

use OCP\Files\File;
use OCP\Files\Folder;
use Psr\Log\LoggerInterface;

class RenameFileProcessor {
    private $logger;

    public function __construct(LoggerInterface $logger) {
        $this->logger = $logger;
    }

    public function readRenameFile(Folder $parent): ?string {
        $renameFile = $parent->get('.rename');
        if ($renameFile instanceof File) {
            return $renameFile->getContent();
        } else {
            $this->logger->error('Error reading .rename file: ' . $parent->getPath() . ' is not a file');
            return null;
        }
    }

    public function processRenameFile(File $file): ?string {
        $parent = $file->getParent();

        try {
            $contents = $this->readRenameFile($parent);
        } catch (\OCP\Files\NotFoundException $e) {
            $this->logger->error('No .rename file found at ' . $parent->getPath() . ': ' . $e->getMessage());
            return null;
        }

        $this->logger->warning('Contents of .rename file: ' . $contents);

        $lines = explode("\n", $contents);
        $rules = [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, ':') === false) {
                continue;
            }
            list($pattern, $replacement) = explode(':', $line, 2);
            $pattern = trim($pattern);
            $replacement = trim($replacement);
            if ($pattern !== '' && $replacement !== '') {
                $rules[] = [
                    'pattern'     => $pattern,
                    'replacement' => $replacement
                ];
            }
        }

        $fileName = $file->getName();
        foreach ($rules as $rule) {
            $regex = '/' . $rule['pattern'] . '/';
            if (preg_match($regex, $fileName)) {
                return preg_replace($regex, $rule['replacement'], $fileName);
            }
        }

        return null;
    }
}