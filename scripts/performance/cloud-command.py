#!/usr/bin/env python3
"""Print one portable Laravel Cloud Commands command; does not execute it."""
import argparse
import base64
import pathlib
import zlib

parser = argparse.ArgumentParser(description=__doc__)
parser.add_argument('--run', type=int, default=186)
args = parser.parse_args()
if args.run < 1:
    parser.error('--run must be positive')
source = pathlib.Path(__file__).with_name('cloud-snapshot.php').read_bytes()
assert source.startswith(b'<?php\n')
source = source[len(b'<?php\n'):]
compressor = zlib.compressobj(wbits=-15)
packed = compressor.compress(source) + compressor.flush()
assert zlib.decompress(packed, wbits=-15) == source
payload = base64.b64encode(packed).decode('ascii')
print('php -r \'eval(gzinflate(base64_decode("' + payload + '")));\' -- ' + str(args.run))
