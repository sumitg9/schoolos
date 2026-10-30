<?php

namespace App\Tenancy;

final class SchoolContext
{
    private static ?int $schoolId = null;

    public static function set(?int $schoolId): void
    {
        self::$schoolId = $schoolId;
    }

    public static function id(): ?int
    {
        return self::$schoolId;
    }
}
