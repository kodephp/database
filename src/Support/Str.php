<?php

declare(strict_types=1);

namespace Kode\Database\Support;

/**
 * 字符串工具（包内自持，无外部依赖）
 *
 * 关联名/表名的驼峰与蛇形互转，用于 with()/withCount() 的关联名归一。
 */
final class Str
{
    private function __construct()
    {
    }

    /**
     * 蛇形/连字符 → 驼峰（user_profile、user-profile → userProfile；原样保持 userProfile）
     */
    public static function camel(string $value): string
    {
        $value = str_replace(['-', '_'], ' ', $value);
        $value = ucwords($value);

        return lcfirst(str_replace(' ', '', $value));
    }

    /**
     * 驼峰 → 蛇形（userProfile → user_profile；user_profile、user-profile → user_profile）
     */
    public static function snake(string $value): string
    {
        $value = str_replace(['-', ' '], '_', $value);
        $value = preg_replace('/(?<=[a-z0-9])([A-Z])/', '_$1', $value);
        $value = preg_replace('/_+/', '_', $value);

        return strtolower(trim((string) $value, '_'));
    }
}
