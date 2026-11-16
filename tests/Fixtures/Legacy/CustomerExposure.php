<?php

namespace Customer\App;

use ABSmartly\SDK\Exposure;

/**
 * A customer subclass written against v1.0.3, overriding the 11-parameter Exposure::create factory.
 */
class CustomerExposure extends Exposure {
	public static function create(
		int $id, string $name, ?string $unit, int $variant, float $exposedAt, bool $assigned, bool $eligible,
		bool $overridden, bool $fullOn, bool $custom, bool $audienceMismatch
	): Exposure {
		return parent::create($id, $name, $unit, $variant, $exposedAt, $assigned, $eligible, $overridden, $fullOn, $custom, $audienceMismatch);
	}
}
