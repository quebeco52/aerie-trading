#!/usr/bin/env python3
"""c_an.py <seeds> <armA> <armB>: analyst-consensus comparison of two unpaired arms, per business model.
Per model and arm: reports (n), EPS beat share (reps > ceps; mean over seeds of the model's share, se over seeds),
revenue surprise against published consensus revenue (rev / crev - 1: median, and median within-firm sd), and the
revenue beat share (rev > crev). Then report-tick price moves from ticks.csv: r^2 profile around the report tick,
the sd of the report-window log move (env WIN, offsets from the report tick, default "0,1"), and the announcement share
of each firm-year's tick variance. Wall seconds per run from the jsonl. Year 1 skipped; needs runs/ticker_models.json
and the `crev` report field (JointHarnessTest from 10-20)."""
import collections, csv, json, math, os, statistics as st, sys

H = os.path.dirname(os.path.abspath(__file__))
seeds = []
for part in sys.argv[1].split(','):
    a, _, b = part.partition('-')
    seeds += list(range(int(a), int(b or a) + 1))
arms = sys.argv[2:4]
WIN = [int(x) for x in os.environ.get('WIN', '0,1').split(',')]
models = {k: v.replace('BusinessModel', '') for k, v in json.load(open(f"{H}/runs/ticker_models.json")).items()}


def se(x):
    return st.stdev(x) / math.sqrt(len(x)) if len(x) > 1 else float('nan')


R = {}
for arm in arms:
    per = collections.defaultdict(lambda: collections.defaultdict(list))  # model -> key -> list
    seedbeat = collections.defaultdict(lambda: collections.defaultdict(list))  # model -> seed -> beats
    board, rboard, secs, prof, moves, shares = [], [], [], collections.defaultdict(list), [], []
    seed_sd, seed_sh = [], []
    for s in seeds:
        d = json.loads(open(f"{H}/runs/{arm}-{s}.jsonl").readline())
        secs.append(d['secs'])
        tpy = d['tpy']
        rep_ticks = {}
        for t, v in d['final'].items():
            q = [r for r in v['q'] if r['t'] > 1.0 and r.get('rev', 0) > 0 and 'reps' in r and r['ceps'] != 0]
            rep_ticks[t] = [int(round(r['t'] * tpy)) for r in v['q'] if r['t'] > 1.0]
            if len(q) < 8:
                continue
            if 'crev' not in q[0]:
                sys.exit(f"{arm}-{s}: no crev field (run predates the harness change)")
            m = models.get(t, '?')
            b = [1.0 if r['reps'] > r['ceps'] else 0.0 for r in q]
            su = [r['rev'] / r['crev'] - 1 for r in q if r['crev'] > 0]
            per[m]['n'].append(len(q)); per[m]['su'] += su; per[m]['susd'].append(st.pstdev(su))
            per[m]['rb'] += [1.0 if x > 0 else 0.0 for x in su]
            seedbeat[m][s] += b
            board += b; rboard += [1.0 if x > 0 else 0.0 for x in su]
        # report-tick moves from the tick tape
        n_mv, n_sh = len(moves), len(shares)
        with open(f"{H}/runs/{arm}-{s}.ticks.csv") as f:
            rd = csv.reader(f)
            hdr = next(rd)
            rows = [[float(x) if x not in ('', 'nan') else float('nan') for x in r] for r in rd]
        col = {h[:-2]: i for i, h in enumerate(hdr) if h.endswith('_p')}
        tick_ix = {int(r[0]): i for i, r in enumerate(rows)}
        for t, ci in col.items():
            ni = hdr.index(t + '_n')
            ret = {}
            for i in range(1, len(rows)):
                p0, p1, n0, n1 = rows[i - 1][ci], rows[i][ci], rows[i - 1][ni], rows[i][ni]
                if not (p0 > 0 and p1 > 0 and n0 > 0 and n1 > 0) or abs(n1 / n0 - 1) > 0.2:
                    continue
                ret[int(rows[i][0])] = math.log(p1 / p0)
            if len(ret) < tpy:
                continue
            var_all = st.mean(x * x for x in ret.values())
            if var_all <= 0:
                continue
            rt = [k for k in rep_ticks.get(t, []) if k in tick_ix]
            for k in rt:
                for o in range(-2, 4):
                    if k + o in ret:
                        prof[o].append(ret[k + o] ** 2 / var_all)
                w = [ret.get(k + o) for o in WIN]
                if None not in w:
                    moves.append(sum(w))
            rts = set(k + o for k in rt for o in WIN)
            by_year = collections.defaultdict(lambda: [0.0, 0.0])
            for k, x in ret.items():
                y = k // tpy
                if y == 0:
                    continue
                by_year[y][0] += x * x
                if k in rts:
                    by_year[y][1] += x * x
            shares += [a / b for b, a in by_year.values() if b > 0]
        seed_sd.append(st.pstdev(moves[n_mv:])); seed_sh.append(st.mean(shares[n_sh:]))
    R[arm] = dict(per=per, seedbeat=seedbeat, board=board, rboard=rboard, secs=secs, prof=prof, moves=moves, shares=shares, seed_sd=seed_sd, seed_sh=seed_sh)

print(f"seeds {sys.argv[1]}, arms {arms[0]} | {arms[1]} (unpaired). surprise = rev / published consensus rev - 1")
for a in arms:
    r = R[a]
    print(f"{a:6s}: board EPS beat {100 * st.mean(r['board']):.1f}%, revenue beat {100 * st.mean(r['rboard']):.1f}%, "
          f"wall {st.mean(r['secs']):.0f} s/run (sd {st.pstdev(r['secs']):.0f})")
print(f"\n{'model':28s} {'n A':>5s} {'n B':>5s} | {'EPS beat A (se)':>15s} {'B (se)':>12s} | {'su50 A':>7s} {'B':>7s} | "
      f"{'susd A':>6s} {'B':>6s} | {'rbeat A':>7s} {'B':>5s}  flag")
flagged = []
for m in sorted(set(R[arms[0]]['per']) | set(R[arms[1]]['per'])):
    cells, fl = [], []
    for a in arms:
        p, sb = R[a]['per'].get(m), R[a]['seedbeat'].get(m, {})
        if not p:
            cells.append(None)
            continue
        sh = [st.mean(v) for v in sb.values() if v]
        cells.append(dict(n=sum(p['n']), b=100 * st.mean(sh), bse=100 * se(sh), su=100 * st.median(p['su']),
                          susd=100 * st.median(p['susd']), rb=100 * st.mean(p['rb'])))
        if not 60 <= 100 * st.mean(sh) <= 85:
            fl.append(a)
    A, B = cells
    if A is None or B is None:
        print(f"{m:28s} missing in one arm")
        continue
    print(f"{m:28s} {A['n']:5d} {B['n']:5d} | {A['b']:6.1f} ({A['bse']:4.1f}) {B['b']:6.1f} ({B['bse']:4.1f}) | "
          f"{A['su']:+7.2f} {B['su']:+7.2f} | {A['susd']:6.2f} {B['susd']:6.2f} | {A['rb']:7.1f} {B['rb']:5.1f}  {','.join(fl)}")
print(f"\nreport-tick r^2 / mean tick r^2 by offset from the report tick; report window = offsets {WIN}")
for a in arms:
    r = R[a]
    pf = ' '.join(f"{o:+d}:{st.mean(r['prof'][o]):6.1f}" for o in sorted(r['prof']))
    sh = r['shares']
    print(f"{a:6s}: {pf} | sd window move {100 * st.pstdev(r['moves']):.2f}% (n {len(r['moves'])}), "
          f"announcement share of firm-year tick variance mean {100 * st.mean(sh):.1f}% median {100 * st.median(sh):.1f}% "
          f"(no-news share {100 * 4 * len(WIN) / 360:.1f}%); over seeds: sd move {100 * st.mean(r['seed_sd']):.2f} "
          f"(se {100 * se(r['seed_sd']):.2f}), share {100 * st.mean(r['seed_sh']):.2f} (se {100 * se(r['seed_sh']):.2f})")
