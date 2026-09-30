<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Optional browser smoke-test account; never touch the user's preview account.
$root = dirname(__DIR__);
$panel = $root . '/.dev/panel';
chdir($panel);
require $panel . '/vendor/autoload.php';
$app = require $panel . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!$app->environment('local') || realpath(base_path()) !== realpath($panel)) { throw new RuntimeException('Refusing non-disposable Panel.'); }
$email = 'ui-smoke@example.test';
$file = $root . '/.dev/ui-smoke.json';
if (in_array('--cleanup', $argv, true)) {
    App\Models\User::where('email', $email)->first()?->delete();
    if (is_file($file)) { unlink($file); }
    echo "Browser smoke-test account removed.\n";
    return;
}
Illuminate\Support\Facades\Notification::fake();
config(['panel.email.send_account_created_notification' => false]);
$password = Illuminate\Support\Str::random(48);
$user = App\Models\User::where('email', $email)->first();
if (!$user) {
    $user = app(App\Services\Users\UserCreationService::class)->handle(['email' => $email, 'username' => 'ui-smoke', 'password' => $password, 'root_admin' => true]);
} else {
    $user->password = Illuminate\Support\Facades\Hash::make($password);
    $user->save();
}
umask(0077);
file_put_contents($file, json_encode(['email' => $email, 'password' => $password], JSON_THROW_ON_ERROR));
echo "Disposable browser smoke-test account prepared (credentials not displayed).\n";
