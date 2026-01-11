<?php

use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCA\Files_AutoRename\Service\RuleAnnotation;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IConfig;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class RenameFileProcessorTest extends TestCase
{
    private LoggerInterface $logger;
    private IConfig $config;

    protected function setUp(): void
    {
        // Simple logger mock
        $this->logger = $this->createMock(LoggerInterface::class);
        $this->config = $this->createMock(IConfig::class);
    }

    public function testReturnsNewNameWhenRuleMatches(): void
    {
        $file = new File('/path/to/example.JPG');
        
        $processorClass = new class($this->logger, $this->config) extends RenameFileProcessor {
            public function getRenameConfigContents(File $file) : array {
                $folder = new Folder('/path/to');
                $rules = <<<TXT
                # This rule does not match
                \.pdf$:.pdf

                # This is the first matching rule
                {
                ^.*:upper($0)_lower($0)
                (?i:jpg):jpeg
                } @ConflictKeepBoth
                
                # This rule would also match, but should not be applied because the first one matched
                ^.*:$0
                TXT;
                return [$rules, $folder, '.rename.conf'];
            }
        };

        $result = $processorClass->processRenameFile($file);
        $this->assertSame('EXAMPLE.JPEG_example.jpeg', $result[0]);
        $this->assertSame([RuleAnnotation::ConflictKeepBoth], $result[2]);
    }

}