<?php
declare(strict_types=1);
namespace HomeAlone;
final class Validate {
    public static function text(array $d,string $key,int $min=1,int $max=500): string {
        $v=$d[$key]??''; if(!is_string($v)||mb_strlen(trim($v))<$min||mb_strlen($v)>$max) throw new HttpError(422,"Campo inválido: $key."); return trim($v);
    }
    public static function integer(array $d,string $key,int $min=0,int $max=100000000): int {
        $v=$d[$key]??null; if(!is_int($v)||$v<$min||$v>$max) throw new HttpError(422,"Valor inválido: $key."); return $v;
    }
    public static function date(array $d,string $key,bool $optional=false): ?string {
        $v=$d[$key]??null; if($optional&&($v===null||$v==='')) return null;
        if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)||!($dt=\DateTimeImmutable::createFromFormat('!Y-m-d',$v))||$dt->format('Y-m-d')!==$v) throw new HttpError(422,"Data inválida: $key."); return $v;
    }
    public static function choice(array $d,string $key,array $choices): string { $v=self::text($d,$key); if(!in_array($v,$choices,true))throw new HttpError(422,"Opção inválida: $key.");return $v; }
    public static function email(array $d): string { $v=strtolower(self::text($d,'email',3,254)); if(!filter_var($v,FILTER_VALIDATE_EMAIL))throw new HttpError(422,'Email inválido.');return $v; }
    public static function strings(array $d,string $key,int $max=50): array { $v=$d[$key]??[]; if(!is_array($v)||!array_is_list($v)||count($v)>$max)throw new HttpError(422,"Lista inválida: $key.");foreach($v as $s)if(!is_string($s)||strlen($s)>2000)throw new HttpError(422,"Lista inválida: $key."); return array_values(array_unique($v)); }
}
