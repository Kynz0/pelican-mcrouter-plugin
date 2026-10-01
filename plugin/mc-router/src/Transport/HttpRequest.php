<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
namespace Kynzo\McRouter\Transport;

final class HttpRequest
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly ?array $data,
    ) {
        if (!preg_match('/^[A-Z]+$/', $this->method) || !preg_match('#^/[A-Za-z0-9/_.\-~]*$#', $this->path)) {
            throw new \InvalidArgumentException('Refusing unsafe HTTP request line.');
        }
    }

    /** @return string|false false on JSON failure */
    public function body(): string|false
    {
        if (!is_array($this->data)) {
            return '';
        }
        try {
            return json_encode($this->data, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }

    /** @return array{0:string, 1:string} head template (with {HOST} placeholder) and body */
    public function packet(): array
    {
        $body = $this->body();
        if ($body === false) {
            throw new \RuntimeException('Refusing to serialize JSON body.');
        }
        $head = "$this->method $this->path HTTP/1.1\r\n"
            . "Host: {HOST}\r\nAccept: application/json\r\nConnection: close\r\n"
            . ($body === '' ? '' : "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n");
        return [$head, $body];
    }
}
