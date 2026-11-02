import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('campaign', Path(__file__).resolve().parents[3] / 'scripts/qa/campaign.py')
campaign = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(campaign)


class CampaignPreparationTest(unittest.TestCase):
    def test_freeze_preserves_first_prompt_and_separates_oracle_without_assistant_answers(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            history = root / 'history'
            for number in range(1, 11):
                campaign.chat.write_private(history / f'{number}.json', [
                    {'role': 'user', 'content': f'Caso sintetico {number}\nSeconda riga.'},
                    {'role': 'assistant', 'content': 'Previous answer is not truth'},
                    {'role': 'user', 'content': 'Adaptive old followup'}])
            output = root / 'frozen'
            digest = campaign.freeze(history, list(range(1, 11)), output)
            self.assertEqual(64, len(digest))
            self.assertEqual('Caso sintetico 1\nSeconda riga.', (output / 'inputs/CASE-01.txt').read_text())
            self.assertEqual(0o600, (output / 'inputs/CASE-01.txt').stat().st_mode & 0o777)
            self.assertNotIn('Previous answer', ''.join(p.read_text() for p in output.rglob('*') if p.is_file()))
            self.assertEqual([], json.loads((output / 'oracle-review.json').read_text())['cases'][0]['expected_facts'])
            with self.assertRaises(ValueError): campaign.freeze(history, list(range(1, 11)), output)

    def test_prices_require_explicit_numeric_tariffs_and_accept_verified_zero(self):
        for pricing in ({'prompt': '0'}, {'prompt': '-1', 'completion': '0'},
                        {'prompt': 'NaN', 'completion': '0'}, {'prompt': '0', 'completion': '0', 'request': '.1'}):
            with self.assertRaises(ValueError): campaign.verified_price({'data': {'endpoints': [{'pricing': pricing}]}})
        self.assertEqual((0, 0), campaign.verified_price({'data': {'endpoints': [{'pricing': {'prompt': '0', 'completion': '0'}}]}}))
        self.assertEqual((2, 3), campaign.verified_price({'data': {'endpoints': [
            {'pricing': {'prompt': '.000001', 'completion': '.000003'}},
            {'pricing': {'prompt': '.000002', 'completion': '.000001'}}]}}))

    def test_manifest_only_reads_catalog_and_blocks_unbounded_paid_decision_output(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            campaign.chat.write_private(root / 'index.json', {'cases': [{'id': str(i)} for i in range(10)]})
            campaign.chat.write_private(root / 'sources.json', {'api': ['api:synthetic'], 'mcp': []})
            catalog = {'data': {'id': 'test/model', 'endpoints': [{'pricing': {'prompt': '.000001', 'completion': '0'}}]}}
            identities = {'data': [{'id': 'test/model', 'canonical_slug': 'test/model-2026-11'}]}
            with patch.object(campaign, 'get_catalog', side_effect=[identities, catalog]) as fetch:
                result = campaign.manifest(root / 'index.json', root / 'sources.json', {'decision': ['test/model']}, 16384, root / 'manifest.json')
            self.assertEqual(['https://openrouter.ai/api/v1/models?output_modalities=all',
                              'https://openrouter.ai/api/v1/models/test/model/endpoints'], [call.args[0] for call in fetch.call_args_list])
            self.assertEqual(10, result['budget_usd'])
            self.assertEqual(['/api/alpha/decisions'], result['allowed_models']['test/model']['paths'])
            self.assertEqual(['test/model', 'test/model-2026-11'], result['allowed_models']['test/model']['response_models'])
            catalog['data']['endpoints'][0]['pricing']['completion'] = '.000001'
            with patch.object(campaign, 'get_catalog', side_effect=[identities, catalog]), self.assertRaises(ValueError):
                campaign.manifest(root / 'index.json', root / 'sources.json', {'decision': ['test/model']}, 16384, root / 'blocked.json')
            self.assertFalse((root / 'blocked.json').exists())

    def test_missing_canonical_identity_cannot_create_a_partial_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            campaign.chat.write_private(root / 'index.json', {'cases': [{'id': str(i)} for i in range(10)]})
            campaign.chat.write_private(root / 'sources.json', {'api': [], 'mcp': []})
            catalog = {'data': {'id': 'test/model', 'endpoints': [{'pricing': {'prompt': '0', 'completion': '0'}}]}}
            with patch.object(campaign, 'get_catalog', side_effect=[{'data': []}, catalog]), self.assertRaises(ValueError):
                campaign.manifest(root / 'index.json', root / 'sources.json', {'text': ['test/model']}, 16384, root / 'manifest.json')
            self.assertFalse((root / 'manifest.json').exists())

    def test_long_context_and_time_prices_are_reserved_at_the_highest_tier_without_discount(self):
        prices = {'prompt': '.000001', 'completion': '.000002', 'discount': '.5', 'web_search': '.01',
                  'overrides': [{'min_prompt_tokens': 272000, 'prompt': '.000003', 'completion': '.000004'},
                                {'utc_start': 2300, 'utc_end': 100, 'utc_days': ['monday'],
                                 'input_cache_write': '.000005'}]}
        self.assertEqual((5, 4), campaign.verified_price({'data': {'endpoints': [{'pricing': prices}]}}))
        prices['overrides'].append({'min_prompt_tokens': 300000, 'unknown_billable_dimension': '1'})
        with self.assertRaises(ValueError): campaign.verified_price({'data': {'endpoints': [{'pricing': prices}]}})


if __name__ == '__main__':
    unittest.main()
