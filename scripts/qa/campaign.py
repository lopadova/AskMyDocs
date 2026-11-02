#!/usr/bin/env python3
"""Prepare a private fixed corpus and verified-price manifest; never run inference."""
import argparse
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import sys
import urllib.parse
import urllib.request

SPEC = importlib.util.spec_from_file_location('chat_api', Path(__file__).with_name('chat-api.py'))
chat = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(chat)


def freeze(history, conversations, output):
    """Only the first user message enters inputs. Historical answers are not an oracle."""
    output = Path(output)
    if output.exists():
        raise ValueError('Corpus directory already exists; refusing to replace fixed prompts.')
    if len(conversations) != 10 or len(set(conversations)) != 10:
        raise ValueError('Exactly ten distinct source conversations are required.')
    cases, originals = [], []
    for position, conversation in enumerate(conversations, 1):
        messages = chat.read_private(Path(history) / f'{conversation}.json')
        users = [m for m in messages if m.get('role') == 'user']
        if not users or not isinstance(users[0].get('content'), str) or not users[0]['content'].strip():
            raise ValueError('Source conversation has no first user message.')
        case = f'CASE-{position:02d}'
        first = users[0]['content']
        cases.append({'id': case, 'first_sha256': hashlib.sha256(first.encode()).hexdigest(), 'min_turns': 3})
        originals.append((case, first, conversation))
    output.mkdir(parents=True, mode=0o700)
    (output / 'inputs').mkdir(mode=0o700)
    for case, first, _ in originals:
        path = output / 'inputs' / f'{case}.txt'
        path.touch(mode=0o600)
        path.write_text(first)
    chat.write_private(output / 'index.json', {'schema_version': 1, 'cases': cases}, exclusive=True)
    # Kept outside inputs. Empty expectations MUST be reviewed against original
    # documents / API records, never copied from a previous assistant answer.
    chat.write_private(output / 'oracle-review.json', {'schema_version': 1, 'cases': [
        {'id': case, 'source_conversation': conversation, 'status': 'needs_original_source_review',
         'original_source_refs': [], 'expected_facts': [], 'forbidden_inferences': []}
        for case, _, conversation in originals]}, exclusive=True)
    return hashlib.sha256((output / 'index.json').read_bytes()).hexdigest()


def get_catalog(url):
    request = urllib.request.Request(url, headers={'Accept': 'application/json', 'User-Agent': 'AskMyDocs-QA-manifest/1'})
    # No auth and no redirects; only the fixed provider's read-only catalog.
    with urllib.request.build_opener(chat.NoRedirect()).open(request, timeout=20) as response:
        return json.load(response)


def verified_price(data):
    endpoints = data.get('data', {}).get('endpoints', [])
    if not endpoints:
        raise ValueError('No verifiable provider endpoint prices; no manifest generated.')
    inputs, outputs = [], []
    for endpoint in endpoints:
        pricing = endpoint.get('pricing', {})
        try:
            parsed = {key: Decimal(str(value)) for key, value in pricing.items()}
        except (InvalidOperation, TypeError):
            raise ValueError('Invalid provider tariff.') from None
        if any(not value.is_finite() or value < 0 for value in parsed.values()):
            raise ValueError('Unknown or negative provider tariff.')
        if 'prompt' not in parsed or 'completion' not in parsed:
            raise ValueError('Missing input/output tariff; zero is never inferred.')
        if any(value != 0 for key, value in parsed.items() if key not in ('prompt', 'completion', 'input_cache_read', 'input_cache_write')):
            raise ValueError('Additional billing dimensions require explicit review.')
        inputs.append(max(parsed['prompt'], parsed.get('input_cache_read', Decimal(0)), parsed.get('input_cache_write', Decimal(0))))
        outputs.append(parsed['completion'])
    return float(max(inputs) * 1_000_000), float(max(outputs) * 1_000_000)


def manifest(index, sources, groups, reserve, output):
    index, output = Path(index), Path(output)
    if output.exists():
        raise ValueError('Manifest already exists; refusing to replace verified prices.')
    corpus = chat.read_private(index)
    if len(corpus.get('cases', [])) != 10:
        raise ValueError('Fixed corpus must contain ten cases.')
    allowed = {}
    for kind, models in groups.items():
        for model in models:
            if not re.fullmatch(r'[A-Za-z0-9_.:-]+/[A-Za-z0-9_./:-]+', model):
                raise ValueError('Invalid provider-native model ID.')
            source = 'https://openrouter.ai/api/v1/models/' + urllib.parse.quote(model, safe='/') + '/endpoints'
            data = get_catalog(source)
            input_price, output_price = verified_price(data)
            paths = {'text': ['/api/v1/chat/completions', '/api/v1/responses'],
                     'embedding': ['/api/v1/embeddings'], 'decision': ['/api/alpha/decisions']}[kind]
            if kind == 'decision' and output_price != 0:
                raise ValueError('Decision output is unbounded and non-free; inference is blocked.')
            response_models = sorted({model, data.get('data', {}).get('id', model)})
            previous = allowed.get(model, {})
            allowed[model] = {'paths': sorted(set(paths + previous.get('paths', []))),
                'input_per_million': input_price, 'output_per_million': output_price,
                'output_reserve_tokens': reserve, 'response_models': response_models,
                'price_source': source, 'price_observed_at': datetime.now(timezone.utc).isoformat()}
    if not allowed:
        raise ValueError('Explicit unchanged runtime models are required.')
    value = {'schema_version': 1, 'budget_usd': 10, 'max_calls': 1000,
        'corpus_sha256': hashlib.sha256(index.read_bytes()).hexdigest(), 'allowed_models': allowed,
        'live_sources': chat.read_private(sources)}
    chat.write_private(output, value, exclusive=True)
    return value


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    corpus = commands.add_parser('freeze')
    corpus.add_argument('--history', required=True)
    corpus.add_argument('--conversations', nargs=10, type=int, required=True)
    corpus.add_argument('--output', required=True)
    prepare = commands.add_parser('manifest')
    prepare.add_argument('--index', required=True)
    prepare.add_argument('--sources', required=True, help='Private JSON with exact api/mcp allowlists')
    for kind in ('text', 'embedding', 'decision'):
        prepare.add_argument('--' + kind + '-model', action='append', default=[])
    prepare.add_argument('--output-reserve-tokens', type=int, default=16384)
    prepare.add_argument('--output', required=True)
    args = parser.parse_args(argv)
    if args.command == 'freeze':
        print(json.dumps({'corpus_sha256': freeze(args.history, args.conversations, args.output), 'dialogs': 10, 'paid_calls': 0}))
    else:
        value = manifest(args.index, args.sources, {'text': args.text_model, 'embedding': args.embedding_model,
            'decision': args.decision_model}, args.output_reserve_tokens, args.output)
        print(json.dumps({'models': sorted(value['allowed_models']), 'budget_usd': 10, 'paid_calls': 0}))
    return 0


if __name__ == '__main__':
    try:
        sys.exit(main())
    except (ValueError, OSError) as error:
        print(str(error), file=sys.stderr)
        sys.exit(2)
