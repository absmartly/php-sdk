<?php

namespace ABSmartly\SDK\Context;

class ContextData {
	public array $experiments;

	public function __construct(array $experiments = []) {
		$this->experiments = $experiments;
	}
}
