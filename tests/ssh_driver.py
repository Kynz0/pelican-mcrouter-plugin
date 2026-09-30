#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Finite SSH transport test: local unprivileged sshd, disposable keys and router."""
import getpass
import json
import os
import shutil
import socket
import subprocess
import tempfile
import time
from pathlib import Path

root = Path(__file__).resolve().parents[1]
sshd = shutil.which('sshd')
if not sshd:
    raise SystemExit('sshd unavailable; run inside nix develop.')

def port():
    with socket.socket() as s:
        s.bind(('127.0.0.1', 0))
        return s.getsockname()[1]

with tempfile.TemporaryDirectory() as d:
    temp = Path(d)
    api, ssh_port = port(), port()
    routes = temp / 'routes.json'
    routes.write_text(json.dumps({'mappings': {}}))
    for name in ['host', 'client']:
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(temp / name)], check=True)
    shutil.copyfile(temp / 'client.pub', temp / 'authorized_keys')
    (temp / 'empty_known_hosts').touch()
    config = temp / 'sshd.conf'
    config.write_text(f'''Port {ssh_port}
ListenAddress 127.0.0.1
HostKey {temp / 'host'}
PidFile {temp / 'pid'}
AuthorizedKeysFile {temp / 'authorized_keys'}
StrictModes no
UsePAM no
PasswordAuthentication no
KbdInteractiveAuthentication no
AllowUsers {getpass.getuser()}
AllowTcpForwarding local
PermitOpen 127.0.0.1:{api}
PermitTTY no
X11Forwarding no
AllowAgentForwarding no
ForceCommand /bin/false
''')
    router = subprocess.Popen([str(root / '.dev/mc-router-bin'), '-api-binding', f'127.0.0.1:{api}', '-port', str(port()), '-routes-config', str(routes)],
                              env={'PATH': os.environ['PATH'], 'HOME': d}, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    log = open(temp / 'sshd.log', 'wb')
    server = subprocess.Popen([sshd, '-D', '-e', '-f', str(config)], stdout=log, stderr=log)
    try:
        for _ in range(100):
            if server.poll() is not None:
                raise RuntimeError('Disposable sshd startup failed (no live SSH configuration was changed).')
            try:
                with socket.create_connection(('127.0.0.1', ssh_port), timeout=0.2):
                    break
            except OSError:
                time.sleep(0.05)
        scan = subprocess.run(['ssh-keyscan', '-p', str(ssh_port), '127.0.0.1'], stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, check=True)
        (temp / 'known_hosts').write_bytes(scan.stdout)
        ssh_options = ['-T', '-p', str(ssh_port), '-i', str(temp / 'client'), '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=yes', '-o', f'UserKnownHostsFile={temp / "known_hosts"}']
        # Even authenticated remote commands must not execute; -W does not open a shell.
        denied = subprocess.run(['ssh', *ssh_options, f'{getpass.getuser()}@127.0.0.1', 'true'], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=10)
        if denied.returncode == 0:
            raise RuntimeError('Test SSH account allowed remote commands.')
        # Destination restriction must also reject arbitrary forward targets.
        blocked = subprocess.run(['ssh', *ssh_options, '-W', '127.0.0.1:1', f'{getpass.getuser()}@127.0.0.1'], input=b'', stdout=subprocess.DEVNULL, stderr=subprocess.PIPE, timeout=10)
        if blocked.returncode == 0 or b'administratively prohibited' not in blocked.stderr:
            raise RuntimeError('Test SSH account allowed arbitrary destinations.')
        env = dict(os.environ, TEST_SSH_PORT=str(ssh_port), TEST_SSH_USER=getpass.getuser(), TEST_API_PORT=str(api),
                   TEST_SSH_KEY_PATH=str(temp / 'client'), TEST_SSH_KNOWN_HOSTS=str(temp / 'known_hosts'),
                   TEST_SSH_EMPTY_KNOWN_HOSTS=str(temp / 'empty_known_hosts'))
        subprocess.run(['php', str(root / 'tests/ssh.php')], env=env, check=True, cwd=root, timeout=90)
    finally:
        for process in [server, router]:
            process.terminate()
            try:
                process.wait(timeout=5)
            except subprocess.TimeoutExpired:
                process.kill()
                process.wait()
        log.close()
