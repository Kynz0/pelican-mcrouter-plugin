<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter;

use App\Contracts\Plugins\HasPluginSettings;
use App\Enums\StepPosition;
use App\Enums\TabPosition;
use App\Filament\Admin\Resources\Servers\Pages\CreateServer;
use App\Filament\Admin\Resources\Servers\Pages\EditServer;
use Filament\Contracts\Plugin;
use Filament\Notifications\Notification;
use Filament\Panel;
use Kynzo\McRouter\Filament\RoutingTab as Tab;
use Kynzo\McRouter\Filament\RoutingStep as Step;
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
        CreateServer::registerCustomSteps(StepPosition::Before,
            Step::make('MC Router')->visible(fn () => user()?->isRootAdmin() ?? false)->schema(fn () => RoutingFields::fields(true)));
        EditServer::registerCustomTabs(TabPosition::After,
            Tab::make('mc-router')->label('MC Router')->visible(fn () => user()?->isRootAdmin() ?? false)->schema(fn () => RoutingFields::fields(false)));
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
