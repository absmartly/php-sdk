<?php

namespace ABSmartly\SDK;

use ABSmartly\SDK\JsonExpression\Expression;
use stdClass;

class AudienceMatcher {
	private Expression $expression;

	public function __construct() {
		$this->expression = new Expression();
	}

	public function evaluate(stdClass $audience, array $attributes): ?bool {
		if (!isset($audience->filter)) {
			return null;
		}

		return $this->expression->evaluateBooleanExpr($audience->filter, (object) $attributes);
	}
}
