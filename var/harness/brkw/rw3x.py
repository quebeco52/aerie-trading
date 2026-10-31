#!/usr/bin/env python3
"""rw3x.py <seeds> A B: extras for the float-floor change. Tender tax (end row `tender_tax`, nan where not logged),
tick cap-return vol of ALBT (end row), quarterly cap-return vol (report price x shares, annualised) of ALBT, CBIL,
ALCA from the OUT record, and the y25 lowest float on the held firms. Paired B - A. Final table only."""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, H

HELD = ['ALBT', 'CBIL', 'ALCA']


def qvol(rows):
    caps = [r['price'] * r['shares'] for r in rows if r.get('price') and r.get('shares')]
    rs = [math.log(b / a) for a, b in zip(caps, caps[1:]) if a > 0 and b > 0]
    if len(rs) < 3:
        return math.nan
    m = sum(rs) / len(rs)
    return math.sqrt(sum((x - m) ** 2 for x in rs) / (len(rs) - 1) * 4)


def one(arm, s):
    end, last = {}, {}
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'end':
            end = r
        elif r['kind'] == 'q':
            last = r
    o = {'tender tax $bn': end['tender_tax'] / 1e9 if 'tender_tax' in end else math.nan}
    o['tender tax/value'] = end['tender_tax'] / end['tender_value'] if 'tender_tax' in end and end['tender_value'] else math.nan
    o['tick vol ALBT'] = end['vol'].get('ALBT') or math.nan
    out = json.loads(open(H / f'{arm}-{s}.jsonl').readline())
    for t in HELD:
        o[f'qvol {t}'] = qvol(out['final'][t]['q'])
    fl = [h['fl'] for h in (last.get('held') or {}).values() if isinstance(h, dict) and h.get('fl') is not None and not h.get('dead')]
    o['held min float y25'] = min(fl) if fl else math.nan
    return o


def main():
    ss, (A, B) = seeds(sys.argv[1]), sys.argv[2:4]
    r = {a: {s: one(a, s) for s in ss} for a in (A, B)}
    print(f'extras, seeds {sys.argv[1]}; mean {A} | {B}; {B} per seed; paired {B}-{A}: d (se) t n')
    for k in r[A][ss[0]]:
        a = [r[A][s][k] for s in ss]
        b = [r[B][s][k] for s in ss]
        ma = sum(a) / len(a)
        mb = sum(b) / len(b)
        per = ' '.join(f'{x:.3g}' for x in b)
        d = paired(a, b) if not any(math.isnan(x) for x in a + b) else None
        print(f'{k:22s} {ma:9.3g} {mb:9.3g}  {per:30s} {fmt(d) if d else "-"}')


if __name__ == '__main__':
    main()
