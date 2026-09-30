<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Domain;

use InvalidArgumentException;

/** Explicit allocation IDs, scoped to node; no assumptions about port numbers. */
final class AllocationSelector
{
    public function __construct(private array $pools) {}

    public function eligible(array $allocation, int $nodeId): bool
    {
        return (int) $allocation['node_id'] === $nodeId
            && in_array((int) $allocation['id'], array_map('intval', $this->pools[$nodeId] ?? []), true);
    }

    public function options(array $allocations, int $nodeId, ?int $serverId = null): array
    {
        $options = array_filter($allocations, fn (array $a) => $this->eligible($a, $nodeId)
            && ($a['server_id'] === null || ($serverId !== null && (int) $a['server_id'] === $serverId)));
        usort($options, fn (array $a, array $b) => [(int) $a['port'], (int) $a['id']] <=> [(int) $b['port'], (int) $b['id']]);

        return array_values($options);
    }

    /** Preview only: submission must revalidate under Pelican's allocation lifecycle. */
    public function choose(array $allocations, int $nodeId, ?int $serverId = null, ?int $currentId = null, ?int $overrideId = null): array
    {
        $options = $this->options($allocations, $nodeId, $serverId);
        $preferred = $overrideId ?? $currentId;
        foreach ($options as $allocation) {
            if ((int) $allocation['id'] === $preferred) {
                return $allocation;
            }
        }
        if ($overrideId !== null) {
            throw new InvalidArgumentException('The selected allocation is not eligible or available.');
        }
        // Keep an eligible current allocation, but automatic replacement must be free.
        // Do not silently promote another allocation already assigned to this server.
        foreach ($options as $allocation) {
            if ($allocation['server_id'] === null) {
                return $allocation;
            }
        }

        throw new InvalidArgumentException('No eligible allocation is available on this node.');
    }

    public static function backend(array $allocation): string
    {
        $ip = $allocation['ip'];
        $port = (int) $allocation['port'];
        if (!filter_var($ip, FILTER_VALIDATE_IP) || $port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Allocation has an invalid backend address.');
        }

        return (str_contains($ip, ':') ? "[$ip]" : $ip) . ":$port";
    }
}
