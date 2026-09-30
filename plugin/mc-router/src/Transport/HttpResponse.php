<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Transport;

use Kynzo\McRouter\Exceptions\RouterException as RuntimeException;

final class HttpResponse
{
    public static function parse(string $wire): array
    {
        if (strlen($wire) > 2_000_000 || !str_contains($wire, "\r\n\r\n")) {
            throw new RuntimeException('Invalid or oversized router response.');
        }
        [$head, $body] = explode("\r\n\r\n", $wire, 2);
        $lines = explode("\r\n", $head);
        if (!preg_match('#^HTTP/1\.[01] ([0-9]{3})(?: |$)#', array_shift($lines), $match)) {
            throw new RuntimeException('Invalid router response status.');
        }
        $headers = [];
        foreach ($lines as $line) {
            if (!str_contains($line, ':')) { throw new RuntimeException('Invalid router response headers.'); }
            [$name, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($name))] = trim($value);
        }
        if (strtolower($headers['transfer-encoding'] ?? '') === 'chunked') {
            $decoded = '';
            while (true) {
                $end = strpos($body, "\r\n");
                if ($end === false || !preg_match('/^[0-9a-f]+(?:;[^\r\n]*)?$/iD', substr($body, 0, $end))) {
                    throw new RuntimeException('Invalid chunked router response.');
                }
                $length = hexdec(explode(';', substr($body, 0, $end))[0]);
                $body = substr($body, $end + 2);
                if ($length === 0) { $body = $decoded; break; }
                if ($length > strlen($body) - 2 || substr($body, $length, 2) !== "\r\n") {
                    throw new RuntimeException('Truncated router response.');
                }
                $decoded .= substr($body, 0, $length);
                $body = substr($body, $length + 2);
            }
        } elseif (isset($headers['content-length']) && (!ctype_digit($headers['content-length']) || strlen($body) !== (int) $headers['content-length'])) {
            throw new RuntimeException('Truncated router response.');
        }

        return [(int) $match[1], $body];
    }
}
