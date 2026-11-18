<?php

namespace ABSmartly\SDK\Tests\Switchback;

use ABSmartly\SDK\Switchback\SwitchbackAttributePath;
use ABSmartly\SDK\Switchback\SwitchbackClusterKeys;
use PHPUnit\Framework\TestCase;

/**
 * Port of java-sdk SwitchbackAttributePathTest. Fixture copied byte-for-byte from absmartly/abs 9bd2b5ca35
 * collector/src/test/resources/switchback/attribute-path-cases.json (git blob 7b1d3fed5985).
 */
class SwitchbackAttributePathTest extends TestCase {
	private const FIXTURE = __DIR__ . '/../Fixtures/switchback/attribute-path-cases.json';

	public function testFixtureIsTheByteIdenticalCollectorBlob(): void {
		$bytes = file_get_contents(self::FIXTURE);
		self::assertSame('7b1d3fed59856fcbc296ed68a820b43359ae1932', sha1('blob ' . strlen($bytes) . "\0" . $bytes));
	}

	public function testMatchesEveryPathCase(): void {
		$fixture = json_decode(file_get_contents(self::FIXTURE), false, 512, JSON_THROW_ON_ERROR);
		self::assertCount(7, $fixture->cases);

		foreach ($fixture->cases as $c) {
			$path = SwitchbackAttributePath::parseSupported($c->path);
			if (!$c->supported) {
				self::assertNull($path, json_encode($c));
				continue;
			}
			self::assertNotNull($path, json_encode($c));
			self::assertEquals($c->value, $path->resolve($fixture->attributes), json_encode($c));
			$root = $path->rootKey();
			self::assertEquals($c->value, $path->resolveAttribute($fixture->attributes->{$root}), json_encode($c));
		}
	}

	public function testQuotedSegmentsAreLiteralKeys(): void {
		$attributes = (object) ['geo' => (object) ['0' => 'zero', 'a/b' => 'slash']];
		self::assertSame('zero', SwitchbackAttributePath::parseSupported("geo/'0'")->resolve($attributes));
		self::assertSame('slash', SwitchbackAttributePath::parseSupported('geo/"a/b"')->resolve($attributes));
		self::assertSame('zero', SwitchbackAttributePath::parseSupported("geo/'0'")->resolveAttribute((object) ['0' => 'zero']));
		// PHP cannot tell ['0' => 'zero'] from the list ['zero'], which encodes as a JSON array: pass an object instead.
		self::assertNull(SwitchbackAttributePath::parseSupported("geo/'0'")->resolveAttribute(['0' => 'zero']));
	}

	/**
	 * Malformed paths are unsupported, which makes the experiment unservable: an unterminated quote anywhere, or a '.'
	 * anywhere, quoted or not ('/' nests).
	 */
	public function testRejectsMalformedPaths(): void {
		foreach (["geo/'0", "'geo", '"geo/city', 'geo/"', 'geo.city', 'geo/a.b', 'a./b', '.', 'items/0.5/city',
			"'geo.city'", '"geo.city"', "geo/'a.b'"] as $path) {
			self::assertNull(SwitchbackAttributePath::parseSupported($path), $path);
		}
	}

	public function testSeparatorsAreCollapsed(): void {
		$attributes = (object) ['geo' => (object) ['city' => 'Porto']];
		self::assertSame('Porto', SwitchbackAttributePath::parseSupported('/geo//city/')->resolve($attributes));
		self::assertSame($attributes->geo, SwitchbackAttributePath::parseSupported('geo')->resolve($attributes));
	}

	public function testRejectsUnsupportedPaths(): void {
		foreach ([null, '', '/', '//', '0/city', 'items/0', 'items/0/1/city', 'items/-1/city', "'", 7, ['geo'], (object) []] as $path) {
			self::assertNull(SwitchbackAttributePath::parseSupported($path), json_encode($path));
		}
	}

	public function testIndexSegmentsReadOnlyListsAndKeySegmentsReadOnlyObjects(): void {
		$attributes = (object) [
			'items' => [(object) ['city' => 'Lisbon']],
			'geo' => (object) ['0' => (object) ['city' => 'zero']],
			'name' => 'scalar',
		];
		self::assertNull(SwitchbackAttributePath::parseSupported('geo/0/city')->resolve($attributes));
		self::assertNull(SwitchbackAttributePath::parseSupported('items/1/city')->resolve($attributes));
		self::assertNull(SwitchbackAttributePath::parseSupported('name/x')->resolve($attributes));
		self::assertNull(SwitchbackAttributePath::parseSupported('missing/x')->resolve($attributes));
		self::assertSame('Lisbon', SwitchbackAttributePath::parseSupported('items/-0/city')->resolve($attributes));
	}

	/** A PHP attribute value is read through its JSON encoding: a list is an array, an associative array an object. */
	public function testAttributeValuesAreReadThroughTheirJsonEncoding(): void {
		$path = SwitchbackAttributePath::parseSupported('geo/items/0/city');
		self::assertSame('Lisbon', $path->resolveAttribute(['items' => [['city' => 'Lisbon']]]));
		self::assertSame('Lisbon', $path->resolveAttribute((object) ['items' => [(object) ['city' => 'Lisbon']]]));
		// An associative array with integer-like keys is an object, so "0" is a key segment: not an index.
		self::assertNull($path->resolveAttribute(['items' => [1 => ['city' => 'Lisbon']]]));
		self::assertNull($path->resolveAttribute(['items' => ['0' => 'x', 'a' => ['city' => 'Lisbon']]]));

		$city = SwitchbackAttributePath::parseSupported('geo/city');
		// A float stays a float, so 3.0 never keys a cluster.
		self::assertSame(3.0, $city->resolveAttribute(['city' => 3.0]));
		self::assertNull(SwitchbackClusterKeys::normalize($city->resolveAttribute(['city' => 3.0])));
		self::assertSame(3, $city->resolveAttribute(['city' => 3]));
		self::assertSame('3', SwitchbackClusterKeys::normalize($city->resolveAttribute(['city' => 3])));
		self::assertSame(PHP_INT_MAX, $city->resolveAttribute(['city' => PHP_INT_MAX]));

		$serializable = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				return ['city' => 'Faro'];
			}
		};
		self::assertSame('Faro', $city->resolveAttribute($serializable));
		self::assertSame('Faro', $city->resolveAttribute(['city' => 'Faro', 'other' => $serializable]));
	}

	/** A scalar root has nothing below it; the root itself is the value of a one-segment path. */
	public function testScalarRootValues(): void {
		self::assertNull(SwitchbackAttributePath::parseSupported('geo/city')->resolveAttribute('Porto'));
		self::assertNull(SwitchbackAttributePath::parseSupported('geo/city')->resolveAttribute(null));
		$root = SwitchbackAttributePath::parseSupported('geo');
		self::assertSame('Porto', $root->resolveAttribute('Porto'));
		self::assertSame(7, $root->resolveAttribute(7));
		self::assertSame(1.5, $root->resolveAttribute(1.5));
		self::assertTrue($root->resolveAttribute(true));
	}

	public function testCyclicOrTooDeepOrUnencodableValuesHaveNoKey(): void {
		$path = SwitchbackAttributePath::parseSupported('geo/city');

		$self = new \stdClass();
		$self->city = 'Porto';
		$self->self = $self;
		self::assertNull($path->resolveAttribute($self));

		// 65 nested objects: one past the depth bound.
		$deep = ['leaf' => 'x'];
		for ($i = 0; $i < 64; ++$i) {
			$deep = ['city' => 'Porto', 'next' => $deep];
		}
		self::assertNull($path->resolveAttribute($deep));
		self::assertSame('Porto', $path->resolveAttribute($deep['next']));

		// A value reached twice through siblings is not a cycle.
		$shared = ['a' => 'b'];
		self::assertSame('Porto', $path->resolveAttribute(['city' => 'Porto', 'x' => $shared, 'y' => $shared]));

		self::assertNull($path->resolveAttribute(['city' => 'Porto', 'nan' => NAN]));
		self::assertNull($path->resolveAttribute(['city' => "\xB1\x31"]));
		$throwing = new class implements \JsonSerializable {
			public function jsonSerialize(): array {
				throw new \RuntimeException('no encoding');
			}
		};
		self::assertNull($path->resolveAttribute(['city' => 'Porto', 'x' => $throwing]));
	}

	public function testTryParseIntFollowsGuava(): void {
		self::assertSame(0, SwitchbackAttributePath::tryParseInt('0'));
		self::assertSame(0, SwitchbackAttributePath::tryParseInt('-0'));
		self::assertSame(7, SwitchbackAttributePath::tryParseInt('007'));
		self::assertSame(2147483647, SwitchbackAttributePath::tryParseInt('2147483647'));
		self::assertSame(-2147483648, SwitchbackAttributePath::tryParseInt('-2147483648'));
		foreach (['', '-', '+1', '1a', ' 1', '2147483648', '-2147483649', '99999999999999999999', "\u{0660}", '1.0', '1 '] as $s) {
			self::assertNull(SwitchbackAttributePath::tryParseInt($s), $s);
		}
	}
}
