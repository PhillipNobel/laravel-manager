"""Bridge contract tests. Never invoke an updater, sudo, network, or systemd."""
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import subprocess
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('bridge', str(Path(__file__).resolve().parents[2] / 'scripts/laravel-manager-updates'))
spec = importlib.util.spec_from_loader(loader.name, loader)
bridge = importlib.util.module_from_spec(spec)
loader.exec_module(bridge)


class BridgeTests(unittest.TestCase):
    def test_invalid_arguments_never_validate_or_execute(self):
        for args in [['start', 'main'], ['run', 'shell'], ['progress', 'secret'], ['update'], []]:
            with patch.object(bridge.sys, 'argv', ['bridge'] + args), patch.object(bridge, 'validate') as validate:
                self.assertEqual(bridge.main(), 2)
                validate.assert_not_called()

    def test_rejects_non_root(self):
        with patch.object(bridge.os, 'geteuid', return_value=1000):
            with self.assertRaises(ValueError):
                bridge.validate()

    def test_rejects_symlink_status(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'target').write_text('{}')
            (root / 'status.json').symlink_to(root / 'target')
            with patch.object(bridge, 'STATE', root):
                with self.assertRaises(ValueError):
                    bridge.read()

    def test_rejects_unsafe_git_origin(self):
        for origin in ['https://token@github.com/a/b.git', 'file:///tmp/repo', 'https://github.com/a/b.git;id']:
            with patch.object(bridge, 'git', side_effect=['a' * 40, 'main', origin]):
                with self.assertRaises(ValueError):
                    bridge.installed()

    def test_check_network_failure_is_sanitized(self):
        with patch.object(bridge, 'lock', return_value=7), patch.object(bridge.os, 'close'), \
             patch.object(bridge, 'status', return_value={'state': 'unchecked'}), \
             patch.object(bridge, 'installed', return_value=('a' * 40, 'main', 'https://github.com/a/b.git')), \
             patch.object(bridge, 'git', side_effect=subprocess.TimeoutExpired('secret', 25)), \
             patch.object(bridge, 'write') as write:
            result = bridge.check()
            self.assertEqual(result['state'], 'unavailable')
            self.assertNotIn('secret', json.dumps(result))
            write.assert_called_once()

    def test_up_to_date_and_available(self):
        for target, expected in [('a' * 40, 'up_to_date'), ('b' * 40, 'available')]:
            with patch.object(bridge, 'lock', return_value=7), patch.object(bridge.os, 'close'), \
                 patch.object(bridge, 'status', return_value={'state': 'unchecked'}), \
                 patch.object(bridge, 'installed', return_value=('a' * 40, 'main', 'https://github.com/a/b.git')), \
                 patch.object(bridge, 'git', return_value=target + '\trefs/heads/main'), patch.object(bridge, 'write'):
                self.assertEqual(bridge.check()['state'], expected)

    def test_duplicate_start_is_rejected(self):
        with patch.object(bridge, 'lock', return_value=7), patch.object(bridge.os, 'close'), \
             patch.object(bridge, 'status', return_value={'state': 'running'}), patch.object(bridge, 'command') as command:
            with self.assertRaises(ValueError):
                bridge.start()
            command.assert_not_called()

    def test_service_launch_has_no_user_arguments(self):
        with patch.object(bridge, 'lock', return_value=7), patch.object(bridge.os, 'close'), \
             patch.object(bridge.os, 'open', return_value=8), patch.object(bridge.fcntl, 'flock'), \
             patch.object(bridge, 'status', return_value={'state': 'available', 'installed_commit': 'a' * 40}), \
             patch.object(bridge, 'command') as command, patch.object(bridge, 'write'):
            self.assertEqual(bridge.start()['state'], 'queued')
            self.assertEqual(command.call_args.args[0], ['/usr/bin/systemctl', 'start', '--no-block', bridge.UNIT])
            self.assertIn('manager:update-ready', command.call_args_list[0].args[0])

    def test_busy_operation_blocks_launch(self):
        with patch.object(bridge, 'lock', return_value=7), patch.object(bridge.os, 'close'), \
             patch.object(bridge.os, 'open', return_value=8), patch.object(bridge.fcntl, 'flock', side_effect=BlockingIOError), \
             patch.object(bridge, 'status', return_value={'state': 'available'}), patch.object(bridge, 'command') as command:
            with self.assertRaises(BlockingIOError):
                bridge.start()
            command.assert_not_called()

    def test_dead_service_reports_interrupted(self):
        with tempfile.TemporaryDirectory() as directory:
            with patch.object(bridge, 'STATE', Path(directory)), \
                 patch.object(bridge, 'read', return_value={'state': 'running'}), \
                 patch.object(bridge, 'installed', return_value=('a' * 40, 'main', 'https://github.com/a/b.git')), \
                 patch.object(bridge, 'git', return_value='a' * 12), \
                 patch.object(bridge, 'updater_active', return_value=False), patch.object(bridge, 'service_active', return_value=False):
                self.assertEqual(bridge.status()['state'], 'interrupted')

    def test_progress_is_bounded_and_contains_only_fixed_messages(self):
        data = {'history': [{'phase': 'waiting'}] * 100}
        with patch.object(bridge, 'read', return_value=data), patch.object(bridge, 'write') as write:
            bridge.progress('assets')
            output = write.call_args.args[0]
            self.assertEqual(len(output['history']), 12)
            self.assertEqual(output['message'], bridge.PHASES['assets'])
            with self.assertRaises(ValueError):
                bridge.progress('secret')


if __name__ == '__main__':
    unittest.main()
