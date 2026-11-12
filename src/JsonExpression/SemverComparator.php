<?php
declare(strict_types=1);
namespace ABSmartly\SDK\JsonExpression;

use function is_bool;
use function is_float;
use function is_int;
use function is_numeric;
use function strlen;
use function strncmp;
use function substr;

final class SemverComparator {
	public static function compare($lhs, $rhs): ?int {
		$l = self::stringify($lhs); $r = self::stringify($rhs);
		if ($l === null || $r === null) return null;
		$l = self::parse($l); $r = self::parse($r);
		if ($l === null || $r === null) return null;
		$n = max(count($l['core']), count($r['core']));
		for ($i=0; $i<$n; $i++) { $c=self::identifierCompare($l['core'][$i]??'0',$r['core'][$i]??'0'); if($c) return $c; }
		if ($l['pre']==='' && $r['pre']==='') return 0;
		if ($l['pre']==='') return 1; if ($r['pre']==='') return -1;
		$a=explode('.', $l['pre']); $b=explode('.', $r['pre']);
		for($i=0,$n=max(count($a),count($b));$i<$n;$i++){if(!array_key_exists($i,$a))return -1;if(!array_key_exists($i,$b))return 1;$c=self::identifierCompare($a[$i],$b[$i]);if($c)return $c;}
		return 0;
	}
	private static function stringify($v): ?string { if(is_bool($v))return $v?'true':'false'; if(is_int($v)||is_float($v))return is_numeric($v)?(string)$v:null; return is_string($v)?$v:null; }
	private static function parse(string $v): ?array { if($v!=='' && ($v[0]==='v'||$v[0]==='V'))$v=substr($v,1); $p=strpos($v,'+');if($p!==false)$v=substr($v,0,$p);if($v==='')return null;$p=strpos($v,'-');$core=$p===false?$v:substr($v,0,$p);if($core==='')return null;return ['core'=>explode('.',$core),'pre'=>$p===false?'':substr($v,$p+1)]; }
	private static function identifierCompare(string $a,string $b): int { $an=$a!==''&&ctype_digit($a);$bn=$b!==''&&ctype_digit($b);if($an&&$bn){$a=ltrim($a,'0')?:'0';$b=ltrim($b,'0')?:'0';return $a===$b?0:(strlen($a)>strlen($b)?1:-1);}if($an!==$bn)return $an?-1:1;return $a===$b?0:($a>$b?1:-1); }
}
