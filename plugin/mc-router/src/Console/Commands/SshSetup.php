<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Console\Commands;

use Illuminate\Console\Command;
use Kynzo\McRouter\Services\SshSetupGuide;

final class SshSetup extends Command
{
    protected $signature = 'mc-router:ssh-setup
        {--api-port=25564 : mc-router loopback API port on the router host}
        {--ssh-port=22 : SSH port of the router host}
        {--user=mcrouter-api : dedicated unprivileged SSH account name}
        {--generate : generate a dedicated keypair on the Panel host}';

    protected $description = 'Print step-by-step instructions for the restricted SSH forwarding transport';

    public function handle(): int
    {
        $api = (int) $this->option('api-port');
        $ssh = (int) $this->option('ssh-port');
        $user = (string) $this->option('user');
        if ($api < 1 || $api > 65535 || $ssh < 1 || $ssh > 65535 || !preg_match('/^[a-z_][a-z0-9_-]*$/D', $user)) {
            $this->error('Invalid options.');
            return self::FAILURE;
        }
        $guide = app(SshSetupGuide::class)->steps((bool) $this->option('generate'), $api, $ssh, $user);
        $this->line('MC Router — restricted SSH forwarding setup');
        $this->line('Goal: the Panel reaches only the mc-router API on 127.0.0.1:' . $api . ' via a gutted SSH account.');
        $this->line('');
        foreach ($guide['steps'] as $step) {
            $this->line(preg_replace('/^(\d+\.) /m', PHP_EOL . '$1 ', $step));
            $this->line('');
        }
        $this->line('Full documentation: README.md bundled with this plugin (Transport B).');

        return self::SUCCESS;
    }
}
