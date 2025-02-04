<?php
namespace OCA\NextRename\Event;

use OCA\NextRename\Jobs\RenameJob;
use OCA\NextRename\Service\RenameFileProcessor;
use OCP\BackgroundJob\IJobList;
use function OCP\Log\logger;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Files\Events\Node\BeforeNodeRenamedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use Psr\Log\LoggerInterface;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\Files\File;
use OCP\Files\Lock\ILock;
use OCP\Files\Lock\ILockManager;
use OCP\Files\Lock\ILockProvider;
use OCP\Lock\ILockingProvider;

class RenameListener implements IEventListener {
    private IJobList $jobList;
    private LoggerInterface $logger;
    private ILockManager $lockManager;
    private ILockingProvider $lockingProvider;
    private string $appName;
    
    
    public function __construct(
        IJobList $jobList,
        LoggerInterface $logger,
        ILockManager $lockManager,
        ILockingProvider $lockingProvider,
        string $appName
    ){
        $this->jobList = $jobList;
        $this->logger = $logger;
        $this->lockManager = $lockManager;
        $this->lockingProvider = $lockingProvider;
        $this->appName = $appName;
    }
    
    public function log($message) {
        $this->logger->error($message, ['extra_context' => 'my extra context']);
    }

    public function handle(Event $event): void {
        logger('nextrename')->warning('RenameListener::handle called');
        if (!($event instanceOf NodeRenamedEvent) && !($event instanceOf NodeWrittenEvent)) {
            return;
        }

        if ($event instanceOf NodeWrittenEvent) {
            $targetNode = $event->getNode();
        } else {
            $targetNode = $event->getTarget();
        }
        
        $this->log('Target Node: ' . $targetNode->getPath());

        // Only process files, not folders
        if (!($targetNode instanceof File)) {
            return;
        }

        $filePath = $targetNode->getPath();
        logger('nextrename')->warning('Processing file at path: ' . $filePath);

        // Bestimme den übergeordneten Ordner des betroffenen Files
        $parent = $targetNode->getParent();
        logger('nextrename')->warning('Looking for .rename file at ' . $parent->getPath());

        try {
            $renameFile = $parent->get('.rename');
        } catch (\Exception $e) {
            // .rename-Datei existiert nicht in diesem Ordner – es wird nichts unternommen
            logger('nextrename')->warning('No .rename file found at ' . $parent->getPath() . $e->getMessage());
            return;
        }

        $renameFileProcessor = new RenameFileProcessor($this->logger);
        $newName = $renameFileProcessor->processRenameFile($targetNode);

        if ($newName !== null) {
            logger('nextrename')->warning('Create a RenameJob for ' . $targetNode->getPath());
            try {
                $this->jobList->add(RenameJob::class, ['id' => $targetNode->getId(), 'path' => $parent->getPath()]);
            } catch (\Exception $ex) {
                $this->logger->error('Error adding RenameJob: ' . $ex->getMessage());
            }
        } else {
            $this->logger->warning('No matching rename rule found for ' . $targetNode->getName());
        }
    }
}
