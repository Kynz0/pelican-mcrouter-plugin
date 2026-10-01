#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Run PHP plain-HTTP transport tests against a finite disposable real mc-router."""
import json
import os
import socket
import subprocess
import tempfile
import time
import urllib.request
from pathlib import Path

root = Path(__file__).resolve().parents[1]
binary = root / '.dev/mc-router-bin'

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]

with tempfile.TemporaryDirectory() as d:
    routes = Path(d) / 'routes.json'
    routes.write_text(json.dumps({'mappings': {'manual.example.org': '192.0.2.99:25565'}}))
    api = port()
    process = subprocess.Popen([str(binary), '-api-binding', f'127.0.0.1:{api}', '-port', str(port()), '-routes-config', str(routes)],
                               env={'PATH': os.environ['PATH'], 'HOME': d}, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    try:
        for _ in range(100):
            if process.poll() is not None:
                raise RuntimeError('Test router exited during startup.')
            try:
                with urllib.request.urlopen(f'http://127.0.0.1:{api}/routes', timeout=1):
                    break
            except OSError:
                time.sleep(0.05)
        else:
            raise RuntimeError('Test router timed out.')
        env = dict(os.environ, MC_ROUTER_TEST_API_PORT=str(api))
        subprocess.run(['php', str(root / 'tests/http_transport.php')], env=env, check=True, cwd=root, timeout=90)
    finally:
        process.terminate()
        try:
            process.wait(timeout=5)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait()
