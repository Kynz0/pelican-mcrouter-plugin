#!/usr/bin/env python3
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Cut a Forgejo release for the plugin and keep update.json in sync.

This is the whole Pelican plugin-update workflow, driven by one Paseo press:

  plugin/mc-router/plugin.json  update_url -> .../raw/branch/main/update.json
  update.json (repo root)       {"mc-router": {"*" | "<panel version>":
                                  {"version", "download_url"}}}
  Forgejo release               tag v<version> + ZIP asset, whose URL is
                                .../releases/download/v<version>/<zip>

Installed Panels poll update_url (10-minute cache) and, when the advertised
version compares greater than the installed one, offer an in-panel update
that downloads the ZIP and runs the standard import path (id check, atomic
swap, migrations, composer packages).

Steps (release.py):
  1. precheck: clean tree, expected commit identity, version format,
     unused tag, remote reachable
  2. build the importable ZIP via scripts/package.py
  3. regenerate update.json for the new version; commit it on the current
     branch if it changed
  4. push the branch, then the tag v<version>
  5. create the Forgejo release with `tea` and attach the ZIP
  6. push the update.json change as a single-file commit to main
     (opt out with --no-publish-main; --publish-main-only does just this
     step, e.g. after editing update.json by hand)

Runs a dry run (no writes, no pushes, no releases) with --dry. Versions
containing a hyphen (e.g. 0.4.1-dev) are marked --prerelease on Forgejo;
override with --stable. This script never touches the live Panel or router.
"""
from __future__ import annotations

import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPO_SLUG = 'kynzo/pelican-mcrouter-plugin'
FORGEJO_URL = f'https://git.kynzo.org/{REPO_SLUG}'
EXPECTED_IDENTITY = 'kynzo <kynzo@users.noreply.git.kynzo.org>'
MANIFEST = ROOT / 'plugin' / 'mc-router' / 'plugin.json'
UPDATE_JSON = ROOT / 'update.json'


def git(*args: str, check: bool = True) -> str:
    result = subprocess.run(['git', '-C', str(ROOT), *args],
                            capture_output=True, text=True)
    if check and result.returncode != 0:
        raise RuntimeError(f'git {" ".join(args)} failed: {result.stderr.strip()}')
    return result.stdout.strip()


def run(cmd: list[str], dry: bool, capture: bool = False) -> str:
    if dry:
        print(f'  [dry] would run: {" ".join(cmd)}')
        return ''
    print(f'  $ {" ".join(cmd)}')
    if capture:
        result = subprocess.run(cmd, capture_output=True, text=True)
        if result.returncode != 0:
            raise RuntimeError(f'command failed: {result.stderr.strip() or result.stdout.strip()}')
        return result.stdout
    subprocess.run(cmd, check=True)
    return ''


def main() -> int:
    dry = '--dry' in sys.argv
    publish_main = '--no-publish-main' not in sys.argv or '--publish-main-only' in sys.argv
    if '--publish-main-only' in sys.argv:
        dry = False

    manifest = json.loads(MANIFEST.read_text())
    version = manifest['version']
    plugin_id = manifest['id']
    if not re.fullmatch(r'\d+\.\d+\.\d+(-[\w.]+)?', version):
        print(f'FAIL: unexpected version format in plugin.json: {version!r}')
        return 1
    tag = f'v{version}'
    prerelease = '-' in version and '--stable' not in sys.argv
    zip_name = f'mc-router-{version}.zip'
    zip_path = ROOT / 'dist' / zip_name
    asset_url = f'{FORGEJO_URL}/releases/download/{tag}/{zip_name}'

    print(f'Plugin {plugin_id} version {version}'
          f' ({"prerelease" if prerelease else "stable release"})')

    if '--publish-main-only' in sys.argv:
        publish_update_json_to_main(dry)
        print('OK: update.json published to main.')
        return 0

    # --- 1. prechecks -----------------------------------------------------
    if dirty := git('status', '--porcelain'):
        if dry:
            print('WARN: worktree is dirty (ignored in dry run):\n' + dirty)
        else:
            print('FAIL: worktree is dirty; commit or stash first:\n' + dirty)
            return 1
    ident = git('var', 'GIT_AUTHOR_IDENT').split('>', 1)[0] + '>'
    committer_ident = git('var', 'GIT_COMMITTER_IDENT').split('>', 1)[0] + '>'
    for what, value in (('author', ident), ('committer', committer_ident)):
        if value != EXPECTED_IDENTITY:
            print(f'FAIL: git {what} identity is {value}, expected {EXPECTED_IDENTITY}')
            return 1
    if git('tag', '--list', tag):
        print(f'FAIL: tag {tag} already exists.')
        return 1
    branch = git('branch', '--show-current')
    git('ls-remote', '--heads', 'origin', branch)  # remote reachable
    print('Prechecks passed.')

    # --- 2. build the ZIP -------------------------------------------------
    print('Building importable ZIP…')
    run([sys.executable, str(ROOT / 'scripts' / 'package.py')], dry=False)
    if not zip_path.is_file():
        print(f'FAIL: package.py did not produce {zip_path}')
        return 1

    # --- 3. regenerate update.json ---------------------------------------
    update = json.loads(UPDATE_JSON.read_text()) if UPDATE_JSON.is_file() else {}
    plugin_entry = {k: v for k, v in update.get(plugin_id, {}).items() if k != '*'}
    plugin_entry['*'] = {'version': version, 'download_url': asset_url}
    update[plugin_id] = plugin_entry
    new_text = json.dumps(update, indent=4) + '\n'
    if UPDATE_JSON.is_file() and UPDATE_JSON.read_text() == new_text:
        print('update.json already up to date.')
    elif dry:
        print(f'[dry] would write {UPDATE_JSON.name}:')
        print(new_text)
    else:
        UPDATE_JSON.write_text(new_text)
        run(['git', '-C', str(ROOT), 'add', 'update.json'], dry=False)
        run(['git', '-C', str(ROOT), 'commit', '-m', f'release: {tag} (update.json)'],
            dry=False)
    if dry:
        print('Dry run complete; nothing written, pushed or published.')
        return 0

    # --- 4. tag + push ----------------------------------------------------
    head_sha = git('rev-parse', 'HEAD')
    run(['git', '-C', str(ROOT), 'tag', '-a', tag, '-m', f'MC Router {version}'], dry=False)
    run(['git', '-C', str(ROOT), 'push', 'origin', 'HEAD'], dry=False)
    run(['git', '-C', str(ROOT), 'push', 'origin', tag], dry=False)

    # --- 5. Forgejo release ----------------------------------------------
    notes = build_notes(tag, version)
    notes_path = ROOT / 'dist' / f'release-notes-{tag}.md'
    notes_path.write_text(notes)
    cmd = ['tea', 'release', 'create', '--repo', REPO_SLUG, '--tag', tag,
           '--target', head_sha, '--title', f'MC Router {version}',
           '--note-file', str(notes_path), '--asset', str(zip_path)]
    if prerelease:
        cmd.append('--prerelease')
    run(cmd, dry=False)

    # --- 6. publish update.json to main -----------------------------------
    if publish_main:
        publish_update_json_to_main(dry=False)
    else:
        print('Skipped publishing update.json to main (--no-publish-main).')

    print(f'DONE: release {tag} created at {FORGEJO_URL}/releases/tag/{tag}')
    print(f'      ZIP: {asset_url}')
    print(f'      Update feed: {FORGEJO_URL}/raw/branch/main/update.json')
    if not publish_main:
        print('      Next: push the update.json commit on main (rerun with '
              '--publish-main-only or merge this branch).')
    return 0


def build_notes(tag: str, version: str) -> str:
    prev = git('describe', '--tags', '--abbrev=0', '--exclude', tag, check=False)
    commits = git('log', '--pretty=format:- %s (%h)', *(f'{prev}..' if prev else []), 'HEAD')
    body = '\n'.join(commits.splitlines()[:20])
    return (f'## MC Router {tag}\n\n'
            f'Plugin ZIP: `mc-router-{version}.zip` (import via the Panel plugin page or the in-panel update flow).\n\n'
            f'### Changes since {prev or "last tag"}\n\n{body or "- (no commit history found)"}\n')


def publish_update_json_to_main(dry: bool) -> None:
    """Push the current update.json to main as a single-file commit.

    Uses a temporary detached worktree on origin/main so nothing else on the
    current branch leaks into main; no merge, no history rewrite.
    """
    run(['git', '-C', str(ROOT), 'fetch', 'origin', 'main'], dry=False)
    base = git('rev-parse', 'origin/main')
    content = UPDATE_JSON.read_text()
    if dry:
        print(f'[dry] would push update.json (from this branch) to main at {base}')
        return
    show = subprocess.run(['git', '-C', str(ROOT), 'show', 'origin/main:update.json'],
                          capture_output=True, text=True)
    main_text = show.stdout if show.returncode == 0 else ''
    if main_text == content:
        print('update.json on main already matches; nothing to publish.')
        return
    worktree = subprocess.run(
        ['mktemp', '-d'], capture_output=True, text=True, check=True).stdout.strip()
    run(['git', '-C', str(ROOT), 'worktree', 'add', '--detach', worktree, base], dry=False)
    try:
        (Path(worktree) / 'update.json').write_text(content)
        run(['git', '-C', worktree, 'add', 'update.json'], dry=False)
        run(['git', '-C', worktree, 'commit', '-m', 'release: publish update.json'],
            dry=False)
        run(['git', '-C', worktree, 'push', 'origin', 'HEAD:main'], dry=False)
    finally:
        run(['git', '-C', str(ROOT), 'worktree', 'remove', '--force', worktree], dry=False)


if __name__ == '__main__':
    sys.exit(main())
