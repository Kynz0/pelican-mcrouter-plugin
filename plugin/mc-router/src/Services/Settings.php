<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use App\Models\Allocation;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Domain\Hostname;
use Kynzo\McRouter\Models\RouteRecord;

final class Settings
{
    public function get(): array
    {
        $defaults = [
            'mode' => 'dry-run', 'suffix' => config('mc-router.suffix', 'mc.example.org'),
            'pools' => config('mc-router.pools', []), 'local_port' => 25564,
            'ssh_host' => '', 'ssh_port' => 22, 'ssh_user' => '', 'ssh_api_port' => 25564,
            'ssh_key_path' => '', 'ssh_known_hosts_path' => '',
        ];
        $saved = Schema::hasTable('mc_router_settings') ? DB::table('mc_router_settings')->where('id', 1)->value('values') : null;
        $settings = array_replace($defaults, $saved ? json_decode($saved, true, 512, JSON_THROW_ON_ERROR) : []);
        if (config('mc-router.preview_only')) {
            $settings['mode'] = 'dry-run';
        }

        return $settings;
    }

    public function validate(array $data): array
    {
        abort_unless(user()?->isRootAdmin(), 403);
        $values = array_intersect_key($data, $this->get());
        $values = array_replace($this->get(), $values);
        Hostname::make('validation', $values['suffix']);
        if (!in_array($values['mode'], ['dry-run', 'local', 'ssh'], true)
            || (config('mc-router.preview_only') && $values['mode'] !== 'dry-run')
            || ($values['mode'] === 'local' && !app()->environment('local', 'testing'))) {
            throw ValidationException::withMessages(['mode' => 'This transport is not permitted. Local HTTP is for disposable local testing only.']);
        }
        foreach ($values['pools'] as $nodeId => $ids) {
            if (!is_array($ids) || Allocation::where('node_id', $nodeId)->whereIn('id', $ids)->count() !== count(array_unique($ids))) {
                throw ValidationException::withMessages(['pool_rows' => 'Every approved allocation must belong to its configured node.']);
            }
            foreach (Allocation::withoutGlobalScopes()->whereIn('id', $ids)->get() as $allocation) {
                try { \Kynzo\McRouter\Domain\AllocationSelector::backend($allocation->toArray()); }
                catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['pool_rows' => $e->getMessage()]); }
            }
        }
        foreach (['local_port', 'ssh_port', 'ssh_api_port'] as $key) {
            if (!filter_var($values[$key], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]])) {
                throw ValidationException::withMessages([$key => 'Enter a valid TCP port.']);
            }
            $values[$key] = (int) $values[$key];
        }
        if ($values['mode'] === 'ssh') {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D', $values['ssh_host'])
                || !preg_match('/^[a-z_][a-z0-9_-]*$/D', $values['ssh_user'])) {
                throw ValidationException::withMessages(['ssh_host' => 'Configure a valid SSH host and dedicated unprivileged account.']);
            }
            foreach (['ssh_key_path', 'ssh_known_hosts_path'] as $key) {
                if (!str_starts_with($values[$key], '/') || preg_match('/[\x00-\x20\x7f%]/', $values[$key]) || !is_file($values[$key]) || !is_readable($values[$key])) {
                    throw ValidationException::withMessages([$key => 'Configure a readable absolute file path on the Panel host. Never paste private keys here.']);
                }
            }
        }
        $this->assertConnectionChange($values);
        return $values;
    }

    public function assertConnectionChange(array $values): void
    {
        $previous = $this->get();
        $keys = array_flip(['mode', 'suffix', 'local_port', 'ssh_host', 'ssh_port', 'ssh_user', 'ssh_api_port', 'ssh_key_path', 'ssh_known_hosts_path']);
        if (array_intersect_key($previous, $keys) !== array_intersect_key($values, $keys)
            && RouteRecord::get()->contains(fn ($r) => count($r->applied) > 0 || count($r->attempted ?? []) > 0)) {
            throw ValidationException::withMessages(['mode' => 'Disable and remove managed routes before changing the router connection or hostname suffix.']);
        }
    }

    public function save(array $data): void
    {
        $values = $this->validate($data);
        Cache::lock('mc-router:mutation', 180)->block(10, function () use ($values) {
            $this->assertConnectionChange($values);
            DB::table('mc_router_settings')->updateOrInsert(['id' => 1], ['values' => json_encode($values, JSON_THROW_ON_ERROR)]);
        });
    }
}
