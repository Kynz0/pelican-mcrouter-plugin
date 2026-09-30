<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
use Kynzo\McRouter\Domain\AllocationSelector;
use Kynzo\McRouter\Domain\Hostname;
use Kynzo\McRouter\Domain\RoutePlanner;

foreach (glob(__DIR__ . '/../plugin/mc-router/src/Domain/*.php') as $file) {
    require $file;
}

$count = 0;
function same($expected, $actual): void
{
    global $count;
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
    $count++;
}
function rejects(Closure $action): void
{
    try {
        $action();
    } catch (InvalidArgumentException) {
        same(true, true);
        return;
    }
    throw new RuntimeException('Expected validation failure.');
}

same('hello-world', Hostname::suggest('Hello, World!'));
same('minecraft', Hostname::suggest('!!!'));
same(63, strlen(Hostname::suggest(str_repeat('a', 70))));
same('test.mc.example.org', Hostname::make(' TEST ', 'mc.example.org'));
foreach (['', '-bad', 'bad-', 'a.b', 'a/b', "a\n", str_repeat('a', 64), 'é'] as $bad) {
    // Whitespace around a label is intentionally normalized, internal whitespace is not.
    if ($bad === "a\n") { $bad = "a\nb"; }
    rejects(fn () => Hostname::make($bad, 'mc.example.org'));
}
rejects(fn () => Hostname::make('ADMIN', 'mc.example.org', ['admin']));
rejects(fn () => Hostname::make('a', 'localhost'));
rejects(fn () => Hostname::make('a', 'mc.example.org.evil/'));
$allocations = [
    ['id' => 1, 'node_id' => 1, 'server_id' => null, 'ip' => '192.0.2.1', 'port' => 25566],
    ['id' => 2, 'node_id' => 1, 'server_id' => 7, 'ip' => '192.0.2.1', 'port' => 25565],
    ['id' => 3, 'node_id' => 1, 'server_id' => null, 'ip' => '192.0.2.1', 'port' => 26100],
    ['id' => 4, 'node_id' => 2, 'server_id' => null, 'ip' => '192.0.2.2', 'port' => 25565],
    ['id' => 5, 'node_id' => 1, 'server_id' => null, 'ip' => '192.0.2.1', 'port' => 25567],
];
$selector = new AllocationSelector([1 => [1, 2, 5], 2 => [4]]);
same(1, $selector->choose($allocations, 1)['id']);
same(2, $selector->choose($allocations, 1, 7, 2)['id']); // Keep eligible current.
same(1, $selector->choose($allocations, 1, 7, 3)['id']); // Propose replacement; UI requires confirmation.
same(5, $selector->choose($allocations, 1, null, null, 5)['id']);
foreach ([2, 3, 4, 999] as $id) {
    rejects(fn () => $selector->choose($allocations, 1, null, null, $id));
}
rejects(fn () => $selector->choose($allocations, 3));
same('192.0.2.1:25566', AllocationSelector::backend($allocations[0]));
same('[2001:db8::1]:25565', AllocationSelector::backend(['ip' => '2001:db8::1', 'port' => 25565]));
same('[2001:db8::1]:25565', AllocationSelector::backend(['ip' => '2001:0db8:0000:0000:0000:0000:0000:0001', 'port' => 25565]));
rejects(fn () => AllocationSelector::backend(['ip' => 'example.com', 'port' => 25565]));
rejects(fn () => AllocationSelector::backend(['ip' => '192.0.2.1', 'port' => 0]));
foreach (['0.0.0.0', '::', '224.0.0.1', 'ff02::1'] as $badBackend) {
    rejects(fn () => AllocationSelector::backend(['ip' => $badBackend, 'port' => 25565]));
}
$hostname = 'test.mc.example.org';
$routes = [$hostname => ['backend' => '192.0.2.1:25565'], 'manual.example.org' => ['backend' => '192.0.2.1:25566']];
same('apply', RoutePlanner::apply($hostname, '192.0.2.1:25565', [], null)['action']);
same('conflict', RoutePlanner::apply($hostname, '192.0.2.1:25565', $routes, null)['action']);
same('unchanged', RoutePlanner::apply($hostname, '192.0.2.1:25565', $routes, '192.0.2.1:25565')['action']);
same('apply', RoutePlanner::apply($hostname, '192.0.2.1:25567', $routes, '192.0.2.1:25565')['action']);
same('conflict', RoutePlanner::remove($hostname, $routes, '192.0.2.1:25567')['action']);
same('conflict', RoutePlanner::remove($hostname, $routes, null)['action']);
same('remove', RoutePlanner::remove($hostname, $routes, '192.0.2.1:25565')['action']);
same('unchanged', RoutePlanner::remove($hostname, [], '192.0.2.1:25565')['action']);
same('192.0.2.1:25566', $routes['manual.example.org']['backend']);
echo "OK: $count domain checks\n";
