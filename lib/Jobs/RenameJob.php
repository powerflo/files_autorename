<?php
namespace OCA\Files_AutoRename\Jobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserSession;
use OCP\FilesMetadata\IFilesMetadataManager;
use OCP\BackgroundJob\IJobList;

class RenameJob extends QueuedJob {
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;
    private IUserSession $userSession;
    private IFilesMetadataManager $filesMetadataManager;
    private IJobList $jobList;

    public function __construct(
            ITimeFactory $time,
            LoggerInterface $logger,
            IRootFolder $rootFolder,
            IUserSession $userSession,
            IFilesMetadataManager $filesMetadataManager,
            IJobList $jobList) {
        parent::__construct($time);

        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->filesMetadataManager = $filesMetadataManager;
        $this->jobList = $jobList;
    }

    protected function run($arguments) {
        $this->logger->debug("RenameJob running with arguments: " . print_r($arguments, true), ['app' => 'files_autorename']);

        if (!isset($arguments['id']) || !isset($arguments['path']) || !isset($arguments['retryCount'])) {
            $this->logger->error('RenameJob: Missing arguments');
            return;
        }
                
        $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);

        if (!($file instanceof File)) {
            return;
        }
        
        if ($file === null) {
            $this->logger->error('RenameJob: File not found', ['app' => 'files_autorename']);
            return;
        }

        if (isset($arguments['refreshMetadata'])) {
            $this->logger->info('Refreshing metadata for ' . $file->getName());
            $metadata = $this->filesMetadataManager->refreshMetadata($file, IFilesMetadataManager::PROCESS_LIVE);
            $this->logger->debug('File metadata: ' . print_r($metadata, true), ['app' => 'files_autorename']);
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($file);

        if ($newName === null) {
            return;
        }
        
        $parent = $file->getParent();
        $newDirPath = dirname($newName);
        
        $this->userSession->setUser($parent->getOwner());
        
        // Do not rename if a file with the new name already exists
        if ($parent->nodeExists($newName)) {
            $this->logger->warning('File with the new name already exists: ' . $newName . ' - not renaming');
            return;
        }
        
        // Check if the directory exists, and create it if it doesn't
        if (!$parent->nodeExists($newDirPath)) {
            $this->logger->debug('Target directory does not exist, creating: ' . $newDirPath);
            $this->createDirectories($parent, $newDirPath);
        }

        $newDir = $parent->get($newDirPath);
        
        // Check permissions to create a file in the target directory
        // It seems like Nextcloud does not check the permissions properly when moving a file
        if ($newDirPath !== '.' && !$newDir->isCreatable()) {
            throw new \OCP\Files\NotPermittedException('No permission to create file in ' . $newDirPath);
        }

        try {
            $newPath = $parent->getPath() . '/' . $newName;
            $this->logger->debug('Moving file to ' . $newPath);
            $file->move($newPath);
            $this->logger->debug('File renamed successfully');
        } catch (\Exception $ex) {
            if($arguments['retryCount'] > 0) {
                $this->logger->info('Move ' . $file->getName() . ' to ' . $newName . ' failed: ' . $ex->getMessage());
                $this->logger->info('Retrying rename job');
                $this->jobList->add(RenameJob::class, ['id' => $arguments['id'], 'path' => $arguments['path'], 'retryCount' => $arguments['retryCount'] - 1]);
            } else {
                $this->logger->error('Move ' . $file->getName() . ' to ' . $newName . ' failed: ' . $ex->getMessage());
                $this->logger->info('Max retry count reached, not retrying');
            }
        }

        # After rename OCP\Files\NotFoundException is thrown by /apps/files_versions/lib/Listener/FileEventsListener.php
        # But this is a known issue: https://github.com/nextcloud/server/issues/42343
    }

    private function createDirectories(Folder $parent, string $path): void {
        $parts = explode('/', $path);
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            if (!$parent->nodeExists($part)) {
                $parent->newFolder($part);
            }
            $parent = $parent->get($part);
        }
    }
}