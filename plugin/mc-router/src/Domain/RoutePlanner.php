<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Domain;

/** No mutation. Ownership must come from durable plugin records, never suffix matching. */
final class RoutePlanner
{
    public static function apply(string $hostname, string $backend, array $observed, ?string $lastAppliedBackend): array
    {
        $existing = $observed[$hostname]['backend'] ?? null;
        if ($existing !== null && ($lastAppliedBackend === null || $existing !== $lastAppliedBackend)) {
            return ['action' => 'conflict', 'reason' => 'Existing route is not proven to be owned by this plugin.'];
        }

        return ['action' => $existing === $backend ? 'unchanged' : 'apply', 'hostname' => $hostname, 'backend' => $backend];
    }

    public static function remove(string $hostname, array $observed, ?string $lastAppliedBackend): array
    {
        $existing = $observed[$hostname]['backend'] ?? null;
        if ($existing === null) {
            return ['action' => 'unchanged'];
        }
        if ($lastAppliedBackend === null || $existing !== $lastAppliedBackend) {
            return ['action' => 'conflict', 'reason' => 'Route changed externally; refusing removal.'];
        }

        return ['action' => 'remove', 'hostname' => $hostname];
    }
}
