<?php

namespace Absmartly\SDK;

class VariableParser {
	public function parse(string $experimentName, string $config): ?object {
		try {
			return json_decode($config, false, 512, JSON_THROW_ON_ERROR);
		}
		catch (\Exception $exception) {
			return null;
		}
	}
}
