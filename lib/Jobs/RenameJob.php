<?php
namespace OCA\NextRename\Jobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCA\NextRename\Service\RenameFileProcessor;

class RenameJob extends QueuedJob {
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;

    public function __construct(ITimeFactory $time, LoggerInterface $logger, IRootFolder $rootFolder) {
        parent::__construct($time);

        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
    }

    protected function run($arguments) {
        if (!isset($arguments['id']) || !isset($arguments['path'])) {
            $this->logger->error('RenameJob: Missing id or path argument', ['app' => 'nextrename']);
            return;
        }

        $this->logger->warning($this->rootFolder->getPath());
        $this->logger->warning("Arguments: " . print_r($arguments, true), ['app' => 'nextrename']);
        
        // Perform the renaming logic here...
        
        $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);

        if ($file === null) {
            $this->logger->error('RenameJob: File not found', ['app' => 'nextrename']);
            return;
        }

        $parent = $file->getParent();
        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($parent, $file);

        if ($newName !== null) {
            $this->logger->warning('Renaming ' . $file->getName() . ' to ' . $newName);
            try {
                $file->move($parent->getPath() . '/' . $newName);
                $this->logger->warning('File renamed successfully');
            } catch (\Exception $ex) {
                $this->logger->error('Error renaming file: ' . $ex->getMessage());
            }
        } else {
            $this->logger->warning('No matching rename rule found for ' . $file->getName());
        }

        # After rename OCP\Files\NotFoundException is thrown by /apps/files_versions/lib/Listener/FileEventsListener.php
        # But this is a known issue: https://github.com/nextcloud/server/issues/42343
    }
}