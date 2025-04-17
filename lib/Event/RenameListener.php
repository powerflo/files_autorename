<?php
namespace OCA\Files_AutoRename\Event;

use OCA\Files_AutoRename\Jobs\RenameJob;
use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCP\BackgroundJob\IJobList;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

class RenameListener implements IEventListener {
    private IJobList $jobList;
    private LoggerInterface $logger;

    public function __construct(
        IJobList $jobList,
        LoggerInterface $logger
    ){
        $this->jobList = $jobList;
        $this->logger = $logger;
    }

    public function handle(Event $event): void {
        $this->logger->debug('RenameListener::handle called');
        if (!($event instanceOf NodeRenamedEvent) && !($event instanceOf NodeWrittenEvent)) {
            return;
        }

        if ($event instanceOf NodeWrittenEvent) {
            $targetNode = $event->getNode();
        } else {
            $targetNode = $event->getTarget();
        }
        
        $this->logger->debug('Target Node: ' . $targetNode->getPath());

        // Only process files, not folders
        if (!($targetNode instanceof File)) {
            return;
        }

        $filePath = $targetNode->getPath();
        $this->logger->debug('Processing file at path: ' . $filePath);

        if (!$targetNode->isReadable()) {
            // If the file is not readable, i.e. uploaded through a public share link with upload only permissions
            // the .readme.conf file cannot be read and we cannot determine if the file should be renamed or not
            // also the metadata for images could not be refreshed
            // so we add a RenameJob with the refreshMetadata option
            $this->logger->info('File is not readable, adding RenameJob with refreshMetadata option');
            $this->jobList->add(RenameJob::class, ['id' => $targetNode->getId(), 'path' => $targetNode->getParent()->getPath(), 'retryCount' => 1, 'refreshMetadata' => 1]);
            return;
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($targetNode);

        if ($newName === null) {
            return;
        }

        $this->logger->info('Adding RenameJob for ' . $targetNode->getName());
        try {
            $this->jobList->add(RenameJob::class, ['id' => $targetNode->getId(), 'path' => $targetNode->getParent()->getPath(), 'retryCount' => 1]);
        } catch (\Exception $ex) {
            $this->logger->error('Error adding RenameJob: ' . $ex->getMessage());
        }
    }
}
