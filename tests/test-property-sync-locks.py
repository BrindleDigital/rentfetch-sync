#!/usr/bin/env python3
"""Real parallel WP workers on a disposable Cove site; providers are fixture callbacks."""
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time

root = Path(sys.argv[1]).resolve()
assert root.parent.name.startswith(('rentfetch-lock-test-', 'rentfetch-followup-test-')), 'Fresh disposable site required'
fixture = root / 'wp-content/plugins/rentfetch-sync/tests/fixtures/property-sync-lock-worker.php'
command = ['wp', '--path=' + str(root), '--skip-plugins', '--skip-themes', 'eval-file', str(fixture)]
providers = ['yardi', 'rentmanager', 'entrata', 'engrain', 'rentegi']


def start(integration, property_id, mode='scheduled', **params):
    env = os.environ.copy()
    env['RFS_LOCK_TEST'] = json.dumps(dict(integration=integration, property_id=property_id, mode=mode, **params))
    return subprocess.Popen(command, env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)


def result(process):
    out, err = process.communicate(timeout=30)
    matches = [line.split('=', 1)[1] for line in out.splitlines() if line.startswith('RFS_LOCK_RESULT=')]
    if process.returncode or not matches:
        raise AssertionError('Worker failed: ' + (out + err)[-2500:])
    return json.loads(matches[-1])


def run(*args, **params):
    return result(start(*args, **params))


with tempfile.TemporaryDirectory(prefix='rfs-lock-workers-') as temp:
    for index, provider in enumerate(providers):
        code = 'LOCK_' + provider
        ready, release = Path(temp) / (provider + '.ready'), Path(temp) / (provider + '.release')
        owner = start(provider, code, ready=str(ready), release=str(release))
        try:
            deadline = time.monotonic() + 15
            while not ready.exists() and owner.poll() is None and time.monotonic() < deadline:
                time.sleep(.02)
            assert ready.exists(), (provider, 'owner did not enter provider')
            # Both user-triggered paths must be blocked before fixture/provider execution.
            for mode in ['manual', 'accelerated']:
                blocked = run(provider, code, mode)
                assert blocked['failed'] and not blocked['entered'], (provider, mode, blocked)
                assert set(blocked['counts'].values()) == {1}, (provider, 'duplicate records', blocked)
            # This cleanup attempt also proves failed contenders did not release the owner's lock.
            blocked_cleanup = run(provider, code, 'cleanup')
            assert blocked_cleanup['failed'] and set(blocked_cleanup['counts'].values()) == {1}, (provider, 'cleanup overlapped', blocked_cleanup)
            # Unrelated work must proceed while the owner's actual connection remains locked.
            parallel = run(provider, code + '_OTHER')
            assert parallel['entered'] and not parallel['failed'], (provider, 'different property blocked')
            parallel = run(providers[(index + 1) % len(providers)], code)
            assert parallel['entered'] and not parallel['failed'], (provider, 'different integration blocked')
        finally:
            release.touch()
            completed = result(owner)
        assert completed['entered'] and not completed['failed'], completed
        retry = run(provider, code, 'manual')
        assert not retry['failed'] and set(retry['counts'].values()) == {1}, (provider, 'release or identity failed', retry)
        clean = run(provider, code, 'cleanup')
        assert not clean['failed'] and set(clean['counts'].values()) == {0}, (provider, 'cleanup failed', clean)
        for behavior in ['fail', 'credentials-fail', 'lost']:
            failed = run(provider, code + '_' + behavior, behavior=behavior)
            assert failed['failed'], (provider, behavior, 'failure not surfaced')
            retry = run(provider, code + '_' + behavior)
            assert retry['entered'] and not retry['failed'] and set(retry['counts'].values()) == {1}, (provider, behavior, retry)
        reentry = run(provider, code + '_reentry', behavior='reentry')
        assert reentry['reentry_rejected'] and not reentry['failed'], (provider, 'reentry not rejected')
        print(provider + ': dispatcher contention, manual/accelerated exclusion, cleanup exclusion/recovery, parallel properties/providers, provider/credential failure release, lost-lock recovery and reentry passed.', flush=True)
print('All five active provider paths passed with real parallel PHP workers and disposable WordPress records.')
