<?php
declare(strict_types=1);
namespace Absmartly\SDK\JsonExpression\Operator;

use Absmartly\SDK\JsonExpression\Evaluator;

use function is_array;
use function is_object;
use function is_string;

class VarOperator implements OperatorInterface {

	public function evaluate(Evaluator $evaluator, $path = null) {
		if (is_object($path)) {
			$path = $path->path;
		}

		if (is_array($path) && isset($path['path'])) {
			$path = $path['path'];
		}

		if (!is_string($path)) {
			return null;
		}

		return $evaluator->extractVar($path);
	}
}
