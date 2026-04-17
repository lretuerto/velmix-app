<?php

namespace App\Support;

use Illuminate\Support\Str;

class ReferenceCode
{
    public static function temporary(string $prefix): string
    {
        return strtoupper($prefix).'-TMP-'.strtoupper(str_replace('-', '', (string) Str::uuid()));
    }

    public static function fromId(string $prefix, int $id): string
    {
        return strtoupper($prefix).'-'.str_pad((string) $id, 6, '0', STR_PAD_LEFT);
    }
}
