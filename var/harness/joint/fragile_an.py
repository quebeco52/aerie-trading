#!/usr/bin/env python3
"""fragile_an.py <seeds> <ticker,...> <arm>...: per arm and named firm, the seeds it defaulted in (with the defaults
entry), its worst rating, peak ND/E, share-count rises >2% (raises) and first loss-of-A year; one line per firm-arm."""
import json, sys

H = 'var/harness/joint/runs'
a, b = sys.argv[1].split('-'); seeds = range(int(a), int(b) + 1)
tks = sys.argv[2].split(','); arms = sys.argv[3:]
ORDER = ['AAA', 'AA', 'A', 'BBB', 'BB', 'B', 'CCC', 'CC', 'C', 'D']
for tk in tks:
    for arm in arms:
        dft, worst, ndE, raises, ends = [], [], [], 0, []
        for s in seeds:
            r = json.loads(open(f'{H}/{arm}-{s}.jsonl').readline())
            d = r.get('defaults') or {}
            if tk in d:
                dft.append(f's{s}:{json.dumps(d[tk] if isinstance(d, dict) else "y")[:40]}')
            q = r['final'][tk]['q']
            rs = [x['rating'] for x in q if x.get('rating') in ORDER]
            w = max(rs, key=ORDER.index) if rs else '?'
            worst.append(w)
            ndE.append(max(x.get('ndE') or 0 for x in q))
            raises += sum(1 for x, y in zip(q, q[1:]) if x['shares'] and y['shares'] / x['shares'] > 1.02)
            ends.append(q[-1].get('rating'))
        print(f'{tk:5s} {arm:6s} defaults {dft or "-"}  worst rating {",".join(worst)}  '
              f'peak ndE {min(ndE):.1f}..{max(ndE):.1f}  share rises>2% {raises}  end {",".join(map(str, ends))}')
