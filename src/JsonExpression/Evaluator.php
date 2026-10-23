<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression;

use ABSmartly\SDK\Exception\InvalidArgumentException;
use ABSmartly\SDK\JsonExpression\Operator\OperatorCollection;
use ABSmartly\SDK\JsonExpression\Operator\OperatorInterface;

use function array_key_exists;
use function count;
use function explode;
use function get_object_vars;
use function gettype;
use function is_array;
use function is_bool;
use function is_nan;
use function is_numeric;
use function is_object;
use function is_resource;
use function is_scalar;
use function property_exists;
use function array_keys;
use function range;
use function is_float;
use function is_int;
use function strcmp;

class Evaluator {
	private OperatorCollection $operators;
	private object $values;

	public function __construct(OperatorCollection $operators, object $values) {
		$this->operators = $operators;
		$this->values = $values;
	}

	public function evaluate($expr) {
		if (is_array($expr)) {
			return $this->operators->and->evaluate($this, $expr);
		}

		if (!is_object($expr)) {
			return null;
		}

		$expressions = get_object_vars($expr);
		foreach ($expressions as $operator => $expression) {
			if (!isset($this->operators->{$operator})) {
				return null;
			}

			$selectedOperator = $this->operators->{$operator};

			/** @var OperatorInterface $selectedOperator */
			return $selectedOperator->evaluate($this, $expression);
		}

		return null;
	}

	public function extractVar(string $path) {
		$accessors = explode('/', $path);

		// Safeguard against potential DoS vector on too deeply nested accessors.
		if (count($accessors) >= 100) {
			throw new InvalidArgumentException('Too deeply nested value extraction clause.');
		}

		// If values is empty, there is no reason point traversing.
		if (empty($this->values)) {
			return null;
		}

		$buffer = $this->values;
		foreach ($accessors as $accessor) {
			if (is_scalar($buffer) || is_resource($buffer) || $buffer === null) {
				return null;
			}

			if (is_object($buffer) && property_exists($buffer, $accessor)) {
				$buffer = $buffer->{$accessor};
				continue;
			}

			if (is_array($buffer) && array_key_exists($accessor, $buffer)) {
				$buffer = $buffer[$accessor];
				continue;
			}

			return null;
		}

		return $buffer;
	}


	/**
	 * @param mixed $value
	 * @return bool|null
	 */
	public static function booleanConvert($value): ?bool {
		if (is_array($value) || is_object($value)) {
			return true;
		}

		if ($value === 'false') {
			return false;
		}
		return !empty($value);
	}

	/**
	 * @param mixed $value
	 * @return float|null
	 */
	public static function numberConvert($value): ?float {
		if (is_bool($value)) {
			return (float) $value;
		}

		if (!is_numeric($value)) {
			return null;
		}

		return (float) $value;
	}

	/**
	 * @param mixed $value
	 * @return void
	 */
	public static function stringConvert($value): ?string {
		if ($value === null) {
			return null;
		}

		if (is_array($value) || is_object($value)) {
			return null;
		}

		if ($value === true) {
			return 'true';
		}

		if ($value === false) {
			return 'false';
		}

		return (string) $value;
	}

	public function compare($lhs, $rhs): ?int {
		if ($lhs === null && $rhs === null) {
			return 0;
		}
		if ($lhs === null || $rhs === null) {
			return null;
		}

		$lhsType = gettype($lhs);

		switch ($lhsType) {
			case 'boolean':
				$rhsValue = self::booleanConvert($rhs);
				return self::compareAndReturn($lhs, $rhsValue);

			case 'string':
				$rhsValue = self::stringConvert($rhs);
				if ($rhsValue === null) {
					return null;
				}

				// strcmp() returns -1, 0, or 1 based on a lexicographical comparison. However, older PHP versions
				// return the difference, so the value is clamped to -1, 0, or 1 here. This can be removed in PHP 8.2
				$lexDif = strcmp($lhs, $rhsValue);

				if ($lexDif === 0) {
					return 0;
				}
				if ($lexDif > 0) {
					return 1;
				}
				return -1;
			case 'integer':
			case 'double':
				$lhs = (float) $lhs;
				$rhsValue = self::numberConvert($rhs);
				return self::compareAndReturn($lhs, $rhsValue);
			case 'resource':
			case 'resource (closed)':
			case 'unknown type':
				return null; // making this condition explicitly visible.
			default: // arrays and objects.
				$result = self::isEqualsDeep($lhs, $rhs);
				if ($result === true) {
					return 0;
				}
				return null;
		}
	}

	private static function compareAndReturn($lhs, $rhsValue): ?int {
		if ($rhsValue === null) {
			return null;
		}
		if ($lhs === $rhsValue) {
			return 0;
		}
		return $lhs > $rhsValue ? 1 : -1;
	}

	protected static function isEqualsDeep($a, $b): bool {
		if (is_object($a)) {
			$a = get_object_vars($a);
			if (!is_array($b) && !is_object($b)) {
				return false;
			}
			if (is_object($b)) {
				return self::isEqualsDeepMap($a, get_object_vars($b));
			}
			if (self::isList($b) && $b !== []) {
				return false;
			}
			return self::isEqualsDeepMap($a, $b);
		}
		if (is_object($b)) {
			return self::isEqualsDeep($b, $a);
		}

		if (is_array($a) || is_array($b)) {
			if (!is_array($a) || !is_array($b)) {
				return false;
			}
			if (self::isList($a) && self::isList($b)) {
				if (count($a) !== count($b)) {
					return false;
				}
				foreach ($a as $index => $value) {
					if (!self::isEqualsDeep($value, $b[$index])) {
						return false;
					}
				}
				return true;
			}
			if (self::isList($a) !== self::isList($b)) {
				return false;
			}
			return self::isEqualsDeepMap($a, $b);
		}

		if (is_float($a) && is_nan($a)) {
			return is_float($b) && is_nan($b);
		}

		if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
			return (float) $a === (float) $b;
		}

		return $a === $b;
	}

	private static function isEqualsDeepMap(array $a, array $b): bool {
		if (count($a) !== count($b)) {
			return false;
		}
		foreach ($a as $key => $value) {
			if (!array_key_exists($key, $b) || !self::isEqualsDeep($value, $b[$key])) {
				return false;
			}
		}
		return true;
	}

	private static function isList(array $value): bool {
		return $value === [] || array_keys($value) === range(0, count($value) - 1);
	}
}
