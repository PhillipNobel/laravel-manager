import importlib.machinery
import importlib.util
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch, Mock

loader = importlib.machinery.SourceFileLoader('database_helper', str(Path(__file__).resolve().parents[2] / 'scripts/laravel-manager-database'))
spec = importlib.util.spec_from_loader(loader.name, loader)
helper = importlib.util.module_from_spec(spec)
loader.exec_module(helper)


class GitEnvironmentCheck(unittest.TestCase):
    def test_uses_only_the_validated_project_as_safe_directory(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            (root / 'portal').mkdir()
            with patch.object(helper, 'load_applications_root', return_value=root), patch.object(helper.subprocess, 'run', return_value=Mock(returncode=0)) as run:
                helper.require_env_is_ignored('portal')
                command = run.call_args.args[0]
                self.assertIn('safe.directory=' + str(root / 'portal'), command)
                self.assertNotIn('safe.directory=*', command)
                self.assertIn('core.fsmonitor=false', command)
                self.assertEqual(run.call_args.kwargs['timeout'], 5)
                self.assertEqual(run.call_args.kwargs['env']['GIT_CONFIG_GLOBAL'], '/dev/null')

    def test_rejects_a_symlink_before_invoking_git(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory).resolve()
            (root / 'target').mkdir()
            (root / 'portal').symlink_to(root / 'target')
            with patch.object(helper, 'load_applications_root', return_value=root), patch.object(helper.subprocess, 'run') as run:
                with self.assertRaises(helper.HelperError):
                    helper.require_env_is_ignored('portal')
                run.assert_not_called()
