<?php

declare(strict_types=1);

namespace OCA\Files_AutoRename\AppInfo;

use OCA\Files_AutoRename\Event\RenameListener;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\EventDispatcher\IEventDispatcher;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;

class Application extends App implements IBootstrap {
	public const APP_ID = 'files_autorename';

	/** @psalm-suppress PossiblyUnusedMethod */
	public function __construct() {
		parent::__construct(self::APP_ID);

		/* @var IEventDispatcher $dispatcher */
		$dispatcher = $this->getContainer()->get(IEventDispatcher::class);
		$dispatcher->addServiceListener(NodeRenamedEvent::class, RenameListener::class, -1000000);
		$dispatcher->addServiceListener(NodeWrittenEvent::class, RenameListener::class, -1000000);
	}
	
	public function register(IRegistrationContext $context): void {
	}

	public function boot(IBootContext $context): void {
	}
}
