<?php
namespace OCA\Files_AutoRename\Jobs;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use Psr\Log\LoggerInterface;
use OCP\Files\IRootFolder;
use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUserManager;
use OCP\IUserSession;
use OCP\Notification\IManager;
use OCP\Remote\IUser;

class RenameJob extends QueuedJob {
    private LoggerInterface $logger;
    private IRootFolder $rootFolder;
    private $userSession;
    private $userManager;
    private IManager $notificationManager;

    public function __construct(
            ITimeFactory $time,
            LoggerInterface $logger,
            IRootFolder $rootFolder,
            IUserSession $userSession,
            IUserManager $userManager,
            IManager $notificationManager) {
        parent::__construct($time);

        $this->logger = $logger;
        $this->rootFolder = $rootFolder;
        $this->userSession = $userSession;
        $this->userManager = $userManager;
        $this->notificationManager = $notificationManager;
    }

    protected function run($arguments) {
        $this->logger->debug('RenameJob: Running', ['app' => 'files_autorename']);

        if (!isset($arguments['uid']) || !isset($arguments['id']) || !isset($arguments['path'])) {
            $this->logger->error('RenameJob: Missing arguments');
            return;
        }

        $this->userSession->setUser($this->userManager->get($arguments['uid']));

        $this->logger->debug("Arguments: " . print_r($arguments, true), ['app' => 'files_autorename']);
                
        $file = $this->rootFolder->getFirstNodeByIdInPath($arguments['id'], $arguments['path']);

        if (!($file instanceof File)) {
            return;
        }
        
        if ($file === null) {
            $this->logger->error('RenameJob: File not found', ['app' => 'files_autorename']);
            return;
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($file);

        if ($newName === null) {
            $this->logger->debug('No matching rename rule found for ' . $file->getName());
            return;
        }

        $parent = $file->getParent();
        $newDirPath = dirname($newName);
        
        $this->logger->info('Matching rename rule found for ' . $file->getName() . ' - renaming to ' . $newName);
        
        // Do not rename if a file with the new name already exists
        if ($parent->nodeExists($newName)) {
            $this->logger->warning('File with the new name already exists: ' . $newName);
            return;
        }
        
        // Check if the directory exists, and create it if it doesn't
        if (!$parent->nodeExists($newDirPath)) {
            $this->logger->debug('Target directory does not exist, creating: ' . $newDirPath);
            $this->createDirectories($parent, $newDirPath);
        }
        
        $newDir = $parent->get($newDirPath);
        
        try {
            // Check permissions to create a file in the target directory
            // It seems like Nextcloud does not check the permissions properly when moving a file
            if ($newDirPath !== '.' && !$newDir->isCreatable()) {
                throw new \OCP\Files\NotPermittedException('No permission to create file in ' . $newDirPath);
            }

            $newPath = $parent->getPath() . '/' . $newName;
            $this->logger->debug('Moving file to ' . $newPath);
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