<?php
namespace OCA\NextRename\Jobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCA\NextRename\Service\RenameFileProcessor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Notification\IManager;

class RenameJob extends QueuedJob {
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;
    private IManager $notificationManager;

    public function __construct(ITimeFactory $time, LoggerInterface $logger, IRootFolder $rootFolder, IManager $notificationManager) {
        parent::__construct($time);

        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
        $this->notificationManager = $notificationManager;
    }

    protected function run($arguments) {
        if (!isset($arguments['id']) || !isset($arguments['path'])) {
            $this->logger->error('RenameJob: Missing id or path argument', ['app' => 'nextrename']);
            return;
        }

        $this->logger->debug("Arguments: " . print_r($arguments, true), ['app' => 'nextrename']);
                
        $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);

        if (!($file instanceof File)) {
            return;
        }
        
        if ($file === null) {
            $this->logger->error('RenameJob: File not found', ['app' => 'nextrename']);
            return;
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($file);

        if ($newName === null) {
            $this->logger->debug('No matching rename rule found for ' . $file->getName());
            return;
        }

        $parent = $file->getParent();
        $this->logger->info('Renaming ' . $file->getName() . ' to ' . $newName);

        // Do not rename if a file with the new name already exists
        if ($parent->nodeExists($newName)) {
            $this->logger->warning('File with the new name already exists: ' . $newName);
            return;
        }

        $newDirPath = dirname($newName);
        
        // Check if the directory exists, and create it if it doesn't
        if (!$parent->nodeExists($newDirPath)) {
            $this->logger->debug('Directory does not exist, creating: ' . $newDirPath);
            $this->createDirectories($parent, $newDirPath);
        }
        
        try {
            $newPath = $parent->getPath() . '/' . $newName;
            $file->move($newPath);
            $this->logger->debug('File renamed successfully');
        } catch (\Exception $ex) {
            $this->logger->error('Error renaming file: ' . $ex->getMessage());
        }

        # After rename OCP\Files\NotFoundException is thrown by /apps/files_versions/lib/Listener/FileEventsListener.php
        # But this is a known issue: https://github.com/nextcloud/server/issues/42343
    }

    private function createDirectories(Folder $parent, string $path): void {
        $parts = explode('/', $path);
        $currentPath = '';
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $currentPath .= '/' . $part;
            if (!$parent->nodeExists($currentPath)) {
                $parent->newFolder($currentPath);
            }
        }
    }
}