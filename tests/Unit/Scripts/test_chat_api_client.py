import importlib.util
import io
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import Mock, patch
import urllib.error

SPEC = importlib.util.spec_from_file_location('chat_api', Path(__file__).resolve().parents[3] / 'scripts/qa/chat-api.py')
qa = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(qa)


class ChatClientTest(unittest.TestCase):
    def profile(self):
        return {'base_url': 'https://demo.example.test', 'tenant_id': 'acme', 'token': 'private-token', 'project_key': 'orders'}

    def test_http_request_is_bearer_authenticated_without_redirects_or_automatic_retry(self):
        client = qa.Client(self.profile())
        client.opener = Mock()
        client.opener.open.side_effect = urllib.error.URLError('connection lost')
        with self.assertRaises(urllib.error.URLError):
            client.request('POST', '/api/chat/conversations', {})
        self.assertEqual(1, client.opener.open.call_count)
        request = client.opener.open.call_args.args[0]
        self.assertEqual('Bearer private-token', request.get_header('Authorization'))
        self.assertEqual('acme', request.get_header('X-tenant-id'))
        self.assertIsNone(qa.NoRedirect().redirect_request(None, None, 302, '', {}, 'https://foreign.test'))

    def test_watch_drains_terminal_pages_and_preserves_rich_messages(self):
        with tempfile.TemporaryDirectory() as output:
            client = qa.Client(self.profile())
            messages = [{'role': 'assistant', 'content': 'Answer', 'metadata': {'citations': [123], 'agent_artifact': {'rows': [1]}}}]
            client.request = Mock(side_effect=[
                {'run_id': 'r', 'conversation_id': 3, 'status': 'completed', 'terminal': True, 'error_code': None,
                 'events': [{'sequence': 1}], 'next_after': 1, 'has_more': True},
                {'run_id': 'r', 'conversation_id': 3, 'status': 'completed', 'terminal': True, 'error_code': None,
                 'events': [{'sequence': 2}], 'next_after': 2, 'has_more': False}, messages])
            with patch('sys.stdout', new_callable=io.StringIO):
                self.assertEqual(0, client.watch('r', output))
            self.assertEqual(messages, json.loads((Path(output) / 'messages.json').read_text()))
            self.assertEqual(2, len(json.loads((Path(output) / 'events.json').read_text())))
            self.assertEqual('/api/chat/runs/r?after=1', client.request.call_args_list[1].args[1])
            self.assertEqual(0o600, (Path(output) / 'messages.json').stat().st_mode & 0o777)

    def test_observation_timeout_does_not_cancel_or_resubmit_the_server_job(self):
        with tempfile.TemporaryDirectory() as output:
            client = qa.Client(self.profile())
            client.request = Mock(return_value={'status': 'running', 'terminal': False, 'events': [], 'next_after': 0, 'has_more': False})
            self.assertEqual(124, client.watch('r', output, wait=0))
            self.assertEqual(1, client.request.call_count)
            self.assertTrue(json.loads((Path(output) / 'summary.json').read_text())['observation_timeout'])

    def test_uncertain_submission_is_saved_and_not_repeated(self):
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder) / 'profile.json'
            qa.write_private(profile, self.profile())
            output = Path(folder) / 'run'
            client = Mock()
            client.request.side_effect = TimeoutError('lost acknowledgement')
            args = ['--profile', str(profile), 'ask', '--conversation', '3', '--question', 'Check order', '--output', str(output)]
            with patch.object(qa, 'Client', return_value=client):
                with self.assertRaises(TimeoutError): qa.main(args)
                with self.assertRaises(ValueError): qa.main(args)
            self.assertEqual(1, client.request.call_count)
            self.assertEqual('sending', json.loads((output / 'submission.json').read_text())['state'])

    def test_credentials_require_private_files_and_remote_tls(self):
        with tempfile.TemporaryDirectory() as folder:
            profile = Path(folder) / 'profile.json'
            qa.write_private(profile, self.profile())
            profile.chmod(0o644)
            with self.assertRaises(ValueError): qa.read_private(profile)
        with self.assertRaises(ValueError): qa.Client({**self.profile(), 'base_url': 'http://example.com'})

    def test_waiting_for_confirmation_is_returned_without_automatic_approval(self):
        with tempfile.TemporaryDirectory() as output:
            client = qa.Client(self.profile())
            client.request = Mock(side_effect=[{'conversation_id': 3, 'status': 'awaiting_confirmation',
                'terminal': False, 'error_code': None, 'events': [], 'next_after': 0, 'has_more': False}, []])
            with patch('sys.stdout', new_callable=io.StringIO):
                self.assertEqual(3, client.watch('r', output))
            self.assertTrue(all(call.args[0] == 'GET' for call in client.request.call_args_list))


if __name__ == '__main__':
    unittest.main()
