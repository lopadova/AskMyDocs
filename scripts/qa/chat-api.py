#!/usr/bin/env python3
"""Exercise the real remote chat queue over Bearer-authenticated HTTP."""
import argparse
import json
import os
import socket
from pathlib import Path
import sys
import time
import urllib.error
import urllib.parse
import urllib.request


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


def write_private(path, value, exclusive=False):
    path = Path(path)
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    # Refuse symlinks and set permissions before writing credential-bearing data.
    fd = os.open(path, os.O_WRONLY | os.O_CREAT | (os.O_EXCL if exclusive else os.O_TRUNC) | getattr(os, 'O_NOFOLLOW', 0), 0o600)
    with os.fdopen(fd, 'w') as stream:
        os.fchmod(stream.fileno(), 0o600)
        json.dump(value, stream, ensure_ascii=False, indent=2)
        stream.write('\n')


def read_private(path):
    path = Path(path)
    if path.stat().st_mode & 0o077:
        raise ValueError('Credential file must be private: chmod 600 the file.')
    return json.loads(path.read_text())


class Client:
    def __init__(self, profile):
        self.base = profile['base_url'].rstrip('/')
        parsed = urllib.parse.urlsplit(self.base)
        if parsed.scheme not in ('https', 'http') or not parsed.hostname or parsed.username or parsed.password or parsed.query or parsed.fragment or parsed.path:
            raise ValueError('base_url must be an HTTP(S) origin without credentials or path.')
        if parsed.scheme == 'http' and not (parsed.hostname.endswith('.test') or parsed.hostname in ('localhost', '127.0.0.1')):
            raise ValueError('Use HTTPS for remote servers.')
        self.profile = profile
        self.opener = urllib.request.build_opener(NoRedirect())

    def request(self, method, path, payload=None, timeout=30):
        if not path.startswith('/api/chat/') or urllib.parse.urlsplit(path).netloc:
            raise ValueError('Only chat API paths are accepted.')
        body = None if payload is None else json.dumps(payload).encode()
        req = urllib.request.Request(self.base + path, data=body, method=method, headers={
            'Authorization': 'Bearer ' + self.profile['token'],
            'X-Tenant-Id': self.profile['tenant_id'], 'Accept': 'application/json',
            'Content-Type': 'application/json', 'User-Agent': 'AskMyDocs-chat-qa/1',
        })
        deadline = time.monotonic() + timeout
        for attempt in range(3):
            try:
                with self.opener.open(req, timeout=max(.1, deadline - time.monotonic())) as response:
                    raw = response.read()
                    return json.loads(raw) if raw else None
            except urllib.error.HTTPError as error:
                # HTTP errors, redirects and authentication failures never retry.
                error.close()
                raise RuntimeError(f'HTTP {error.code} on {method} {path.split("?")[0]}') from None
            except (urllib.error.URLError, TimeoutError, ConnectionError) as error:
                reason = error.reason if isinstance(error, urllib.error.URLError) else error
                delay = .25 * (attempt + 1)
                # A read-only observation may recover from transient DNS/network
                # failures. POST/DELETE outcomes are never submitted twice.
                if method != 'GET' or attempt == 2 or not isinstance(reason, (socket.gaierror, TimeoutError, ConnectionError)) or time.monotonic() + delay >= deadline:
                    raise
                time.sleep(delay)

    def watch(self, run_id, output, wait=240, poll=1, debug=False):
        output = Path(output)
        deadline = time.monotonic() + wait
        cursor, events = 0, []
        while True:
            snapshot = self.request('GET', f'/api/chat/runs/{run_id}?after={cursor}',
                                    timeout=max(0.1, min(30, deadline - time.monotonic())))
            events.extend(snapshot['events'])
            cursor = snapshot['next_after']
            write_private(output / 'run.json', snapshot)
            write_private(output / 'events.json', events)
            if not snapshot['has_more'] and (snapshot['terminal'] or snapshot['status'] not in ('queued', 'running')):
                break
            if time.monotonic() >= deadline:
                write_private(output / 'summary.json', {'run_id': run_id, 'status': snapshot['status'],
                    'observation_timeout': True, 'note': 'Client stopped waiting; server job was not cancelled.'})
                return 124
            if not snapshot['has_more']:
                time.sleep(min(poll, max(0, deadline - time.monotonic())))
        conversation = snapshot['conversation_id']
        messages = self.request('GET', f'/api/chat/conversations/{conversation}/messages')
        write_private(output / 'messages.json', messages)
        if debug:
            try:
                transcript = self.request('GET', f'/api/chat/conversations/{conversation}/debug-transcript')
                write_private(output / 'debug.json', transcript)
            except RuntimeError as error:
                if not str(error).startswith('HTTP 403 '):
                    raise
                write_private(output / 'debug-unavailable.json', {'reason': 'debug_scope_or_admin_role_required'})
        summary = {'run_id': run_id, 'conversation_id': conversation, 'status': snapshot['status'],
                   'requires_action': not snapshot['terminal'], 'error_code': snapshot['error_code'],
                   'output': str(output.resolve())}
        write_private(output / 'summary.json', summary)
        print(json.dumps(summary, ensure_ascii=False))
        return 3 if summary['requires_action'] else 1 if snapshot['status'] in ('failed', 'cancelled') else 0


def parser():
    p = argparse.ArgumentParser(description=__doc__)
    p.add_argument('--profile', default=os.environ.get('ASKMYDOCS_CHAT_PROFILE', str(Path.home() / '.config/askmydocs/chat-api.json')))
    commands = p.add_subparsers(dest='command', required=True)
    init = commands.add_parser('init', help='Import the operator-issued token JSON into a private profile')
    init.add_argument('--base-url', required=True)
    init.add_argument('--token-file', required=True)
    init.add_argument('--project', required=True)
    ask = commands.add_parser('ask')
    ask.add_argument('--question', required=True)
    ask.add_argument('--conversation', type=int)
    ask.add_argument('--input-json', help='Additional chat inputs: filters, live_sources, depth, selection')
    for name in ('ask', 'watch'):
        sub = ask if name == 'ask' else commands.add_parser(name)
        if name == 'watch': sub.add_argument('--run', required=True)
        sub.add_argument('--output', required=True)
        sub.add_argument('--wait', type=float, default=240)
        sub.add_argument('--poll', type=float, default=1)
        sub.add_argument('--debug', action='store_true')
    for name in ('snapshot', 'cancel', 'continue', 'reconcile'):
        sub = commands.add_parser(name)
        sub.add_argument('--run', required=True)
        if name == 'continue':
            sub.add_argument('--logical', type=int, default=0)
            sub.add_argument('--physical', type=int, required=True)
    for name in ('messages', 'debug'):
        commands.add_parser(name).add_argument('--conversation', required=True, type=int)
    commands.add_parser('sources')
    commands.add_parser('check')
    commands.add_parser('conversations')
    commands.add_parser('document').add_argument('--id', required=True, type=int)
    tool = commands.add_parser('tool-source')
    for name in ('conversation', 'message', 'execution'): tool.add_argument('--' + name, required=True, type=int)
    commands.add_parser('revoke')
    return p


def main(argv=None):
    args = parser().parse_args(argv)
    if args.command == 'init':
        credential = read_private(args.token_file)
        profile = {**credential, 'base_url': args.base_url, 'project_key': args.project}
        Client(profile)  # Validate before storing.
        write_private(args.profile, profile)
        print('Private profile saved.')
        return 0
    profile = read_private(args.profile)
    client = Client(profile)
    if args.command == 'ask':
        output = Path(args.output)
        if (output / 'submission.json').exists():
            raise ValueError('Output already contains a submission; use watch with its run_id or a new directory.')
        payload = json.loads(Path(args.input_json).read_text()) if args.input_json else {}
        payload['content'] = args.question
        write_private(output / 'submission.json', {'state': 'preparing', 'input': payload}, exclusive=True)
        conversation = args.conversation
        if conversation is None:
            conversation = client.request('POST', '/api/chat/conversations', {'project_key': profile['project_key']})['id']
        # Persist intent before POST. A network failure must not silently submit a duplicate.
        submission = {'conversation_id': conversation, 'state': 'sending', 'input': payload}
        write_private(output / 'submission.json', submission)
        accepted = client.request('POST', f'/api/chat/conversations/{conversation}/messages', payload)
        write_private(output / 'submission.json', {**submission, 'state': 'accepted', **accepted})
        return client.watch(accepted['run_id'], output, max(1, args.wait), max(0.1, args.poll), args.debug)
    if args.command == 'watch':
        return client.watch(args.run, args.output, max(1, args.wait), max(0.1, args.poll), args.debug)
    paths = {
        'check': lambda: ('GET', '/api/chat/me', None),
        'conversations': lambda: ('GET', '/api/chat/conversations', None),
        'sources': lambda: ('GET', '/api/chat/live-sources?project_key=' + urllib.parse.quote(profile['project_key']), None),
        'document': lambda: ('GET', f'/api/chat/documents/{args.id}/preview', None),
        'tool-source': lambda: ('GET', f'/api/chat/conversations/{args.conversation}/messages/{args.message}/tool-sources/{args.execution}', None),
        'snapshot': lambda: ('GET', f'/api/chat/runs/{args.run}', None),
        'cancel': lambda: ('POST', f'/api/chat/runs/{args.run}/cancel', {}),
        'reconcile': lambda: ('POST', f'/api/chat/runs/{args.run}/reconcile', {}),
        'continue': lambda: ('POST', f'/api/chat/runs/{args.run}/continue', {'logical_extension': args.logical, 'physical_extension': args.physical}),
        'messages': lambda: ('GET', f'/api/chat/conversations/{args.conversation}/messages', None),
        'debug': lambda: ('GET', f'/api/chat/conversations/{args.conversation}/debug-transcript', None),
        'revoke': lambda: ('DELETE', '/api/chat/token', None),
    }
    print(json.dumps(client.request(*paths[args.command]()), ensure_ascii=False, indent=2))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, OSError, RuntimeError, urllib.error.URLError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(2)
