#!/usr/bin/env python3
"""rw2.py <seeds> A B C: means per arm for analyse/payx/anchor quantities + allocator spread and record-date
dividend ratio; paired C - B and C - A. Final table only."""
import json, math, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parent))
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, brkw, board, H
import payx, anchor


def extra(arm, s):
    rep, end = [], {}
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'rep':
            rep.append(r)
        elif r['kind'] == 'end':
            end = r
    o = {}
    sp = [r['spread'] for r in rep if r.get('spread')]
    o['alloc true-hurdle'] = sum(x['true'] + x['hold'] - x['hurdle'] for x in sp) / len(sp) if sp else math.nan
    o['alloc hold ret'] = sum(x['hold'] for x in sp) / len(sp) if sp else math.nan
    o['alloc spread<0 share'] = sum(1 for x in sp if x['true'] + x['hold'] < x['hurdle']) / len(sp) if sp else math.nan
    pr = end.get('paid_on_blocks_record')
    o['recv/paid@record'] = (end['collected'] + end['receivable']) / pr if pr else math.nan
    return o


def one(arm, s):
    o = {**brkw(arm, s), **board(arm, s)[0], **payx.one(arm, s), **anchor.one(arm, s), **extra(arm, s)}
    return o


def main():
    ss, (A, B, C) = seeds(sys.argv[1]), sys.argv[2:5]
    r = {a: {s: one(a, s) for s in ss} for a in (A, B, C)}
    print(f'BRKW, seeds {sys.argv[1]}; mean {A} | {B} | {C}; {C} per seed; paired {C}-{B} and {C}-{A}: d (se) t n')
    for k in r[C][ss[0]]:
        if k.startswith('_'):
            continue
        vals = {a: [r[a][s].get(k, math.nan) for s in ss] for a in (A, B, C)}
        if any(not isinstance(x, (int, float)) for v in vals.values() for x in v):
            continue
        m = {a: sum(v) / len(v) for a, v in vals.items()}
        per = ' '.join(f'{x:.3g}' for x in vals[C])
        okb = not any(math.isnan(x) for x in vals[B] + vals[C])
        oka = not any(math.isnan(x) for x in vals[A] + vals[C])
        print(f'{k:22s} {m[A]:9.3g} {m[B]:9.3g} {m[C]:9.3g}  {per:30s} '
              f'{fmt(paired(vals[B], vals[C])) if okb else "-":28s} | {fmt(paired(vals[A], vals[C])) if oka else "-"}')
    for a in (B, C):
        print(f'{a}: min-float firm y25 ' + ' '.join(f's{s}:{r[a][s]["_minfloat_t"]}' for s in ss))
        print(f'{a}: acquisitions ' + '; '.join(f's{s}: {", ".join(r[a][s]["_acq_ind"]) or "-"}' for s in ss))


if __name__ == '__main__':
    main()
