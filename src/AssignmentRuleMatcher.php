<?php
namespace ABSmartly\SDK;

use stdClass;

class AssignmentRuleMatcher {
	public function evaluate(?string $json, ?string $environment, array $attributes): ?array {
		if ($json === null || $json === '') return null;
		$data = json_decode($json, true);
		if (!is_array($data) || !isset($data['rules']) || !is_array($data['rules'])) return null;
		foreach ($data['rules'] as $rule) {
			if (!is_array($rule)) continue;
			if (isset($rule['environments'])) {
				if (!is_array($rule['environments'])) continue;
				if ($rule['environments'] && !in_array($environment, $rule['environments'], true)) continue;
			}
			if (isset($rule['conditions'])) {
				if (!is_array($rule['conditions'])) continue;
				try { if (!(new AudienceMatcher())->evaluate((object)['filter'=>$rule['conditions']], $attributes)) continue; } catch (\Throwable $e) { continue; }
			}
			$type = $rule['type'] ?? null;
			if ($type === 'assign' && (is_int($rule['variant'] ?? null) || (is_float($rule['variant'] ?? null) && floor($rule['variant']) === $rule['variant']))) return ['variant'=>(int)$rule['variant'], 'ruleOverride'=>true];
			if ($type === 'split' && is_string($rule['percentages'] ?? null)) {
				$parts = explode('/', $rule['percentages']); $values=[]; $sum=0;
				foreach($parts as $part){ if(!is_numeric(trim($part)) || ($v=(float)trim($part))<0)return null; $values[]=$v/100;$sum+=$v; }
				if (abs(100-$sum)>0.01 || !$values) continue;
				return ['split'=>$values, 'ruleOverride'=>true];
			}
		}
		return null;
	}
}
