#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Finite integration test: disposable mc-router process and temporary routes file."""
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
binary = root / '.dev' / 'mc-router-bin'
if not binary.is_file():
    raise SystemExit('Build the pinned router with scripts/setup-router.sh first.')


def port():
    with socket.socket() as sock:
        sock.bind(('127.0.0.1', 0))
        return sock.getsockname()[1]


with tempfile.TemporaryDirectory() as directory:
    config = Path(directory) / 'routes.json'
    config.write_text(json.dumps({'mappings': {'manual.example.org': '192.0.2.10:25565'}}))
    api_port = port()
    base = f'http://127.0.0.1:{api_port}'

    def request(method, path, body=None):
        req = urllib.request.Request(base + path, method=method,
                                     data=None if body is None else json.dumps(body).encode(),
                                     headers={'Accept': 'application/json', 'Content-Type': 'application/json'})
        with urllib.request.urlopen(req, timeout=2) as response:
            return response.status, response.read()

    def start():
        # Do not inherit ROUTES_CONFIG, DEFAULT, discovery credentials or other live env settings.
        process = subprocess.Popen([str(binary), '-api-binding', f'127.0.0.1:{api_port}',
                                    '-port', str(port()), '-routes-config', str(config)],
                                   env={'PATH': os.environ['PATH'], 'HOME': directory},
                                   stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError('Disposable router exited during startup.')
            try:
                request('GET', '/routes')
                return process
            except OSError:
                time.sleep(0.05)
        stop(process)
        raise RuntimeError('Disposable router startup timed out.')

    def stop(process):
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()

    process = start()
    try:
        assert request('POST', '/routes', {'serverAddress': 'test.mc.example.org', 'backend': '192.0.2.10:25566'})[0] == 201
        routes = json.loads(request('GET', '/routes')[1])
        assert routes['test.mc.example.org']['backend'] == '192.0.2.10:25566'
        assert routes['manual.example.org']['backend'] == '192.0.2.10:25565'
        assert json.loads(config.read_text())['mappings']['test.mc.example.org'] == '192.0.2.10:25566'
    finally:
        stop(process)
    process = start()
    try:
        assert json.loads(request('GET', '/routes')[1])['test.mc.example.org']['backend'] == '192.0.2.10:25566'
        assert request('POST', '/routes', {'serverAddress': 'test.mc.example.org', 'backend': '192.0.2.10:25567'})[0] == 201
        assert json.loads(request('GET', '/routes')[1])['test.mc.example.org']['backend'] == '192.0.2.10:25567'
        assert request('DELETE', '/routes/test.mc.example.org')[0] == 200
        assert 'test.mc.example.org' not in json.loads(request('GET', '/routes')[1])
        assert json.loads(config.read_text())['mappings'] == {'manual.example.org': '192.0.2.10:25565'}
        # Emulate a persistence failure without permissions/root tricks.
        config.unlink()
        config.mkdir()
        assert request('POST', '/routes', {'serverAddress': 'volatile.mc.example.org', 'backend': '192.0.2.10:25568'})[0] == 201
        assert 'volatile.mc.example.org' in json.loads(request('GET', '/routes')[1])
        assert config.is_dir()  # API success + effective readback does NOT prove persistence.
    finally:
        stop(process)
print('OK: mc-router v1.47.1 API apply/read/update/delete, unrelated preservation, restart persistence, false-success on failed persistence')
