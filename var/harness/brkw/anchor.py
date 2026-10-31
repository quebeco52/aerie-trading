#!/usr/bin/env python3
"""anchor.py <seeds> A B: anchor-stake rework quantities for BRKW and its held firms, paired B - A. Final table only.

Reads the `held`, `nav_sop`, `dtax`, tender fields of the q rows, the NI split of the rep rows and the `end` row
(realised cap-return vol, dividends collected vs paid on BRKW's blocks, tenders). NAV = the engine's pricing NAV
(marked book + subsidiary revaluation in the working tree; marked book on HEAD). Years are from the run's start.
"""
import json, math, re, sys
from pathlib import Path
sys.path.insert(0, str(Path(__file__).resolve().parents[1] / 'lib'))
from stats import paired, fmt
from analyse import seeds, H

HELD = ['ALBT', 'CBIL', 'ALCA', 'KSTL', 'BIRD', 'ERNE']


def load(arm, s):
    q, rep, ev, end = [], [], [], {}
    for line in open(H / f'{arm}-{s}.brkw.jsonl'):
        r = json.loads(line)
        if r['kind'] == 'end':
            end = r
        else:
            {'q': q, 'rep': rep, 'ev': ev}.get(r['kind'], []).append(r)
    return q, rep, ev, end


def at(rows, y):
    return min(rows, key=lambda r: abs(r['t'] - y))


def one(arm, s):
    q, rep, ev, end = load(arm, s)
    o = {}
    navq = [r for r in q if r.get('nav_sop')]
    cn = [r['cash'] / (r['nav_sop'] * r['sh']) for r in navq]
    o['cash/NAVsop mean'] = sum(cn) / len(cn)
    o['cash/NAVsop y10'] = at(navq, 10)['cash'] / (at(navq, 10)['nav_sop'] * at(navq, 10)['sh'])
    o['cash/NAVsop y25'] = cn[-1]
    o['cash/NAVsop max'] = max(cn)
    cb = [r['cash'] / r['eq'] for r in rep if r['eq'] > 0]
    o['cash/book mean'] = sum(cb) / len(cb)
    o['cash/book y25'] = cb[-1]
    pn = [r['px'] / r['nav_sop'] for r in navq]
    o['P/NAV mean'] = sum(pn) / len(pn)
    o['P/NAV min'] = min(pn)
    o['P/NAV max'] = max(pn)
    o['P/NAV<0.5 share'] = sum(1 for x in pn if x < 0.5) / len(pn)
    for y in (5, 10, 25):
        h = at(q, y)['held']
        for t in HELD:
            o[f'frac {t} y{y}'] = h[t]['f'] if t in h else math.nan
    last = q[-1]['held']
    for t in HELD:
        o[f'float {t} y25'] = last[t]['fl'] if t in last else math.nan
    o['tenders n'] = end['tenders']
    o['tenders $bn'] = end['tender_value'] / 1e9
    rec = end['collected'] + end['receivable']
    o['div recv $bn'] = rec / 1e9
    o['div paid blocks $bn'] = end['paid_on_blocks'] / 1e9
    o['recv/paid'] = rec / end['paid_on_blocks'] if end['paid_on_blocks'] else math.nan
    ni = sum(r['ni'] for r in rep)
    dr = sum(r['divrec'] for r in rep)
    o['NI $bn/y'] = ni / 1e9 / 25
    o['EBIT/NI'] = sum(r['ebit'] for r in rep) / ni
    o['divrec/NI'] = dr / ni
    o['interest(ex div)/NI'] = (sum(r['int_inc'] for r in rep) - dr) / ni
    o['int exp/NI'] = sum(r['int_exp'] for r in rep) / ni
    o['tax/NI'] = sum(r['tax'] for r in rep) / ni
    dy = [4 * r['div'] / r['cap'] for r in rep if r['cap'] > 0]
    o['div yield'] = sum(dy) / len(dy)
    o['gw impair n'] = sum(1 for r in rep if r['gwi'] > 0)
    o['gw impair $bn'] = sum(r['gwi'] for r in rep) / 1e9
    dt = [r['dtax'] for r in q if r.get('dtax') is not None]
    for y in (5, 10, 25):
        o[f'stake dtax y{y} $bn'] = at(q, y)['dtax'] / 1e9 if dt else math.nan
    o['stake dtax min $bn'] = min(dt) / 1e9 if dt else math.nan
    o['stake dtax max $bn'] = max(dt) / 1e9 if dt else math.nan
    for t in ('BRKW', 'CBIL', 'ALCA', 'board'):
        o[f'vol {t}'] = end['vol'].get(t) or math.nan
    for t in ('CBIL', 'ALCA'):
        p0 = q[0]['held'][t]['px']
        for y in (10, 25):
            o[f'{t} px y{y}/y0'] = at(q, y)['held'][t]['px'] / p0
    o['board min float y25'] = q[-1]['min_float']
    o['_minfloat_t'] = q[-1]['min_float_t']
    o['_acq_ind'] = [m.group(1) for e in ev if e['ticker'] == 'BRKW'
                     for m in [re.search(r'taking it into ([^.]+?)\. ', e['desc'])] if m and ' executed a $' in e['desc']]
    return o


def main():
    ss, A, B = seeds(sys.argv[1]), sys.argv[2], sys.argv[3]
    ra = {s: one(A, s) for s in ss}
    rb = {s: one(B, s) for s in ss}
    print(f'BRKW anchor-stake quantities, {A} vs {B}, seeds {sys.argv[1]}; per seed A/B, paired B-A (se) t n')
    for k in ra[ss[0]]:
        if k.startswith('_'):
            continue
        a, b = [ra[s][k] for s in ss], [rb[s][k] for s in ss]
        per = '  '.join(f'{x:.3g}/{y:.3g}' for x, y in zip(a, b))
        ok = all(not math.isnan(x) for x in a + b)
        print(f'{k:22s} {per:64s} {fmt(paired(a, b)) if ok else ""}')
    for arm, r in ((A, ra), (B, rb)):
        print(f'{arm}: min-float firm y25 ' + ' '.join(f's{s}:{r[s]["_minfloat_t"]}' for s in ss))
        print(f'{arm}: acquisitions ' + '; '.join(f's{s}: {", ".join(r[s]["_acq_ind"]) or "-"}' for s in ss))


if __name__ == '__main__':
    main()
