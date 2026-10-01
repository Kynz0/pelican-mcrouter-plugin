<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Services;

use Illuminate\Support\Facades\Http;
use Kynzo\McRouter\Transport\HttpRequest;
use Kynzo\McRouter\Transport\HttpResponse;
use Kynzo\McRouter\Exceptions\RouterException as RuntimeException;
use Symfony\Component\Process\Process;

final class RouterClient
{
    public function routes(): array
    {
        [, $body] = $this->request('GET', '/routes');
        try { $routes = json_decode($body, true, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { throw new RuntimeException('Router returned invalid JSON.'); }
        if (!is_array($routes)) { throw new RuntimeException('Router returned invalid mappings.'); }
        foreach ($routes as $host => $route) {
            if (!is_string($host) || !is_array($route) || !is_string($route['backend'] ?? null)) {
                throw new RuntimeException('Router returned invalid mappings.');
            }
        }
        return $routes;
    }

    public function apply(string $hostname, string $backend): void
    {
        $this->request('POST', '/routes', ['serverAddress' => $hostname, 'backend' => $backend]);
    }

    public function remove(string $hostname): void
    {
        $this->request('DELETE', '/routes/' . rawurlencode($hostname), null, true);
    }

    /** @return array{0:string, 1:string} [host header value, base URL] */
    private function httpTarget(array $settings): array
    {
        $port = (int) $settings['http_port'];
        if ($port === 80) {
            return [$settings['http_host'], 'http://' . $settings['http_host']];
        }
        return [$settings['http_host'] . ':' . $port, 'http://' . $settings['http_host'] . ':' . $port];
    }

    /** @return array{0:string, 1:string} [host header value, base URL] */
    private function sshTarget(array $settings): array
    {
        $host = (string) $settings['ssh_host'];
        if ((int) $settings['ssh_port'] === 80) {
            return [$host, 'http://' . $host];
        }
        return [$host . ':' . (int) $settings['ssh_port'], 'http://' . $host . ':' . (int) $settings['ssh_port']];
    }

    private function request(string $method, string $path, ?array $data = null, bool $allowMissing = false): array
    {
        $settings = app(Settings::class)->get();
        $request = new HttpRequest($method, $path, $data);
        if ($settings['mode'] === 'local' && app()->environment('local', 'testing')) {
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()
                    ->send($method, 'http://127.0.0.1:' . (int) $settings['local_port'] . $path, $data === null ? [] : ['json' => $data]);
                $status = $response->status();
                $body = $response->body();
            } catch (\Throwable) { throw new RuntimeException('Could not reach the local test router.'); }
        } elseif ($settings['mode'] === 'http') {
            [$host, $base] = $this->httpTarget($settings);
            try {
                $response = Http::acceptJson()->connectTimeout(3)->timeout(8)->withoutRedirecting()->withoutVerifying()
                    ->send($method, $base . $path, $data === null ? [] : ['json' => $data]);
                $status = $response->status();
                $body = $response->body();
            } catch (\Throwable) { throw new RuntimeException('Could not reach the mc-router API. Check the host, port and -api-binding.'); }
        } elseif ($settings['mode'] === 'ssh') {
            if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9.:-]*$/D', $settings['ssh_host']) || !preg_match('/^[a-z_][a-z0-9_-]*$/D', $settings['ssh_user'])) {
                throw new RuntimeException('Invalid SSH configuration.');
            }
            [$head, $body] = $request->packet();
            [$host] = $this->sshTarget($settings);
            $request = str_replace('{HOST}', $host, $head) . "\r\n" . $body;
            // -W requests only direct-tcpip forwarding: no remote shell, command or file writes.
            $process = new Process([
                'ssh', '-F', '/dev/null', '-T', '-W', '127.0.0.1:' . (int) $settings['ssh_api_port'],
                '-p', (string) (int) $settings['ssh_port'], '-i', $settings['ssh_key_path'],
                '-o', 'BatchMode=yes', '-o', 'IdentitiesOnly=yes', '-o', 'StrictHostKeyChecking=yes',
                '-o', 'UserKnownHostsFile=' . $settings['ssh_known_hosts_path'], '-o', 'GlobalKnownHostsFile=/dev/null',
                '-o', 'ControlMaster=no', '-o', 'ControlPath=none', '-o', 'UpdateHostKeys=no',
                '-o', 'PasswordAuthentication=no', '-o', 'KbdInteractiveAuthentication=no', '-o', 'PermitLocalCommand=no',
                '-o', 'ConnectTimeout=5', '-o', 'ClearAllForwardings=yes',
                $settings['ssh_user'] . '@' . $settings['ssh_host'],
            ]);
            $process->setInput($request)->setTimeout(12);
            $received = 0;
            try {
                $process->run(function ($type, $buffer) use (&$received, $process) {
                    $received += strlen($buffer);
                    if ($received > 2_000_000) { $process->stop(0); throw new RuntimeException('Oversized SSH router response.'); }
                });
            }
            catch (\Throwable) { throw new RuntimeException('SSH router forwarding timed out or failed.'); }
            if (!$process->isSuccessful()) { throw new RuntimeException('SSH router forwarding failed; check the restricted account, key and pinned host key.'); }
            [$status, $body] = HttpResponse::parse($process->getOutput());
        } else {
            throw new RuntimeException('No permitted live router transport configured.');
        }
        if (strlen($body) > 2_000_000 || ($status < 200 || $status >= 300) && !($allowMissing && $status === 404)) {
            throw new RuntimeException('Router rejected the request or returned an oversized response.');
        }

        return [$status, $body];
    }
}
