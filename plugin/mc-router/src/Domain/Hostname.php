<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Domain;

use InvalidArgumentException;

final class Hostname
{
    public static function suggest(string $name): string
    {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));

        return trim(substr(trim($slug, '-'), 0, 63), '-') ?: 'minecraft';
    }

    public static function make(string $slug, string $suffix, array $reserved = []): string
    {
        $slug = strtolower(trim($slug));
        $suffix = strtolower(trim($suffix));
        self::label($slug);
        foreach (explode('.', $suffix) as $label) {
            self::label($label);
        }
        if (!str_contains($suffix, '.') || strlen("$slug.$suffix") > 253) {
            throw new InvalidArgumentException('Configure a valid public hostname suffix.');
        }
        if (in_array($slug, array_map('strtolower', $reserved), true)) {
            throw new InvalidArgumentException('This hostname is reserved.');
        }

        return "$slug.$suffix";
    }

    private static function label(string $label): void
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) {
            throw new InvalidArgumentException('Use a DNS label of 1–63 letters, digits or hyphens, without leading/trailing hyphens.');
        }
    }
}
