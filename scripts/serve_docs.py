#!/usr/bin/env python3
"""Serve this repo's documentation over HTTP for a local Paseo preview.

Plain-text rendering; no authentication. Preview-only."""
from __future__ import annotations

import argparse
import http.server
import socketserver
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]

ALLOWED_PREFIXES = ('docs/', 'AGENTS.md', 'README.md')
SERVE_SUFFIXES = {'.md', '.markdown', '.txt'}


class Handler(http.server.BaseHTTPRequestHandler):
    def do_GET(self) -> None:  # noqa: N802
        path = self.path.lstrip('/').split('?')[0]
        if path in ('', 'index.html'):
            path = 'docs/README.md'
        if not path.startswith(ALLOWED_PREFIXES) or '..' in path:
            self.send_error(404)
            return
        candidate = ROOT / path
        if candidate.suffix.lower() not in SERVE_SUFFIXES or not candidate.is_file():
            self.send_error(404)
            return
        body = candidate.read_bytes()
        self.send_response(200)
        self.send_header('Content-Type', 'text/plain; charset=utf-8')
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, format: str, *args) -> None:  # noqa: A002
        sys.stderr.write('[docserve] %s %s\n' % (self.command, self.path))


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--port', type=int, default=8081)
    args = parser.parse_args()
    with http.server.ThreadingHTTPServer(('127.0.0.1', args.port), Handler) as httpd:
        print(f'serving docs on 127.0.0.1:{args.port}', file=sys.stderr)
        httpd.serve_forever()
    return 0


if __name__ == '__main__':
    sys.exit(main())
