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
use OCP\IUserSession;

class RenameListener implements IEventListener {
    private IJobList $jobList;
    private LoggerInterface $logger;
    private string $appName;
    private $userSession;
    
    public function __construct(
        IJobList $jobList,
        LoggerInterface $logger,
        IUserSession $userSession,
        string $appName
    ){
        $this->jobList = $jobList;
        $this->logger = $logger;
        $this->userSession = $userSession;
        $this->appName = $appName;
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

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($targetNode);

        if ($newName !== null) {
            $this->logger->info('Matching rename rule found for ' . $targetNode->getName() . ' - adding RenameJob');
            try {
                $this->jobList->add(RenameJob::class, ['uid' => $this->userSession->getUser()->getUID(), 'id' => $targetNode->getId(), 'path' => $targetNode->getParent()->getPath()]);
            } catch (\Exception $ex) {
                $this->logger->error('Error adding RenameJob: ' . $ex->getMessage());
            }
        } else {
            $this->logger->debug('No matching rename rule found for ' . $targetNode->getName());
        }
    }
}
