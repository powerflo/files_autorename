<?php
namespace OCA\Files_AutoRename\Event;

use OCA\Files_AutoRename\Jobs\RenameJob;
use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Files\Storage\ISharedStorage;

class RenameListener implements IEventListener {
    public function __construct(private IJobList $jobList, private LoggerInterface $logger, private IRootFolder $rootFolder) {}

    public function handle(Event $event): void {
        if (!($event instanceOf NodeRenamedEvent) && !($event instanceOf NodeCreatedEvent)) {
            return;
        }
        
        if ($event instanceOf NodeCreatedEvent) {
            $targetNode = $event->getNode();
        } else {
            $targetNode = $event->getTarget();
        }
        
        $this->logger->debug('RenameListener handling ' . get_class($event) . ' event', ['path' => $targetNode->getPath()]);
        
        // Only process files, not folders
        if (!($targetNode instanceof File)) {
            return;
        }

        $this->logger->debug('Processing file: ' . $targetNode->getPath(), ['path' => $targetNode->getPath()]);

        if (!$targetNode->isReadable()) {
            // If the file is not readable, i.e. uploaded through a public share link with upload only permissions
            // the .readme.conf file cannot be read and we cannot determine if the file should be renamed or not
            // also the metadata for images could not be refreshed
            // so we add a RenameJob with the refreshMetadata option
            $this->logger->info('File is not readable, adding RenameJob with refreshMetadata option');
            $this->jobList->add(RenameJob::class, ['id' => $targetNode->getId(), 'path' => $targetNode->getParent()->getPath(), 'retryCount' => 1, 'refreshMetadata' => 1]);
            return;
        }

        $targetNode = $this->adjustFileForSharedStorage($targetNode);

        $renameFileProcessor = new RenameFileProcessor($this->logger, $this->rootFolder);
        [$newName] = $renameFileProcessor->processRenameFile($targetNode);

        if ($newName === null) {
            return;
        }

        try {
            $this->logger->info('Adding RenameJob', ['path' => $targetNode->getPath()]);
            $this->jobList->add(RenameJob::class, [
                'id' => $targetNode->getId(),
                'path' => $targetNode->getParent()->getPath(),
                'retryCount' => 1
            ]);
        } catch (\Exception $ex) {
            $this->logger->error('Error adding RenameJob: ' . $ex->getMessage(), ['path' => $targetNode->getPath()]);
        }
    }

    private function adjustFileForSharedStorage(File $file): File {
        $parentFolder = $file->getParent();
    
        try {
            $storage = $parentFolder->getStorage();
        } catch (NotFoundException) {
            return $file;
        }

        if (!$storage->instanceOfStorage(ISharedStorage::class)) {
			return $file;
		}

        /** @var ISharedStorage $storage */
        $share = $storage->getShare();

        $sharedFile = $share->getNode()->getFirstNodeById($file->getId());
        
        if ($sharedFile === null) {
            // In case a files is moved by a rule into a shared folder getFirstNodeById() returns null
            // but get() returns the node
            $sharedFile = $share->getNode()->get($file->getName());
        }
            
        $this->logger->debug('File is in shared storage, using original file ' . $sharedFile->getPath(), ['path' => $file->getPath()]);
        
        return $sharedFile;
    }
}