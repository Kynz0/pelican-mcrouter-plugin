<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\StepPosition;
use App\Enums\TablerIcon;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use Filament\Contracts\Plugin;
use Filament\Notifications\Notification;
use Filament\Panel;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Kynzo\McRouter\Filament\RoutingFields;
use Kynzo\McRouter\Filament\SettingsForm;
use Kynzo\McRouter\Services\Settings;

final class McRouterPlugin implements Plugin, HasPluginSettings
{
    public function getId(): string { return 'mc-router'; }

    public function register(Panel $panel): void
    {
        if ($panel->getId() !== 'admin') { return; }
        // beta38 only supports whole steps before/after the built-in ones, so the
        // step runs after Egg Configuration. Egg gating keeps it Minecraft-only.
        CreateServer::registerCustomSteps(StepPosition::After,
            \Kynzo\McRouter\Filament\RoutingStep::make('MC Router')
                ->label('MC Router')->icon(TablerIcon::Router)->completedIcon(TablerIcon::Check)
                ->visible(function (Get $get) {
                    if (!(user()?->isRootAdmin() ?? false)) { return false; }
                    $eggs = array_map('intval', app(Settings::class)->get()['eggs'] ?? []);
                    if (!$eggs) { return true; }
                    $eggId = (int) $get('egg_id');

                    return $eggId !== 0 && in_array($eggId, $eggs, true);
                })
                ->schema(fn () => RoutingFields::fields(true)));
        EditServer::registerCustomTabs(TabPosition::After,
            \Kynzo\McRouter\Filament\RoutingTab::make('mc-router')->label('MC Router')
                ->icon(TablerIcon::Router)
                ->visible(fn () => user()?->isRootAdmin() ?? false)
                ->columns(['default' => 1, 'sm' => 2])
                ->schema(fn () => RoutingFields::fields(false)));
    }

    public function boot(Panel $panel): void {}

    public function getSettingsFormData(): array
    {
        abort_unless(user()?->isRootAdmin(), 403);
        $values = app(Settings::class)->get();
        $values['pool_rows'] = collect($values['pools'])->map(fn ($ids, $nodeId) => ['node_id' => (int) $nodeId, 'allocation_ids' => $ids])->values()->all();
        unset($values['pools']);

        return $values;
    }

    public function getSettingsForm(): array
    {
        abort_unless(user()?->isRootAdmin(), 403);

        return SettingsForm::fields();
    }

    public function saveSettings(array $data): void
    {
        abort_unless(user()?->isRootAdmin(), 403);
        $data = SettingsForm::values($data);
        try { app(Settings::class)->save($data); }
        catch (\InvalidArgumentException $e) { throw ValidationException::withMessages(['suffix' => $e->getMessage()]); }
        Notification::make()->title('MC Router settings saved')->success()->send();
    }
}
