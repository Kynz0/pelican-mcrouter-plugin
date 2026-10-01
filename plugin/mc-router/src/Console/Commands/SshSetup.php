<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Console\Commands;

use Illuminate\Console\Command;

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
        $storage = storage_path('mc-router');
        $key = $storage . '/mcrouter_api_key';
        $known = $storage . '/known_hosts';
        $this->line('MC Router — restricted SSH forwarding setup');
        $this->line('Goal: the Panel reaches only the mc-router API on 127.0.0.1:' . $api . ' via a gutted SSH account.');
        $this->line('');
        if ((bool) $this->option('generate')) {
            if (!is_dir($storage)) { mkdir($storage, 0770, true); }
            if (!is_file($key)) {
                $keygen = new \Symfony\Component\Process\Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'mc-router-panel', '-f', $key]);
                $keygen->mustRun();
            }
            $pub = trim((string) file_get_contents($key . '.pub'));
            $this->info("Keypair ready: $key (never share the private key; the .pub line below is the only thing the router host needs)");
            $this->line('authorized_keys entry (copy to the router host):');
            $this->line("restrict,port-forwarding,permitopen=\"127.0.0.1:$api\" $pub");
        } else {
            $this->line("1. Generate a dedicated keypair on this Panel host (run this command with --generate, or):");
            $this->line("   ssh-keygen -t ed25519 -f $key -N '' -C mc-router-panel");
        }
        $this->line('');
        $this->line("2. On the ROUTER host (root): create the unprivileged account and authorize the key:");
        $this->line("   useradd -m -s /usr/sbin/nologin $user");
        $this->line("   sudo -u $user mkdir -p ~$user/.ssh && sudo -u $user chmod 700 ~$user/.ssh");
        $this->line("   echo 'restrict,port-forwarding,permitopen=\"127.0.0.1:$api\" <public key from step 1>' >> ~$user/.ssh/authorized_keys");
        $this->line("   sudo -u $user chmod 600 ~$user/.ssh/authorized_keys");
        $this->line('');
        $this->line("3. On the ROUTER host: make sure sshd allows local forwarding for this account (usually default):");
        $this->line("   # /etc/ssh/sshd_config");
        $this->line("   Match User $user");
        $this->line("       AllowTcpForwarding local");
        $this->line("       PermitListen none");
        $this->line("   # then: systemctl restart ssh");
        $this->line('');
        $this->line("4. On the ROUTER host: give the mc-router API a loopback binding and restart mc-router:");
        $this->line("   # add to the mc-router unit/compose: API_BINDING=127.0.0.1:$api  (or -api-binding 127.0.0.1:$api)");
        $this->line('');
        $this->line("5. On the ROUTER host: ensure the routes file is writable by the mc-router service user (root-owned routes silently fail persistence).");
        $this->line('');
        $this->line("6. On this Panel host: pin the router host key:");
        $this->line("   mkdir -p $storage && ssh-keyscan -p $ssh <router-host> > $known");
        $this->line('');
        $this->line('7. In Admin → Plugins → MC Router → Settings pick "Restricted SSH forwarding" and enter:');
        $this->line("   host: <router-host>   port: $ssh   account: $user   api port: $api");
        $this->line("   private key path: $key   known hosts path: $known");
        $this->line('   then use "Test saved router connection".');
        $this->line('');
        $this->line('Full documentation: README.md bundled with this plugin (Transport B).');

        return self::SUCCESS;
    }
}
