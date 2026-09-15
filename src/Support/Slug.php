<?php

declare(strict_types=1);

namespace App\Support;

final class Slug
{
    public static function make(string $text): string
    {
        $slug = strtolower(trim($text));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
        return trim($slug, '-');
    }
}
