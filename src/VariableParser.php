<?php

namespace ABSmartly\SDK;

use Exception;

use function json_decode;

use const JSON_THROW_ON_ERROR;

class VariableParser {
	public function parse(string $experimentName, string $config): ?object {
		try {
			return json_decode($config, false, 512, JSON_THROW_ON_ERROR);
		}
		catch (Exception $exception) {
			error_log(sprintf(
				'ABsmartly SDK Error: Failed to parse variant config for experiment "%s": %s',
				$experimentName,
				$exception->getMessage()
			));
			return null;
		}
	}
}
