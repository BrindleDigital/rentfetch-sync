#!/usr/bin/env python3
"""Run eight old/new plugin combinations in both client load orders.

Usage: python3 tests/test-mixed-versions.py DISPOSABLE_SITE_PUBLIC SNAPSHOT_ROOT
SNAPSHOT_ROOT/{old,new}/{rentfetch,rentfetch-sync,rentfetch-api} holds source snapshots.
The API runs separately from Core/Sync. All external provider traffic is mocked.
"""
import itertools
import json
from pathlib import Path
import os
import subprocess
import sys

root = Path(sys.argv[1]).resolve()
snapshots = Path(sys.argv[2]).resolve()
assert root.parent.name.startswith('rentfetch-followup-test-'), 'Disposable Cove site required'
fixtures = Path(__file__).resolve().parent / 'fixtures'
for fixture in fixtures.glob('mixed-version-*.php'):
    subprocess.run(['php', '-l', str(fixture)], check=True, capture_output=True)
base = ['php', '-r', 'ob_start(); require "/usr/local/bin/wp";', '--', '--path=' + str(root), '--skip-themes']
results = []
for core, sync, api in itertools.product(['old', 'new'], repeat=3):
    for order in ['core-first', 'sync-first']:
        case = dict(core=core, sync=sync, api=api, order=order)
        for name, version in [('rentfetch', core), ('rentfetch-sync', sync), ('rentfetch-api', api)]:
            source = snapshots / version / name
            assert source.is_dir(), source
            destination = root / 'wp-content/plugins' / name
            destination.mkdir(exist_ok=True)
            subprocess.run(['rsync', '-a', '--delete', str(source) + '/', str(destination) + '/'], check=True)
        env = os.environ.copy()
        env['RF_MIXED_CASE'] = json.dumps(case)
        for command in [base + ['--skip-plugins', 'eval-file', str(fixtures / 'mixed-version-setup.php')],
                        base + ['eval-file', str(fixtures / 'mixed-version-client.php')]]:
            process = subprocess.run(command, env=env, text=True, capture_output=True, timeout=90)
            output = process.stdout + process.stderr
            if process.returncode or 'Fatal error:' in output or 'Parse error:' in output:
                relevant = '\n'.join(line for line in output.splitlines() if 'Deprecated:' not in line)
                raise AssertionError(str(case) + '\n' + relevant[-6000:])
        line = next(x for x in process.stdout.splitlines() if x.startswith('MIXED_CLIENT_RESULT='))
        result = json.loads(line.split('=', 1)[1])
        results.append(result)
        print(json.dumps(result), flush=True)
print('All sixteen mixed-version/load-order cases passed.', flush=True)
