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

class RenameJob extends QueuedJob
{
    public function __construct(
        ITimeFactory $time,
        private LoggerInterface $logger,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IFilesMetadataManager $filesMetadataManager,
        private IJobList $jobList
    ) {
        parent::__construct($time);
    }

    protected function run($arguments)
    {
        $this->logger->debug('RenameJob running', ['arguments' => $arguments]);

        if (!isset($arguments['id']) || !isset($arguments['path']) || !isset($arguments['retryCount'])) {
            $this->logger->error('RenameJob: Missing arguments');
            return;
        }

        $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);

        if ($file === null) {
            $this->logger->error('File with id ' . $arguments['id'] . ' not found in path ' . $arguments['path']);
            return;
        }

        if (!($file instanceof File)) {
            return;
        }

        if (isset($arguments['refreshMetadata'])) {
            $metadata = $this->filesMetadataManager->refreshMetadata($file, IFilesMetadataManager::PROCESS_LIVE);
            $this->logger->debug('Metadata refreshed', ['path' => $file->getPath(), 'metadata' => $metadata->asArray()]);
            $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger, $this->rootFolder);
        [$newName, $baseFolder] = $renameFileProcessor->processRenameFile($file);

        if ($newName === null) {
            return;
        }

        $this->userSession->setVolatileActiveUser($baseFolder->getOwner());
        
        $filePath = $file->getPath();
        $newFilePath = $baseFolder->getPath() . '/' . $newName;
        $newDirname = dirname($newName);

        if ($filePath === $newFilePath) {
            $this->logger->info('New file name is the same as the old one - not renaming', ['path' => $file->getPath()]);
            return;
        }

        // Do not rename if a file with the new name already exists
        if ($baseFolder->nodeExists($newName)) {
            $this->logger->warning('File with the new name ' . $newName . ' already exists - not renaming', ['path' => $file->getPath()]);
            return;
        }

        // Check if the directory exists, and create it if it doesn't
        if (!$baseFolder->nodeExists($newDirname)) {
            $this->logger->debug('Creating target directory: ' . $newDirname, ['path' => $file->getPath()]);
            $this->createDirectories($baseFolder, $newDirname);
        }

        $newFolder = $baseFolder->get($newDirname);

        // Check permissions to create a file in the target directory
        // It seems like Nextcloud does not check the permissions properly when moving a file
        if ($newDirname !== '.' && !$newFolder->isCreatable()) {
            $this->logger->warning('Insufficient permissions to move file to ' . $newDirname, ['path' => $file->getPath()]);
            throw new \OCP\Files\NotPermittedException();
        }

        try {
            $file->move($newFilePath);
            $this->logger->info('File moved successfully to ' . $newFilePath, ['path' => $filePath]);
        } catch (\Exception $ex) {
            if ($arguments['retryCount'] > 0) {
                $this->logger->info('Rename to ' . $newFilePath . ' failed. Exception: ' . $ex->getMessage() . '. Retrying...', ['path' => $file->getPath(), 'retryCount' => $arguments['retryCount']]);

                $this->jobList->add(RenameJob::class, [
                    'id' => $arguments['id'],
                    'path' => $arguments['path'],
                    'retryCount' => $arguments['retryCount'] - 1
                ]);
            } else {
                $this->logger->error('Rename to ' . $newFilePath . ' failed. Exception: ' . $ex->getMessage(), ['path' => $file->getPath()]);
            }
        }
    }

    private function createDirectories(Folder $parent, string $path): void
    {
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