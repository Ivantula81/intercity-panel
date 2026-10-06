#!/usr/bin/env python3
"""Process-level guard tests; only synthetic files, no DB/bootstrap/provider calls."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import socket
import sys
import signal
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[1]
PHP = shutil.which('php')
A, B, C = 'a' * 32, 'b' * 32, 'c' * 32
checks = []
skipped = []
processes = []


def check(condition, label):
    if not condition:
        raise AssertionError(label)
    checks.append(label)


def wait_for(predicate):
    deadline = time.monotonic() + 5
    while not predicate():
        if time.monotonic() > deadline:
            raise AssertionError('timed out waiting for synthetic process')
        time.sleep(.01)


with tempfile.TemporaryDirectory(prefix='panel-jobs-test-') as temporary:
    base = Path(temporary).resolve()
    state = base / 'state'
    env = dict(os.environ, PANEL_JOBS_STATE_DIR=str(state))
    control = [PHP, str(ROOT / 'scripts/jobs_control.php')]
    fixture = base / 'worker.php'
    fixture.write_text('''<?php
require $argv[1];
BackgroundJobs::enter($argv[2]);
file_put_contents($argv[3], 'admitted');
if (($argv[5] ?? '') === 'shutdown') {
    register_shutdown_function(function () use ($argv) {
        file_put_contents($argv[3] . '.shutdown', 'active');
        while (!is_file($argv[4])) { clearstatcache(); usleep(10000); }
    });
    exit;
}
while (!is_file($argv[4])) { clearstatcache(); usleep(10000); }
''')

    def run(args, expected=0, environment=None):
        result = subprocess.run(args, env=environment or env, capture_output=True, text=True, timeout=8)
        if result.returncode != expected:
            raise AssertionError(f'exit {result.returncode} expected {expected}: {result.stdout} {result.stderr}')
        return result

    def ctl(command, owner=None, timeout=None, expected=0):
        args = control + [command]
        if owner is not None:
            args.append(owner)
        if timeout is not None:
            args.append(str(timeout))
        return run(args, expected)

    def status():
        return json.loads(ctl('status').stdout)

    def launch(name='broadcast_worker', mode='', library=None):
        number = len(processes)
        marker, release = base / f'admitted-{number}', base / f'release-{number}'
        process = subprocess.Popen([PHP, str(fixture), str(library or ROOT / 'lib/BackgroundJobs.php'),
                                    name, str(marker), str(release), mode],
                                   env=env, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        processes.append(process)
        return process, marker, release

    def admitted(name='broadcast_worker', mode='', library=None):
        process, marker, release = launch(name, mode, library)
        wait_for(lambda: marker.exists() or process.poll() is not None)
        check(marker.exists(), f'{name}: admission reaches synthetic work')
        return process, marker, release

    def finish(worker):
        worker[2].touch()
        out, err = worker[0].communicate(timeout=5)
        check(worker[0].returncode == 0, 'admitted job completes normally')

    def denied(expected=75, name='broadcast_worker'):
        process, marker, _ = launch(name)
        out, err = process.communicate(timeout=5)
        check(process.returncode == expected and not marker.exists(), f'{name}: rejected before work ({expected})')

    try:
        if '--require-network-isolation' in sys.argv:
            check(os.readlink('/proc/self/ns/net') != os.readlink('/proc/1/ns/net')
                  and [name for _, name in socket.if_nameindex()] == ['lo'],
                  'separate Linux network namespace has only loopback')
        denied(78)
        ctl('init', A)
        check(status() == {'mode': 'paused', 'idle': True}, 'initialization is paused and idle')
        inodes = {p.name: p.stat().st_ino for p in state.glob('*.lock')}
        ctl('init', B, expected=78)
        check(inodes == {p.name: p.stat().st_ino for p in state.glob('*.lock')}, 'reinitialization cannot replace locks')
        denied()
        ctl('resume', B, expected=78)
        check(status()['mode'] == 'paused', 'wrong owner cannot resume')

        # Each production entrypoint is copied with a sentinel bootstrap: no credentials or business code.
        jobs = ['scripts/broadcast_worker.php', 'scripts/broadcast_report_worker.php',
                'scripts/sales_ingest.php', 'scripts/backfill_conversations.php',
                'scripts/sales_seed.php', 'scripts/import_dryrun.php', 'backfill_contacts.php',
                'tools/backfill_chat_ids.php', 'tools/clean_failed_convs.php',
                'tools/merge_conv_dups.php', 'tools/reporting_agents_fix.php', 'tools/reporting_reset.php']
        release = base / 'release'
        for directory in ['lib', 'app', 'scripts', 'tools']:
            (release / directory).mkdir(parents=True, exist_ok=True)
        shutil.copyfile(ROOT / 'lib/BackgroundJobs.php', release / 'lib/BackgroundJobs.php')
        (release / 'app/bootstrap.php').write_text('<?php fwrite(STDOUT, "BOOTSTRAP\\n"); exit(42);')
        for job in jobs:
            shutil.copyfile(ROOT / job, release / job)
            result = run([PHP, str(release / job), '--dry-run'], 75)
            check('BOOTSTRAP' not in result.stdout, job + ': paused before bootstrap')
        ctl('resume', A)
        for job in jobs:
            result = run([PHP, str(release / job), '--dry-run'], 42)
            check(result.stdout == 'BOOTSTRAP\n', job + ': running reaches sentinel bootstrap')
        ctl('pause', A, expected=78)
        check(status()['mode'] == 'running', 'new pause cannot reuse completed owner')
        denied(78, 'unknown')

        first = admitted()
        denied()  # Same job cannot overlap, even with no scheduler mutex.
        other = admitted('sales_ingest')
        check(status() == {'mode': 'running', 'idle': False}, 'different jobs coexist and are counted active')
        ctl('pause', B, .03, 75)
        check(status() == {'mode': 'paused', 'idle': False}, 'timeout retains persistent pause and active jobs')
        denied(name='broadcast_report_worker')
        ctl('resume', B, expected=75)
        ctl('pause', C, expected=78)
        ctl('resume', A, expected=78)
        finish(first)
        finish(other)
        check(status() == {'mode': 'paused', 'idle': True}, 'last worker exit yields paused idle state')
        ctl('pause', B, 0)

        # A different release uses the same stable locks and state.
        replacement = base / 'next-release'
        replacement.mkdir()
        shutil.copyfile(ROOT / 'lib/BackgroundJobs.php', replacement / 'BackgroundJobs.php')
        process, marker, _ = launch(library=replacement / 'BackgroundJobs.php')
        process.communicate(timeout=5)
        check(process.returncode == 75 and not marker.exists(), 'release switch does not bypass pause')
        ctl('resume', B)
        held = admitted(mode='shutdown', library=replacement / 'BackgroundJobs.php')
        wait_for(lambda: Path(str(held[1]) + '.shutdown').exists())
        ctl('pause', C, 0, 75)
        check(not status()['idle'], 'shutdown callback retains worker lease')
        finish(held)
        ctl('pause', C, 0)
        ctl('resume', C)

        held = admitted()
        controller = subprocess.Popen(control + ['pause', A, '5'], env=env,
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        processes.append(controller)
        wait_for(lambda: json.loads((state / 'state.json').read_text())['mode'] == 'paused')
        ctl('resume', A, expected=75)
        ctl('pause', B, 0, 75)
        check(controller.poll() is None, 'controller serialization blocks competing pause and resume')
        controller.kill()
        controller.communicate(timeout=5)
        check(status() == {'mode': 'paused', 'idle': False}, 'controller crash retains pause')
        denied(name='sales_ingest')
        held[0].kill()
        held[0].communicate(timeout=5)
        check(status()['idle'], 'worker crash releases locks without stale pid cleanup')
        ctl('pause', A, 0)
        ctl('resume', A)
        held = admitted()
        held[0].send_signal(signal.SIGTERM)
        held[0].communicate(timeout=5)
        check(status()['idle'], 'terminated worker releases lease')
        check(inodes == {p.name: p.stat().st_ino for p in state.glob('*.lock')}, 'pause resume and crashes never replace lock inodes')

        # Successful drain waits for actual completion, not just a stop signal.
        held = admitted('sales_ingest')
        controller = subprocess.Popen(control + ['pause', B, '5'], env=env,
                                      stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        processes.append(controller)
        wait_for(lambda: json.loads((state / 'state.json').read_text())['mode'] == 'paused')
        denied(name='broadcast_report_worker')
        check(controller.poll() is None, 'pause waits while admitted work is active')
        finish(held)
        out, err = controller.communicate(timeout=5)
        check(controller.returncode == 0 and 'drained' in out, 'pause succeeds after real process completion')

        # Corruption, absent files and symlinks all fail closed.
        ctl('resume', B)
        saved = (state / 'state.json').read_text()
        (state / 'state.json').write_text('{')
        denied(78)
        ctl('status', expected=78)
        ctl('resume', B, expected=78)
        (state / 'state.json').write_text(saved)
        lock = state / 'broadcast_worker.lock'
        lock.rename(state / 'saved.lock')
        denied(78)
        lock.symlink_to(state / 'saved.lock')
        denied(78)
        lock.unlink()
        (state / 'saved.lock').rename(lock)
        alias = base / 'alias'
        alias.symlink_to(state)
        run(control + ['status'], 78, dict(env, PANEL_JOBS_STATE_DIR=str(alias)))
        check(True, 'noncanonical state directory rejected')
        ctl('pause', C, -1, 78)
        ctl('pause', C, 'NaN', 78)
        check(status()['mode'] == 'running', 'invalid arguments do not change state')
        # The actual queue dry-run reads only a synthetic SQLite queue through an isolated bootstrap.
        drivers = json.loads(run([PHP, '-r', 'echo json_encode(PDO::getAvailableDrivers());']).stdout)
        if 'sqlite' in drivers:
            queue = base / 'queue.sqlite'
            with sqlite3.connect(queue) as db:
                db.execute('CREATE TABLE broadcast_deliveries (id INTEGER PRIMARY KEY, status TEXT)')
                db.executemany('INSERT INTO broadcast_deliveries(status) VALUES (?)', [('queued',), ('accepted',), ('queued',)])
            for name in ['app/broadcast_queue.php', 'app/conversations.php', 'lib/Channels.php']:
                shutil.copyfile(ROOT / name, release / name)
            (release / 'app/bootstrap.php').write_text("""<?php
            define('PANEL_ROOT', dirname(__DIR__));
            function db(): PDO { return new PDO('sqlite:' . getenv('PANEL_JOBS_TEST_DB')); }
            """)
            queue_before = queue.read_bytes()
            queue_env = dict(env, PANEL_JOBS_TEST_DB=str(queue))
            result = run([PHP, str(release / 'scripts/broadcast_worker.php'), '--dry-run'], environment=queue_env)
            check(result.stdout == 'dry-run queued 2; no changes\n', 'actual queue dry-run reads synthetic rows')
            check(queue.read_bytes() == queue_before, 'actual queue dry-run leaves database unchanged')

        else:
            skipped.append('Actual queue dry-run: pdo_sqlite unavailable; tested on local host separately')

        # Linux-only identity check: root controls state, www-data can lock but cannot resume.
        if os.geteuid() == 0 and shutil.which('runuser'):
            import pwd
            identity = pwd.getpwnam('www-data')
            base.chmod(0o755)
            isolated_lib = release / 'lib/BackgroundJobs.php'
            probe = base / 'identity.php'
            probe.write_text("<?php require $argv[1]; BackgroundJobs::enter('broadcast_worker'); echo \"ADMITTED\\n\";")
            prefix = ['runuser', '-u', identity.pw_name, '--', 'env', 'PANEL_JOBS_STATE_DIR=' + str(state)]
            result = run(prefix + [PHP, str(probe), str(isolated_lib)])
            check(result.stdout == 'ADMITTED\n', 'unprivileged worker can acquire root-owned read-only lock files')
            # Copy controller too, so the private checkout's path permissions are irrelevant.
            shutil.copyfile(ROOT / 'scripts/jobs_control.php', release / 'scripts/jobs_control.php')
            unpriv_control = prefix + [PHP, str(release / 'scripts/jobs_control.php')]
            ctl('pause', C, 0)
            run(unpriv_control + ['resume', C], 78)
            check(status()['mode'] == 'paused', 'unprivileged worker identity cannot alter pause state')
            run(prefix + [PHP, str(probe), str(isolated_lib)], 75)
            check(True, 'unprivileged worker obeys persistent pause')
            ctl('resume', C)
            next_owner = A
        else:
            next_owner = C

        (state / 'state.json').chmod(0o666)
        denied(78)
        (state / 'state.json').chmod(0o644)
        state.chmod(0o777)
        denied(78)
        state.chmod(0o755)
        ctl('pause', next_owner, 0)
        denied()
        print(json.dumps({'assertions': len(checks), 'checks': checks, 'skipped': skipped}, ensure_ascii=False, indent=2))
    finally:
        for process in processes:
            if process.poll() is None:
                process.kill()
                process.communicate(timeout=5)
