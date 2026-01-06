<?php

// Stub Psr\Log\LoggerInterface
namespace Psr\Log {
    interface LoggerInterface {
        public function warning(string $message, array $context = []): void;
        public function info(string $message, array $context = []): void;
        public function debug(string $message, array $context = []): void;
    }
}

namespace OCP\Files {
    // Stand-in File class
    class File {
        private string $path;
        public function __construct(string $path) {
            $this->path = $path;
        }
        public function getName(): string { return basename($this->path); }
        public function getPath(): string { return $this->path; }
    }
    
    // Stand-in Folder class
    class Folder {
        private string $path;
        public function __construct(string $path) {
            $this->path = $path;
        }
        public function getPath(): string { return $this->path; }
        public function getRelativePath(string $filePath): string {
            return substr($filePath, strlen($this->path)); // Simplified
        }
    }
}
