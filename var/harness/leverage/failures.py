#!/usr/bin/env python3
"""failures.py <arm>...: list every bankruptcy and reorganisation per arm: ticker, seed, year, last non-D rating."""
import glob, json, os, re, sys

H = os.path.dirname(os.path.abspath(__file__))


def last_rating(fd, year):
    r = '?'
    for q in fd.get('q', []):
        if q['t'] <= year and q.get('rating') not in (None, 'D'):
            r = q['rating']
    return r


for arm in sys.argv[1:]:
    rows = []
    for p in glob.glob(f'{H}/runs/{arm}-*.jsonl'):
        s = int(re.search(rf'{arm}-(\d+)\.jsonl$', p).group(1))
        rec = json.loads(open(p).readline())
        op = json.load(open(p.replace('.jsonl', '.open.json')))
        for t, y in (rec.get('dead') or {}).items():
            rows.append((s, y, t, 'bankrupt', last_rating(rec['final'].get(t, {}), y), op.get(t, {}).get('industry', '')))
        for e in rec.get('reorgs', []):
            rows.append((s, e['year'], e['t'], 'reorg', last_rating(rec['final'].get(e['t'], {}), e['year']),
                         op.get(e['t'], {}).get('industry', '')))
    print(f'-- {arm}: {len(rows)} failures')
    for s, y, t, k, r, ind in sorted(rows):
        print(f'   seed {s:>2} year {y:6.2f} {t:<5} {k:<8} rating {r:<4} {ind}')
