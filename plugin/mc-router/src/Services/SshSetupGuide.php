<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

/** Shared step list for the restricted SSH transport, used by the artisan command and the settings UI. */
final class SshSetupGuide
{
    /** @return array{key_path: string, known_hosts_path: string, steps: list<string>, authorized_keys: string} */
    public function steps(bool $generate = false, int $apiPort = 25564, int $sshPort = 22, string $user = 'mcrouter-api'): array
    {
        $storage = storage_path('mc-router');
        $key = $storage . '/mcrouter_api_key';
        $known = $storage . '/known_hosts';
        $authorized = "restrict,port-forwarding,permitopen=\"127.0.0.1:$apiPort\" <public key>";
        if ($generate) {
            if (!is_dir($storage)) { mkdir($storage, 0770, true); }
            if (!is_file($key)) {
                $keygen = new \Symfony\Component\Process\Process(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-C', 'mc-router-panel', '-f', $key]);
                $keygen->mustRun();
            }
            $pub = trim((string) file_get_contents($key . '.pub'));
            $authorized = "restrict,port-forwarding,permitopen=\"127.0.0.1:$apiPort\" $pub";
        }

        $steps = [];
        $steps[] = $generate
            ? '1. Keypair ready on the Panel host: ' . $key . ' (private key — never share or reuse it).'
            : "1. Generate a dedicated keypair on the Panel host:\n    ssh-keygen -t ed25519 -f $key -N '' -C mc-router-panel\n   Or save this settings slide-over with the generator enabled to do it for you.";
        $steps[] = "2. On the ROUTER host (root): create the unprivileged account and authorize the key:\n"
            . "    useradd -m -s /usr/sbin/nologin $user\n"
            . "    sudo -u $user mkdir -p ~$user/.ssh && sudo -u $user chmod 700 ~$user/.ssh\n"
            . "    echo '" . $authorized . "' >> ~$user/.ssh/authorized_keys\n"
            . "    sudo -u $user chmod 600 ~$user/.ssh/authorized_keys";
        $steps[] = "3. On the ROUTER host: let this account only forward TCP to the API:\n"
            . "    # /etc/ssh/sshd_config\n"
            . "    Match User $user\n"
            . "        AllowTcpForwarding local\n"
            . "        PermitListen none\n"
            . "    # then: systemctl restart ssh";
        $steps[] = "4. On the ROUTER host: give the mc-router API a loopback binding and restart mc-router:\n"
            . "    # unit/compose: API_BINDING=127.0.0.1:$apiPort (or -api-binding 127.0.0.1:$apiPort)";
        $steps[] = '5. On the ROUTER host: ensure the mc-router routes file is writable by the service user (root-owned routes silently fail persistence).';
        $steps[] = "6. On the Panel host: pin the router host key:\n"
            . "    mkdir -p $storage && ssh-keyscan -p $sshPort <router-host> > $known";
        $steps[] = "7. Fill this settings slide-over:\n"
            . "    host: <router-host>   SSH port: $sshPort   account: $user   API port: $apiPort\n"
            . "    private key path: $key\n"
            . "    known hosts path: $known\n"
            . '    then use "Test saved router connection".';

        return ['key_path' => $key, 'known_hosts_path' => $known, 'steps' => $steps, 'authorized_keys' => $authorized];
    }
}
