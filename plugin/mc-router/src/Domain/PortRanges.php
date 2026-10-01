<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Domain;

final class PortRanges
{
    /** Well-known and commonly used service/game ports auto-generation must avoid. */
    public const COMMON = [
        [1, 1023],        // privileged/well-known
        [1337, 1337], [3000, 3010], [3306, 3306], [4444, 4444], [5000, 5010],
        [5432, 5432], [5555, 5555], [5900, 5910], [6379, 6379], [6665, 6669],
        [7777, 7790], [8000, 8010], [8080, 8081], [8443, 8443], [8767, 8768],
        [9000, 9010], [9090, 9090], [11211, 11211], [12345, 12345], [19999, 19999],
        [2302, 2305], [24454, 24454], [25565, 25575], [27015, 27050],
        [27017, 27017], [28015, 28020], [19132, 19133],
    ];

    public static function isCommon(int $port): bool
    {
        foreach (self::COMMON as [$from, $to]) {
            if ($port >= $from && $port <= $to) { return true; }
        }

        return false;
    }
}
