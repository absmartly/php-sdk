<?php

namespace ABSmartly\SDK\Context;

class ContextData {
	public array $experiments;
	/** @var object[] The collector's holdouts ({id, name, iteration, seedHi, seedLo, split, ...}), by experiments' holdoutIds. */
	public $holdouts = [];

	public function __construct(array $experiments = [], array $holdouts = []) {
		$this->experiments = $experiments;
		$this->holdouts = $holdouts;
	}
}
