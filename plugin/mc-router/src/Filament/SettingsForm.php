<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Filament;

use App\Models\Allocation;
use App\Models\Node;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;

final class SettingsForm
{
    public static function values(array $data): array
    {
        $pools = [];
        foreach ($data['pool_rows'] ?? [] as $row) {
            $node = (int) ($row['node_id'] ?? 0);
            if (!$node || array_key_exists($node, $pools)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['pool_rows' => 'Configure each node only once.']);
            }
            $pools[$node] = array_values(array_unique(array_map('intval', $row['allocation_ids'] ?? [])));
        }
        $data['pools'] = $pools;
        return $data;
    }

    public static function fields(): array
    {
        return [
            Text::make('DNS wildcard and TCP forwarding are configured outside this plugin. Route Active means accepted by mc-router, not that Minecraft is online or disk persistence is guaranteed.'),
            Text::make('Transport setup guides (same-host, SSH tunnel, plain HTTP) are documented step by step in the plugin README bundled with the download.'),
            \Filament\Schemas\Components\Actions::make([
                \Filament\Actions\Action::make('mc_router_test_connection')->label('Test saved router connection')
                    ->authorize(fn () => user()?->isRootAdmin() ?? false)
                    ->visible(fn () => !config('mc-router.preview_only') && app(\Kynzo\McRouter\Services\Settings::class)->get()['mode'] !== 'dry-run')
                    ->action(function () {
                        try {
                            app(\Kynzo\McRouter\Services\RouterClient::class)->routes();
                            \Filament\Notifications\Notification::make()->title('Router API readback succeeded')->success()->send();
                        } catch (\Throwable) {
                            \Filament\Notifications\Notification::make()->title('Router connection test failed; check the saved restricted transport configuration')->danger()->send();
                        }
                    }),
                \Filament\Actions\Action::make('mc_router_ssh_guide')->label('SSH setup guide')
                    ->icon(\App\Enums\TablerIcon::Key)
                    ->visible(fn (Get $get) => !config('mc-router.preview_only') && $get('mode') === 'ssh')
                    ->authorize(fn () => user()?->isRootAdmin() ?? false)
                    ->modalHeading('Restricted SSH forwarding — setup guide')
                    ->modalDescription(fn (Get $get) => new \Illuminate\Support\HtmlString('<div style="display:grid;gap:0.75rem;font-family:monospace;font-size:0.8rem;white-space:pre-wrap">'
                        . collect(app(\Kynzo\McRouter\Services\SshSetupGuide::class)->steps(false,
                                apiPort: (int) ($get('ssh_api_port') ?: 25564), sshPort: (int) ($get('ssh_port') ?: 22),
                                user: $get('ssh_user') ?: 'mcrouter-api'))['steps']
                            ->map(fn (string $step) => '<p>' . e($step) . '</p>')->implode('') . '</div>'))
                    ->modalSubmitActionLabel('Generate keypair on the Panel host')
                    ->action(function (Get $get) {
                        $guide = app(\Kynzo\McRouter\Services\SshSetupGuide::class)->steps(true,
                            apiPort: (int) ($get('ssh_api_port') ?: 25564), sshPort: (int) ($get('ssh_port') ?: 22),
                            user: $get('ssh_user') ?: 'mcrouter-api');
                        \Filament\Notifications\Notification::make()
                            ->title('Keypair ready on the Panel host')
                            ->body('Private key: ' . $guide['key_path'] . "\nCopy this authorized_keys line to the router host:\n" . $guide['authorized_keys'])
                            ->success()->persistent()->send();
                    }),
            ]),
            Select::make('mode')->label('Router transport')->live()->required()
                ->options(config('mc-router.preview_only') ? ['dry-run' => 'Dry-run (no server or router changes)'] : [
                    'dry-run' => 'Dry-run (no server or router changes)',
                    'ssh' => 'Restricted SSH forwarding to mc-router loopback API',
                    'http' => 'Plain HTTP API URL (no authentication)',
                    'local' => 'Disposable local test router (loopback HTTP)',
                ]),
            TextInput::make('suffix')->label('Hostname suffix')->placeholder('mc.example.org')->required()
                ->rule(fn (Get $get) => function (string $attribute, mixed $value, \Closure $fail) use ($get) {
                    // Beta38's Plugin model swallows saveSettings exceptions: validate before its callback.
                    try { app(\Kynzo\McRouter\Services\Settings::class)->validate(self::values((array) $get())); }
                    catch (\Illuminate\Validation\ValidationException $e) { $fail(collect($e->errors())->flatten()->first()); }
                    catch (\InvalidArgumentException $e) { $fail($e->getMessage()); }
                }),
            Repeater::make('pool_rows')->label('Approved primary allocation pools')->schema([
                Select::make('node_id')->label('Wings node')->options(fn () => Node::orderBy('name')->pluck('name', 'id'))
                    ->required()->live()->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                Select::make('allocation_ids')->label('Approved allocations')->multiple()->required()->searchable()
                    ->options(fn (Get $get) => Allocation::where('node_id', $get('node_id'))->orderBy('port')->get()
                        ->mapWithKeys(fn ($a) => [$a->id => $a->ip . ':' . $a->port
                            . ($a->server_id ? " — '" . (\App\Models\Server::find($a->server_id)?->name ?? 'server #' . $a->server_id) . "'" : '')])),
                \Filament\Schemas\Components\Actions::make([
                    \Filament\Actions\Action::make('mc_router_pool_add_range')->label('Add existing ports in range')->color('gray')
                        ->icon(\App\Enums\TablerIcon::ArrowsJoin2)
                        ->schema([
                            TextInput::make('start')->label('Start port')->numeric()->minValue(1024)->maxValue(65535)->required(),
                            TextInput::make('end')->label('End port (max 1000 apart)')->numeric()->minValue(1024)->maxValue(65535)->required(),
                        ])
                        ->action(function (array $data, Get $get, Set $set) {
                            $start = (int) $data['start']; $end = (int) $data['end'];
                            if ($start > $end || $end - $start > 999) {
                                \Filament\Notifications\Notification::make()->title('Invalid range — ports must be 1024–65535, at most 1000 apart')->danger()->send();
                                return;
                            }
                            $found = app(\Kynzo\McRouter\Services\AllocationPool::class)->inRange((int) $get('node_id'), $start, $end);
                            $current = collect($get('allocation_ids') ?? [])->map(fn ($v) => (int) $v);
                            $added = collect($found['ids'])->reject(fn ($id) => $current->contains($id))->values();
                            if ($added->isEmpty()) {
                                \Filament\Notifications\Notification::make()->title('No new allocations in that range')->body('Nothing on this node exists between those ports, or everything found is already in the pool.')->warning()->send();
                                return;
                            }
                            $set('allocation_ids', $current->merge($added)->unique()->values()->all());
                            \Filament\Notifications\Notification::make()->title($added->count() . ' allocation(s) added to the pool')
                                ->body(($found['skipped'] || count($found['ids']) > $added->count() ? 'Skipped ' . (count($found['ids']) - $added->count()) . ' already in the pool. ' : '') . 'Remember to save the settings.')->success()->send();
                        }),
                    \Filament\Actions\Action::make('mc_router_pool_create_range')->label('Create new allocations')->color('gray')
                        ->icon(\App\Enums\TablerIcon::Plus)
                        ->schema([
                            TextInput::make('allocation_ip')->label('Allocation IP on this node')->required()
                                ->helperText('Must be an IP address already available on this node (the same one your other allocations use).'),
                            TextInput::make('start')->label('Start port')->numeric()->minValue(1024)->maxValue(65535)->required(),
                            TextInput::make('count')->label('How many ports')->numeric()->minValue(1)->maxValue(1000)->required(),
                        ])
                        ->action(function (array $data, Get $get, Set $set) {
                            if (!filter_var($data['allocation_ip'], FILTER_VALIDATE_IP)) {
                                \Filament\Notifications\Notification::make()->title('Enter a valid IP address present on this node')->danger()->send();
                                return;
                            }
                            $start = (int) $data['start'];
                            if ($start + (int) $data['count'] - 1 > 65535) {
                                \Filament\Notifications\Notification::make()->title('Range exceeds port 65535')->danger()->send();
                                return;
                            }
                            $result = app(\Kynzo\McRouter\Services\AllocationPool::class)->createRange((int) $get('node_id'), $data['allocation_ip'], $start, (int) $data['count']);
                            if (!$result['created']) {
                                \Filament\Notifications\Notification::make()->title('Nothing created')
                                    ->body('All ' . $result['skipped'] . ' port(s) already exist on this node and were skipped.')->warning()->send();
                                return;
                            }
                            $current = collect($get('allocation_ids') ?? [])->map(fn ($v) => (int) $v);
                            $set('allocation_ids', $current->merge($result['created'])->unique()->values()->all());
                            \Filament\Notifications\Notification::make()->title(count($result['created']) . ' allocation(s) created and added to the pool')
                                ->body(($result['skipped'] ? $result['skipped'] . ' already-existing port(s) were skipped. ' : '') . 'Remember to save the settings.')->success()->send();
                        }),
                    \Filament\Actions\Action::make('mc_router_pool_auto_range')->label('Auto-generate an uncommon range')->color('gray')
                        ->icon(\App\Enums\TablerIcon::Wand)
                        ->schema([
                            TextInput::make('allocation_ip')->label('Allocation IP on this node')->required()
                                ->helperText('Must be an IP address already available on this node (the same one your other allocations use).'),
                        ])
                        ->action(function (array $data, Get $get, Set $set) {
                            if (!filter_var($data['allocation_ip'], FILTER_VALIDATE_IP)) {
                                \Filament\Notifications\Notification::make()->title('Enter a valid IP address present on this node')->danger()->send();
                                return;
                            }
                            $pool = app(\Kynzo\McRouter\Services\AllocationPool::class);
                            $start = $pool->findUncommonStart((int) $get('node_id'), 10);
                            if ($start === null) {
                                \Filament\Notifications\Notification::make()->title('Could not find a free uncommon range of 10 ports')->warning()->send();
                                return;
                            }
                            $result = $pool->createRange((int) $get('node_id'), $data['allocation_ip'], $start, 10);
                            if (!$result['created']) {
                                \Filament\Notifications\Notification::make()->title('Nothing created')
                                    ->body('All port(s) already exist on this node and were skipped.')->warning()->send();
                                return;
                            }
                            $current = collect($get('allocation_ids') ?? [])->map(fn ($v) => (int) $v);
                            $set('allocation_ids', $current->merge($result['created'])->unique()->values()->all());
                            \Filament\Notifications\Notification::make()->title('Created uncommon ports ' . $start . '–' . ($start + count($result['created']) - 1))
                                ->body('Common service/game ports were avoided. Remember to save the settings.')->success()->send();
                        }),
                ])->columnSpanFull(),
            ])->helperText('Explicit allocations, including occupied ones that may become available later. Extra mod/voice ports should not be included.'),
            Select::make('eggs')->label('Eggs with MC Router')->multiple()->searchable()
                ->options(fn () => \App\Models\Egg::orderBy('name')->pluck('name', 'id'))
                ->hintAction(\Filament\Actions\Action::make('mc_router_add_minecraft_tagged')->label("Add all eggs tagged 'minecraft'")
                    ->icon(\App\Enums\TablerIcon::Tag)->color('gray')
                    ->action(function (\Filament\Schemas\Components\Utilities\Set $set, \Filament\Schemas\Components\Utilities\Get $get) {
                        $tagged = \App\Models\Egg::all()
                            ->filter(fn (\App\Models\Egg $egg) => collect($egg->tags ?? [])
                                ->contains(fn ($tag) => strcasecmp((string) $tag, 'minecraft') === 0))
                            ->pluck('id');
                        $merged = collect($get('eggs') ?? [])->map(fn ($v) => (int) $v)
                            ->merge($tagged)->unique()->values()->all();
                        $set('eggs', $merged);
                        \Filament\Notifications\Notification::make()
                            ->title($tagged->count() . ' egg(s) tagged minecraft added to the list')
                            ->body('Review the list and save to apply the "' . \Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE . '" feature.')
                            ->success()->send();
                    }))
                ->helperText('Saving automatically adds or removes the "' . \Kynzo\McRouter\Filament\RoutingFields::EGG_FEATURE . '" egg feature to match this list — eggs with that feature get the creation step and the edit tab (you can also set the feature manually in the egg editor). With no eggs in the list, MC Router is not offered anywhere.'),
            TextInput::make('local_port')->label('Local test API port')->numeric()->minValue(1)->maxValue(65535)
                ->visible(fn (Get $get) => $get('mode') === 'local'),
            TextInput::make('http_host')->label('mc-router host (no scheme, no path)')
                ->visible(fn (Get $get) => $get('mode') === 'http')
                ->placeholder('router.example.org'),
            TextInput::make('http_port')->label('API port')->numeric()->minValue(1)->maxValue(65535)
                ->visible(fn (Get $get) => $get('mode') === 'http'),
            \Filament\Forms\Components\Toggle::make('http_tls')->label('Use HTTPS (any certificate accepted)')
                ->visible(fn (Get $get) => $get('mode') === 'http')
                ->helperText('Accepts self-signed certificates. The mc-router API has no authentication: anyone who can reach this port can rewrite every route. Restrict reachability with a firewall or VPN.'),
            TextInput::make('ssh_host')->label('SSH host')->visible(fn (Get $get) => $get('mode') === 'ssh')
                ->hint('Run `php artisan mc-router:ssh-setup` on the Panel host for a generated step-by-step guide.'),
            TextInput::make('ssh_port')->label('SSH port')->numeric()->visible(fn (Get $get) => $get('mode') === 'ssh'),
            TextInput::make('ssh_user')->label('Dedicated SSH account')->visible(fn (Get $get) => $get('mode') === 'ssh'),
            TextInput::make('ssh_api_port')->label('mc-router loopback API port')->numeric()->visible(fn (Get $get) => $get('mode') === 'ssh'),
            TextInput::make('ssh_key_path')->label('Panel-local private-key file path')->visible(fn (Get $get) => $get('mode') === 'ssh')
                ->helperText('Path only. Use a dedicated restricted key; never paste or reuse a root key.'),
            TextInput::make('ssh_known_hosts_path')->label('Panel-local pinned known_hosts file path')->visible(fn (Get $get) => $get('mode') === 'ssh'),
        ];
    }
}
