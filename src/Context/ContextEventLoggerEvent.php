<?php

namespace Absmartly\SDK\Context;

class ContextEventLoggerEvent {
	private string $event;
	private ?object $data;

	public const Error = 'Error';
	public const Ready = 'Ready';
	public const Refresh = 'Refresh';
	public const Publish = 'Publish';
	public const Exposure = 'Exposure';
	public const Goal = 'Goal';
	public const Close = 'Close';

	public function __construct(string $event, ?object $data) {
		$this->event = $event;
		$this->data = $data;
	}

	public function getEvent(): string {
		return $this->event;
	}

	public function getData(): ?object {
		return $this->data;
	}
}
