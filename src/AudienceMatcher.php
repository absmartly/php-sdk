<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\JsonExpression\Expression;
use JsonException;
use stdClass;
use Throwable;

use function abs;
use function count;
use function error_log;
use function explode;
use function floor;
use function in_array;
use function is_array;
use function is_float;
use function is_int;
use function is_nan;
use function is_infinite;
use function is_object;
use function is_string;
use function json_decode;
use function ltrim;
use function preg_match;
use function sprintf;

use const JSON_THROW_ON_ERROR;
use const PHP_INT_MAX;
use const PHP_INT_MIN;

class AudienceMatcher {
	/**
	 * The backend accepts a split whose percentages sum to 100 within this tolerance, after rounding the gap to
	 * two decimals.
	 */
	private const SPLIT_PERCENTAGES_SUM_TOLERANCE = 0.01;

	private Expression $expression;

	public function __construct() {
		$this->expression = new Expression();
	}

	public function evaluate(stdClass $audience, array $attributes): ?bool {
		if (!isset($audience->filter)) {
			return null;
		}

		if (!is_array($audience->filter) && !is_object($audience->filter)) {
			return null;
		}

		return $this->expression->evaluateBooleanExpr($audience->filter, (object) $attributes);
	}

	/**
	 * Returns the action of the first matching assignment rule: ['variant' => int] for an "assign" rule or
	 * ['split' => float[]] (fractions of 1) for a "split" rule, or null when no rule matches. Mirrors the
	 * JavaScript SDK's AudienceMatcher.evaluateRules.
	 *
	 * A rule is skipped when it is not an object, its type is unknown, an "assign" rule has a non-integer variant,
	 * a "split" rule has invalid percentages, its environments are present but not a list, its non-empty
	 * environments list does not contain $environmentName (a null name never matches a non-empty list), its
	 * conditions are not an object, or its conditions do not evaluate to true. A rule without conditions always
	 * matches, and an empty environments list matches every environment. Variant bounds are the caller's
	 * responsibility.
	 *
	 * @param string $assignmentRules JSON of the form {"rules": [...]}
	 * @return array{variant: int}|array{split: float[]}|null
	 */
	public function evaluateRules(string $assignmentRules, ?string $environmentName, array $attributes): ?array {
		try {
			$parsed = json_decode($assignmentRules, false, 512, JSON_THROW_ON_ERROR);
		}
		catch (JsonException $exception) {
			error_log(sprintf('ABsmartly SDK Error: Failed to parse assignment rules: %s', $exception->getMessage()));
			return null;
		}

		if (!is_object($parsed) || !isset($parsed->rules) || !is_array($parsed->rules)) {
			return null;
		}

		foreach ($parsed->rules as $rule) {
			if (!is_object($rule)) {
				continue;
			}

			$action = self::parseRuleAction($rule);
			if ($action === null) {
				continue;
			}

			if (isset($rule->environments)) {
				if (!is_array($rule->environments)) {
					continue;
				}

				if (count($rule->environments) > 0
					&& ($environmentName === null || !in_array($environmentName, $rule->environments, true))) {
					continue;
				}
			}

			if (!isset($rule->conditions)) {
				return $action;
			}

			if (!is_object($rule->conditions)) {
				continue;
			}

			try {
				if ($this->expression->evaluateBooleanExpr($rule->conditions, (object) $attributes) === true) {
					return $action;
				}
			}
			catch (Throwable $exception) {
				error_log(sprintf(
					'ABsmartly SDK Warning: Failed to evaluate assignment rule conditions for rule "%s": %s',
					is_string($rule->name ?? null) ? $rule->name : '',
					$exception->getMessage()
				));
			}
		}

		return null;
	}

	/**
	 * @return array{variant: int}|array{split: float[]}|null
	 */
	private static function parseRuleAction(object $rule): ?array {
		switch ($rule->type ?? null) {
			case 'assign':
				$variant = self::asInteger($rule->variant ?? null);
				return $variant !== null ? ['variant' => $variant] : null;
			case 'split':
				$split = self::parseSplitPercentages($rule->percentages ?? null);
				return $split !== null ? ['split' => $split] : null;
			default:
				return null;
		}
	}

	/**
	 * JavaScript's Number.isInteger: integral numbers, including a JSON 2.0 decoded as a float.
	 *
	 * @param mixed $value
	 */
	private static function asInteger($value): ?int {
		if (is_int($value)) {
			return $value;
		}

		if (!is_float($value) || is_nan($value) || is_infinite($value) || floor($value) !== $value
			|| $value > PHP_INT_MAX || $value < PHP_INT_MIN) {
			return null;
		}

		return (int) $value;
	}

	/**
	 * Parses percentages such as "20/30/50" into fractions of 1. Returns null when the value is not a string, a
	 * part is not a number or is negative, or the parts do not sum to 100 within the tolerance.
	 *
	 * @param mixed $percentages
	 * @return float[]|null
	 */
	public static function parseSplitPercentages($percentages): ?array {
		if (!is_string($percentages)) {
			return null;
		}

		$values = [];
		$total = 0.0;
		foreach (explode('/', $percentages) as $part) {
			$value = self::parseFloat($part);
			if (is_nan($value) || $value < 0) {
				return null;
			}
			$values[] = $value;
			$total += $value;
		}

		// Like the other SDKs and the backend, the gap is rounded half up to two decimals before the comparison
		// (JavaScript's toFixed(2)). A gap rounds to at most 0.01 exactly when it is below 0.015, and no float is
		// exactly 0.015, so comparing against the 0.015 float literal gives the same result without rounding.
		if (!(abs(100 - $total) <= 0.015)) {
			return null;
		}

		foreach ($values as $i => $value) {
			$values[$i] = $value / 100;
		}

		return $values;
	}

	/**
	 * JavaScript parseFloat: leading whitespace is skipped and the longest decimal prefix is parsed, so "50abc"
	 * is 50 and "abc" is NaN.
	 */
	public static function parseFloat(string $value): float {
		$value = ltrim($value, " \t\n\r\v\f");
		if (preg_match('/^[+-]?Infinity/', $value) === 1) {
			return $value[0] === '-' ? -INF : INF;
		}

		if (preg_match('/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:[eE][+-]?[0-9]+)?/', $value, $match) !== 1) {
			return NAN;
		}

		return (float) $match[0];
	}
}
