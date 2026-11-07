<?php
declare(strict_types=1);
namespace HomeAlone;

final class Language {
    public static function english(string $language): bool {
        return preg_match('/^en(?:-[a-z]{2})?$/iD',trim($language))===1;
    }

    public static function money(int $cents,bool $english): string {
        return $english
            ? '€'.number_format($cents/100,2,'.',',')
            : number_format($cents/100,2,',','.').' €';
    }
}
