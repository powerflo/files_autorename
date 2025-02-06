<?php
namespace OCA\Files_AutoRename\Event;

use OCA\Files_AutoRename\Jobs\RenameJob;
use OCA\Files_AutoRename\Service\RenameFileProcessor;
use OCP\BackgroundJob\IJobList;
use function OCP\Log\logger;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;

class RenameListener implements IEventListener {
    private IJobList $jobList;
    private LoggerInterface $logger;
    private string $appName;
    
    public function __construct(
        IJobList $jobList,
        LoggerInterface $logger,
        string $appName
    ){
        $this->jobList = $jobList;
        $this->logger = $logger;
        $this->appName = $appName;
    }

    public function handle(Event $event): void {
        logger('files_autorename')->debug('RenameListener::handle called');
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
        logger('files_autorename')->debug('Processing file at path: ' . $filePath);

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($targetNode);

        if ($newName !== null) {
            logger('files_autorename')->info('Create a RenameJob for ' . $targetNode->getPath());
            try {
                $this->jobList->add(RenameJob::class, ['id' => $targetNode->getId(), 'path' => $targetNode->getParent()->getPath()]);
            } catch (\Exception $ex) {
                $this->logger->error('Error adding RenameJob: ' . $ex->getMessage());
            }
        } else {
            $this->logger->debug('No matching rename rule found for ' . $targetNode->getName());
        }
    }
}
