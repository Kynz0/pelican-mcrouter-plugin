<?php

// SPDX-License-Identifier: AGPL-3.0-or-later
require __DIR__ . '/../plugin/mc-router/src/Exceptions/RouterException.php';
require __DIR__ . '/../plugin/mc-router/src/Transport/HttpResponse.php';
use Kynzo\McRouter\Transport\HttpResponse;

$checks = 0;
foreach ([
    ["HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\n{}", [200, '{}']],
    ["HTTP/1.1 201 Created\r\nContent-Length: 0\r\n\r\n", [201, '']],
    ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n1\r\n{\r\n1\r\n}\r\n0\r\n\r\n", [200, '{}']],
    ["HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n2;extension=yes\r\n{}\r\n0\r\n\r\n", [200, '{}']],
] as [$wire, $expected]) {
    if (HttpResponse::parse($wire) !== $expected) { throw new RuntimeException('Unexpected response parse result.'); }
    $checks++;
}
foreach ([
    '', 'garbage', "HTTP/1.1 200 OK\r\nContent-Length: 3\r\n\r\n{}",
    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\n4\r\n{}\r\n0\r\n\r\n",
    "HTTP/1.1 200 OK\r\nTransfer-Encoding: chunked\r\n\r\nX\r\n{}\r\n0\r\n\r\n",
    "HTTP/1.1 200 OK\r\nBad header\r\n\r\n{}",
    str_repeat('x', 2_000_001),
] as $wire) {
    try { HttpResponse::parse($wire); }
    catch (Kynzo\McRouter\Exceptions\RouterException) { $checks++; continue; }
    throw new RuntimeException('Malformed response accepted.');
}
echo "OK: $checks SSH-forwarded HTTP framing/size checks\n";
