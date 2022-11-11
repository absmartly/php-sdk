<?php

namespace ABSmartly\SDK\Tests\Mocks;

use ABSmartly\SDK\Context\ContextEventHandler;
use ABSmartly\SDK\PublishEvent;

class ContextEventHandlerMock extends ContextEventHandler {
	public array $submitted = [];
	public $prerun = null;

	public function publish(PublishEvent $event): void {
		if (is_callable($this->prerun)) {
			($this->prerun)();
		}
		$this->submitted[] = json_decode(json_encode($event), false, 512, JSON_THROW_ON_ERROR);
	}
}
