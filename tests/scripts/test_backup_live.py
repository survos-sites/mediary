"""Safety regressions for the database refresh CLI; never contact real services."""
import hashlib
import os
from pathlib import Path
import subprocess
import tempfile
import unittest

SCRIPT = Path(__file__).resolve().parents[2] / 'bin' / 'backup-live.sh'


class BackupLiveTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.log = self.root / 'calls'
        self.dump = self.root / 'live.dump'
        self.dump.write_bytes(b'archive fixture')
        self.digest = hashlib.sha256(self.dump.read_bytes()).hexdigest()
        Path(str(self.dump) + '.sha256').write_text(self.digest + '\n')
        self.env = {k: v for k, v in os.environ.items() if not k.startswith(
            ('PROD_', 'LOCAL_', 'PG_', 'CONTAINER_', 'DOCKER_', 'LIVE_'))}
        self.env.update(PATH=str(self.root) + ':' + os.environ['PATH'], CTR='podman',
                        HOST_DUMP=str(self.dump), CALL_LOG=str(self.log),
                        MOCK_HASH=self.digest, MOCK_URI='ssh://core@127.0.0.1:1234/socket')
        mock = '''#!/usr/bin/env python3
import os, sys
from pathlib import Path
name = Path(sys.argv[0]).name
args = sys.argv[1:]
with open(os.environ['CALL_LOG'], 'a') as f:
    f.write(name + ' ' + ' '.join(args) + '\\n')
if name == 'podman':
    if args[:3] == ['system', 'connection', 'list']:
        print(os.environ['MOCK_URI'])
    if 'pg_restore' in args and '--list' not in args:
        sys.exit(int(os.environ.get('RESTORE_EXIT', '0')))
if name == 'ssh':
    print(os.environ['MOCK_HASH'] + '  remote.dump')
if name == 'rsync':
    Path(args[-1]).write_bytes(b'archive fixture')
'''
        for name in ('podman', 'ssh', 'rsync'):
            p = self.root / name
            p.write_text(mock)
            p.chmod(0o755)

    def run_cli(self, *args):
        return subprocess.run(['bash', str(SCRIPT), *args], env=self.env,
                              text=True, capture_output=True)

    def calls(self):
        return self.log.read_text() if self.log.exists() else ''

    def test_default_is_non_destructive_plan(self):
        result = self.run_cli()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('Local target:', result.stdout)
        self.assertEqual(self.calls(), '')

    def test_restore_requires_explicit_flag(self):
        self.assertNotEqual(self.run_cli('restore', 'mediary').returncode, 0)
        self.assertEqual(self.calls(), '')

    def test_rejects_sql_identifier_injection(self):
        self.env['LOCAL_DB'] = 'mediary;DROP DATABASE lingua'
        self.assertNotEqual(self.run_cli('restore', 'mediary', '--replace-local').returncode, 0)
        self.assertEqual(self.calls(), '')

    def test_remote_runtime_is_rejected_before_drop(self):
        self.env['MOCK_URI'] = 'ssh://root@production/socket'
        self.assertNotEqual(self.run_cli('restore', 'mediary', '--replace-local').returncode, 0)
        self.assertNotIn('DROP DATABASE', self.calls())

    def test_connection_override_is_rejected(self):
        self.env['CONTAINER_HOST'] = 'ssh://root@production/socket'
        self.assertNotEqual(self.run_cli('restore', 'lingua', '--replace-local').returncode, 0)
        self.assertNotIn('DROP DATABASE', self.calls())

    def test_corrupt_archive_is_rejected_before_drop(self):
        self.dump.write_bytes(b'corrupt')
        self.assertNotEqual(self.run_cli('restore', 'mediary', '--replace-local').returncode, 0)
        self.assertNotIn('DROP DATABASE', self.calls())

    def test_restore_failure_is_not_reported_as_success(self):
        self.env['RESTORE_EXIT'] = '1'
        result = self.run_cli('restore', 'lingua', '--replace-local')
        self.assertNotEqual(result.returncode, 0)
        self.assertIn('Restore FAILED', result.stderr)
        self.assertNotIn('Restore complete', result.stdout)
        self.assertNotIn('ANALYZE', self.calls())

    def test_fetch_does_not_redump(self):
        result = self.run_cli('fetch', 'lingua')
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotIn('pg_dump', self.calls())
        self.assertEqual(Path(str(self.dump) + '.sha256').read_text().strip(), self.digest)

    def test_failed_fetch_preserves_verified_archive(self):
        self.env['MOCK_HASH'] = '0' * 64
        result = self.run_cli('fetch', 'mediary')
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(self.dump.read_bytes(), b'archive fixture')
        self.assertEqual(Path(str(self.dump) + '.sha256').read_text().strip(), self.digest)


if __name__ == '__main__':
    unittest.main()
