<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
// Disposable development router. Read the preview origin on every request so a
// quick-tunnel URL can change without losing Paseo's assigned service port.
$root = dirname(__DIR__);
$previewFile = $root . '/.dev/public-url';
if (is_file($previewFile)) {
    $origin = trim(file_get_contents($previewFile));
    if (preg_match('#^https://[a-z0-9-]+\.trycloudflare\.com$#D', $origin)) {
        putenv('APP_URL=' . $origin);
        $_ENV['APP_URL'] = $_SERVER['APP_URL'] = $origin;
    }
}
chdir($root . '/.dev/panel/public');
return require $root . '/.dev/panel/vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php';
