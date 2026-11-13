<?php

namespace ABSmartly\SDK\Tests\Client;

use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Client\ClientConfig;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase {
	public function testDecodeHandsDeeplyNestedJson(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$client = new Client($clientConfig);

		$nested = '{"a":{"b":{"c":{"d":{"e":{"f":{"g":{"h":{"i":{"j":{"k":{"l":{"m":{"n":{"o":{"p":{"q":"deep"}}}}}}}}}}}}}}}}}';
		$result = $client->decode($nested);

		self::assertSame('deep', $result->a->b->c->d->e->f->g->h->i->j->k->l->m->n->o->p->q);
	}

	public function testDecodeThrowsOnInvalidJson(): void {
		$clientConfig = new ClientConfig('endpoint', 'key', 'app', 'env');
		$client = new Client($clientConfig);

		$this->expectException(\JsonException::class);
		$client->decode('invalid json');
	}
}
