<?php

namespace ABSmartly\SDK\Context;

#[\AllowDynamicProperties]
class ContextData {

	public array $experiments;
	/** @var object[] The collector's holdouts ({id, name, iteration, seedHi, seedLo, split, ...}), by experiments' holdoutIds. */


	public function __construct(array $experiments = [], array $holdouts = []) {
		$this->experiments = $experiments;
		try {
			$this->holdouts = $holdouts;
		}
		catch (\TypeError $exception) {
			// A legacy subclass may reserve this name with an incompatible type.
		}
	}
}
