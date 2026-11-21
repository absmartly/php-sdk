<?php

namespace ABSmartly\SDK\Tests\Mocks;

use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;

/** Serves whatever data a test sets in $next, for refresh() tests. */
class HoldoutDataProvider extends ContextDataProvider {
	public ?ContextData $next = null;

	public function __construct() {
	}

	public function getContextData(): ContextData {
		return $this->next;
	}
}
