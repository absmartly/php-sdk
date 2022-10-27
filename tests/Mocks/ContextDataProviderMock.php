<?php

namespace Absmartly\SDK\Tests\Mocks;

use Absmartly\SDK\Context\ContextData;
use Absmartly\SDK\Context\ContextDataProvider;

class ContextDataProviderMock extends ContextDataProvider {
	private string $source = 'context.json';

	public function getContextData(): ContextData {
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
