#!/usr/bin/env python3
"""rating_decompose.py <arm> [<arm>...]: what moves each credit rating, from the RATING_OUT review rows (run.sh R arms).

Per review the row carries the terms CreditRatingAgency::evaluateRating() takes the minimum of: the grade the distance to
default reads at the old rating (`ddr`, hysteresis included), the leverage anchor (`an`), the Altman zone (`zn`: Distress
caps at CCC, Grey at BB) and book equity (`eq` <= 0 caps at CCC); `def` is a payment default or a filing (D).
A change is attributed to the binding term(s) that moved in its direction since the firm's previous review (for a
downgrade the terms binding after it, for an upgrade the ones that bound before it); none moved = `catch-up`, the
one-notch clamp still walking toward a target set earlier.

Prints per arm: changes per firm-year (all, and letter-to-letter excluding D), reversals (a move followed by one the
other way) within a year and within a quarter, the cause split, the anchor's own quarter-to-quarter changes per
firm-year, and the firms with the most changes. Population: every rated firm-quarter after the seed review.
"""
import glob, json, os, re, sys
from collections import Counter, defaultdict

H = os.path.dirname(os.path.abspath(__file__))
RANK = {'AAA': 7, 'AA': 6, 'A': 5, 'BBB': 4, 'BB': 3, 'B': 2, 'CCC': 1, 'D': 0}


def terms(r):
    t = {}
    if r.get('ddr') in RANK:
        t['dd'] = RANK[r['ddr']]
    if r.get('an') in RANK:
        t['anchor'] = RANK[r['an']]
    zn = r.get('zn')
    if zn == 'Distress':
        t['altman'] = 1
    elif zn == 'Grey':
        t['altman'] = 3
    else:
        t['altman'] = 7
    t['neg_equity'] = 1 if r.get('eq', 1.0) <= 0.0 else 7
    return t


def load(path):
    by = defaultdict(list)
    for line in open(path):
        r = json.loads(line)
        by[r['k']].append(r)
    for k in by:
        by[k].sort(key=lambda r: r['t'])
    return by


def analyse(path):
    by = load(path)
    years = max(r['t'] for rs in by.values() for r in rs)
    firm_years = 0.0
    moves, letter_moves, causes, rev_y, rev_q, anchor_moves = 0, 0, Counter(), 0, 0, 0
    per_firm = Counter()
    for k, rs in by.items():
        firm_years += rs[-1]['t'] - rs[0]['t'] + 0.25
        prev = None
        last_move = None  # (t, direction)
        for r in rs:
            if prev is not None and prev.get('an') != r.get('an'):
                anchor_moves += 1
            o, n = r['o'], r['n']
            if o != n:
                moves += 1
                per_firm[k] += 1
                d = 1 if RANK[n] > RANK[o] else -1
                if 'D' not in (o, n):
                    letter_moves += 1
                if last_move is not None and last_move[1] != d:
                    gap = r['t'] - last_move[0]
                    rev_y += gap <= 1.0 + 1e-6
                    rev_q += gap <= 0.25 + 1e-6
                last_move = (r['t'], d)
                if r.get('def') or n == 'D' or o == 'D':
                    causes['default/cure'] += 1
                else:
                    now = terms(r)
                    before = terms(prev) if prev is not None else now
                    if d < 0:
                        target = min(now.values())
                        bind = [x for x in now if now[x] == target]
                        moved = [x for x in bind if now[x] < before.get(x, 7)]
                    else:
                        target = min(before.values())
                        bind = [x for x in before if before[x] == target]
                        moved = [x for x in bind if now.get(x, 7) > before[x]]
                    causes['+'.join(sorted(moved)) if moved else 'catch-up'] += 1
            prev = r
    return dict(firm_years=firm_years, moves=moves, letter=letter_moves, rev_y=rev_y, rev_q=rev_q,
                causes=causes, anchor=anchor_moves, per_firm=per_firm, years=years)


if __name__ == '__main__':
    for arm in sys.argv[1:]:
        files = sorted(glob.glob(f'{H}/runs/{arm}-*.rating.jsonl'), key=lambda p: int(re.search(r'-(\d+)\.rating', p).group(1)))
        print(f'== {arm}: {len(files)} seeds')
        tot = Counter()
        firms = Counter()
        for p in files:
            s = int(re.search(r'-(\d+)\.rating', p).group(1))
            a = analyse(p)
            fy = a['firm_years']
            print(f"seed {s:3d}: changes/firm-yr {a['moves'] / fy:.3f} letter (ex D) {a['letter'] / fy:.3f}  reversals <=1y {a['rev_y']} <=1q {a['rev_q']}"
                  f"  anchor moves/firm-yr {a['anchor'] / fy:.3f}  causes {dict(a['causes'].most_common())}")
            tot.update(a['causes'])
            firms.update(a['per_firm'])
        n = sum(tot.values())
        print('cause shares: ' + ', '.join(f'{c} {v / max(1, n):.0%}' for c, v in tot.most_common()))
        print('most changes (all seeds): ' + ', '.join(f'{k} {v}' for k, v in firms.most_common(12)))
