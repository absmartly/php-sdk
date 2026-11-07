<?php

namespace ABSmartly\SDK\Client;

use ABSmartly\SDK\PublishEvent;
use React\Promise\PromiseInterface;

interface AsyncClientInterface extends ClientInterface {
	public function getContextDataAsync(): PromiseInterface;
	public function publishAsync(PublishEvent $publishEvent): PromiseInterface;
}
