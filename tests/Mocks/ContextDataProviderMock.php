<?php

namespace ABSmartly\SDK\Tests\Mocks;

use ABSmartly\SDK\Context\ContextData;
use ABSmartly\SDK\Context\ContextDataProvider;

class ContextDataProviderMock extends ContextDataProvider {
	private string $source = 'context.json';
	public $prerun = null;

	public function getContextData(): ContextData {
		if (is_callable($this->prerun)) {
			($this->prerun)();
		}

		$data = json_decode(
			file_get_contents(__DIR__.'/../Fixtures/json/'. $this->source),
			false,
			512,
			JSON_THROW_ON_ERROR
		);
		return new ContextData($data->experiments);
	}

	public function setSource(string $source): void {
		$this->source = $source;
	}
}
