#!/usr/bin/env python3
"""Benchmark pre-fetched images with local Tesseract; never downloads image data."""
import argparse
import hashlib
import json
import pathlib
import statistics
import subprocess
import time

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--image', action='append', required=True, help='LABEL=local/path')
parser.add_argument('--output', required=True)
parser.add_argument('--runs', type=int, default=3)
parser.add_argument('--psm', type=int, default=6)
args = parser.parse_args()
if args.runs < 1:
    parser.error('--runs must be positive')
version = subprocess.run(['tesseract', '--version'], capture_output=True, check=True).stdout.decode().splitlines()[0]
results = []
for spec in args.image:
    label, path = spec.split('=', 1)
    raw = pathlib.Path(path).read_bytes()  # Outside timing; also verify tested bytes.
    trials = []
    for _ in range(args.runs):
        start = time.perf_counter()
        proc = subprocess.run(['tesseract', str(pathlib.Path(path).resolve()), 'stdout', '-l', 'eng', '--psm', str(args.psm)], capture_output=True, timeout=120, check=True)
        trials.append({'elapsed_ms': round((time.perf_counter() - start) * 1000, 2), 'text': proc.stdout.decode('utf-8')})
    result = {'label': label, 'engine': version, 'language': 'eng', 'psm': args.psm, 'sha256': hashlib.sha256(raw).hexdigest(), 'median_ms': statistics.median(t['elapsed_ms'] for t in trials), 'text': trials[0]['text'], 'trials': trials, 'api_fee_usd': 0, 'timing_scope': 'Fresh process including model load and image decode; excludes image download.'}
    results.append(result)
    print(label, result['median_ms'], 'ms')
with open(args.output, 'x') as stream:
    json.dump(results, stream, indent=2)
