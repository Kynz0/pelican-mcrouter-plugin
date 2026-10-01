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
            \Filament\Schemas\Components\Actions::make([
                \Filament\Actions\Action::make('mc_router_add_minecraft_tagged')->label("Add all eggs tagged 'minecraft'")
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
                    }),
            ])->key('mc-router-settings-actions')->columnSpanFull(),
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
            ]),
            Text::make('DNS wildcard and TCP forwarding are configured outside this plugin. Route Active means accepted by mc-router, not that Minecraft is online or disk persistence is guaranteed.'),
            Text::make('Transport setup guides (same-host, SSH tunnel, plain HTTP) are documented step by step in the plugin README bundled with the download.'),
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
                        ->mapWithKeys(fn ($a) => [$a->id => $a->ip . ':' . $a->port . ($a->server_id ? ' (assigned)' : '')])),
            ])->helperText('Explicit allocations, including occupied ones that may become available later. Extra mod/voice ports should not be included.'),
            Select::make('eggs')->label('Eggs with MC Router')->multiple()->searchable()
                ->options(fn () => \App\Models\Egg::orderBy('name')->pluck('name', 'id'))
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
