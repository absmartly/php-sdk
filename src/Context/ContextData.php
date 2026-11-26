<?php

namespace ABSmartly\SDK\Context;

class ContextData extends \ABSmartly\SDK\DynamicModel {

	public array $experiments;
	/** @var object[] The collector's holdouts ({id, name, iteration, seedHi, seedLo, split, ...}), by experiments' holdoutIds. */


	public function __construct(array $experiments = [], array $holdouts = []) {
		$this->experiments = $experiments;
		if (!(property_exists($this, 'holdouts') && (new \ReflectionProperty($this, 'holdouts'))->isInitialized($this))) {
			try { $this->holdouts = $holdouts; } catch (\TypeError $exception) { }
		}
	}
}
