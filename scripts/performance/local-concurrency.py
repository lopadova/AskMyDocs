#!/usr/bin/env python3
"""Bounded SQL load simulation. No real jobs, AI, imports or remote databases."""
import argparse
import json
import math
import pathlib
import subprocess
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--php', required=True)
parser.add_argument('--app-root', required=True)
parser.add_argument('--fanout', type=int, default=4, choices=range(1, 5))
args = parser.parse_args()
leaf = pathlib.Path(__file__).with_name('local-vector-benchmark.php')
output = {'mode': 'local SQL concurrency simulation; 3 stored-vector SELECTs per child',
          'limitations': 'No application-job latency or provider work; tiny warm local corpus; sample p95 is descriptive only.', 'batches': []}
for shape in ['known_fix', 'projection_experiment']:
    for jobs in [1, 2, 4]:
        children = []
        try:
            gate = time.monotonic_ns() + 3_000_000_000
            for _ in range(jobs * args.fanout):
                children.append(subprocess.Popen([args.php, str(leaf), '--leaf', str(gate), shape],
                    cwd=args.app_root, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True))
            results = []
            for child in children:
                stdout, _ = child.communicate(timeout=20)
                result = json.loads(stdout)
                if child.returncode or 'error_type' in result:
                    raise RuntimeError('Local leaf failed; exception text suppressed.')
                results.append(result)
            samples = sorted(value for result in results for value in result['sql_ms'])
            events = sorted([(r['start_ns'], 1) for r in results] + [(r['end_ns'], -1) for r in results])
            active = peak = 0
            for _, delta in events:
                active += delta
                peak = max(peak, active)
            output['batches'].append({'shape': shape, 'simulated_jobs': jobs, 'branches_per_job': args.fanout,
                'physical_children': len(children), 'peak_overlapping_query_loops': peak, 'queries': len(samples),
                'p50_sql_ms': samples[math.ceil(len(samples) * .5)-1], 'p95_sql_ms': samples[math.ceil(len(samples) * .95)-1],
                'max_start_lateness_ms': max(r['start_lateness_ms'] for r in results), 'errors': 0})
        finally:
            # Only our own benchmark child processes are ever stopped.
            for child in children:
                if child.poll() is None:
                    child.kill()
                    child.wait()
print(json.dumps(output, indent=2))
