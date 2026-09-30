#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Build an importable prototype ZIP. Never package Panel, .env or runtime meta."""
import json
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def build(output: Path) -> None:
    plugin = ROOT / 'plugin' / 'mc-router'
    manifest = json.loads((plugin / 'plugin.json').read_text())
    assert 'meta' not in manifest
    output.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        def add(path: Path, name: str) -> None:
            # Fixed ZIP metadata also supports Nix's epoch-dated source files.
            info = zipfile.ZipInfo(name, date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, path.read_bytes())

        for path in sorted(plugin.rglob('*')):
            if path.is_file():
                add(path, (Path('mc-router') / path.relative_to(plugin)).as_posix())
        add(ROOT / 'LICENSE', 'mc-router/LICENSE')
        add(ROOT / 'docs' / 'prototype.md', 'mc-router/README.md')


if __name__ == '__main__':
    version = json.loads((ROOT / 'plugin/mc-router/plugin.json').read_text())['version']
    output = ROOT / 'dist' / f'mc-router-{version}.zip'
    build(output)
    print(output)
