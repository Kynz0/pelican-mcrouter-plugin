#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
import importlib.util
import json
import tempfile
import zipfile
from pathlib import Path

root = Path(__file__).resolve().parents[1]
spec = importlib.util.spec_from_file_location('packager', root / 'scripts' / 'package.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory() as directory:
    output = Path(directory) / 'plugin.zip'
    module.build(output)
    with zipfile.ZipFile(output) as archive:
        names = archive.namelist()
        manifest = json.loads(archive.read('mc-router/plugin.json'))
        assert manifest['panels'] == ['admin']
        assert manifest['panel_version'] == '1.0.0-beta38'
        assert 'meta' not in manifest
        assert all(n.startswith('mc-router/') and '..' not in n for n in names)
        assert not any('.env' in n or '/vendor/' in n or '/.dev/' in n for n in names)
        assert 'mc-router/LICENSE' in names
print('OK: import archive structure, compatibility, admin-only scope, no runtime state')
