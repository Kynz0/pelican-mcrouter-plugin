#!/usr/bin/env python3
"""Validate the docs skeleton of this repo.

Checks that every markdown file exists, that relative links inside docs/
resolve, and that required sections are present. Exits 0 on success."""
from __future__ import annotations

import re
import sys
from pathlib import Path

REQUIRED = {
    'docs/product-brief.md': ['V1 requirements', 'Invariants'],
    'docs/topology.md': ['Deployment topology'],
    'docs/pelican-integration.md': ['Creation wizard', 'Router seam'],
    'docs/architecture.md': ['Create path', 'Router-side boundary', 'Failure and operations'],
    'docs/verification.md': ['Acceptance'],
    'docs/agent-handoff.md': ['Phase 1', 'Phase 2', 'Phase 3', 'Phase 4'],
}


def main() -> int:
    root = Path(__file__).resolve().parents[1]
    readme = root / 'docs' / 'README.md'
    if not readme.is_file():
        print('FAIL: docs/README.md not found')
        return 1

    failures: list[str] = []
    excluded = {'.git', '.dev', 'vendor', 'dist', 'node_modules'}
    md_files = sorted(p for p in root.rglob('*.md') if p.is_file()
                      and not excluded.intersection(p.relative_to(root).parts))
    link_pattern = re.compile(r'\[[^\]]+\]\(([^)#\s]+)(?:#[^)]*)?\)')

    for md in md_files:
        text = md.read_text(encoding='utf-8')
        for match in link_pattern.finditer(text):
            target = match.group(1).strip()
            if not target or target.startswith(('http://', 'https://', 'mailto:')):
                continue
            resolved = (md.parent / target).resolve()
            if not resolved.exists():
                failures.append(f'{md.relative_to(root)}: broken relative link -> {target}')

    for path, needles in REQUIRED.items():
        target = root / path
        if not target.is_file():
            failures.append(f'missing required doc: {path}')
            continue
        body = target.read_text(encoding='utf-8')
        for needle in needles:
            if needle not in body:
                failures.append(f'{path}: missing section "{needle}"')

    if failures:
        print('FAIL:')
        for line in failures:
            print(' -', line)
        return 1
    print(f'OK: {len(md_files)} markdown files, links resolve, required sections present')
    return 0


if __name__ == '__main__':
    sys.exit(main())
