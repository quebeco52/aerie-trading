#!/usr/bin/env python3
"""Round 2d: lenders (CommercialBankBusinessModel subclasses) exempt from the ICR<1 refinancing refusal.

Arms (same mt_srand(seed), working tree each; seeds 1-16):
  R2C = runs/r2c/after-*  (ICR<1 refusal applied to STRK / TALN / POOL)
  R2D = runs/r2d/after-*  (ICR<1 refusal skipped for every lender; spread closure and CCC floor unchanged)
Replay pair (both arms on the macro path recorded by a live R2C-tree run, runs/r2d/rec-*):
  RC = runs/r2d/rc-*  (R2C tree)        RD = runs/r2d/rd-*  (working tree)
python3 analyse_r2d.py > round2d_seeds1-16.out   (replay pair first, then the live pair)
"""
import glob, json, re, sys
import math, statistics as st
import analyse as A
import analyse_r2 as R
import analyse_r2c as C

SEEDS = range(1, 17)
FOCUS = ('STRK', 'TALN', 'POOL')


def ms(xs, d=2):
    return R.ms(xs, d)


def extra(rec, t):
    q = rec['final'][t]['q']
    o = rec['opening'][t]
    wd = [x['wd'] for x in q]
    cash = [x['cash'] for x in q]
    roe_q = C.fin([4 * x['ni'] / x['eq'] for x in q if x['eq'] > 0])
    rolls = R.asdict(rec.get('rolls')).get(t, [])
    return {
        'wd_end': q[-1]['wd'] / 1e9, 'wd_mean': st.mean(wd) / 1e9, 'wd_end_open': q[-1]['wd'] / o['wd'] if o['wd'] > 0 else float('nan'),
        'wd_eq_mean': st.mean(x['wd'] / x['eq'] for x in q if x['eq'] > 0), 'wd_eq_max': max(x['wd'] / x['eq'] for x in q if x['eq'] > 0),
        'wd_ta_end': 100 * q[-1]['wd'] / q[-1]['ta'] if q[-1]['ta'] > 0 else float('nan'),
        'eq_end_open': q[-1]['eq'] / o['eq'],
        'cash_end': q[-1]['cash'] / 1e9, 'cash_mean': st.mean(cash) / 1e9, 'cash_ta_mean': 100 * st.mean(x['cash'] / x['ta'] for x in q if x['ta'] > 0),
        'roe_q_mean': 100 * st.mean(roe_q),
        'icr_lt1_rolled': sum(1 for r in rolls if r['ok'] and r['icr'] < 1.0),
        'rolls': len(rolls),
    }


def row(label, a, b, d=2):
    seeds = [s for s in SEEDS if s in a and s in b]
    dd = [b[s] - a[s] for s in seeds if a[s] is not None and b[s] is not None and math.isfinite(a[s]) and math.isfinite(b[s])]
    per = ' '.join(f'{x:+.{max(d - 1, 0)}f}' for x in dd)
    print(f'{label:42s} {ms([a[s] for s in seeds], d):>16s} {ms([b[s] for s in seeds], d):>16s} {ms(dd, d):>16s}  {len(dd):2d}  [{per}]')


def hdr(title):
    print(f'\n{title}\n{"quantity":42s} {"r2c":>16s} {"r2d":>16s} {"r2d-r2c":>16s}   n  per-seed r2d-r2c (seeds 1..16)')


def load(d, arm):
    out = {}
    for f in glob.glob(f'{A.H}/runs/{d}/{arm}-*.jsonl'):
        m = re.search(rf'/{arm}-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def compare(a, b, title):
    seeds = [s for s in SEEDS if s in a and s in b]
    same = [s for s in seeds if a[s]['macro_q'] == b[s]['macro_q']]
    r0 = b[seeds[0]]
    print(f'\n\n===== {title}: seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), {r0["years"]} y, tpy {r0["tpy"]}, replay {r0["replay"]}; '
          f'macro path identical in both arms on {len(same)}/{len(seeds)} seeds')
    print('Quarterly ROE = 4 x NI / equity at the report; per-seed mean/p5/p1 then averaged over seeds.')
    for t in FOCUS:
        ta = {s: C.tail_stats(a[s], t) for s in seeds}
        tb = {s: C.tail_stats(b[s], t) for s in seeds}
        xa = {s: extra(a[s], t) for s in seeds}
        xb = {s: extra(b[s], t) for s in seeds}
        hdr(t)
        g = lambda d, k: {s: d[s][k] for s in seeds}
        for k, lab, d in [('refused', 'refinancing refusals / seed', 2), ('refused_seed', 'share of seeds with >= 1 refusal', 2)]:
            row(lab, g(ta, k), g(tb, k), d)
        for k, lab, d in [('icr_lt1_rolled', 'rolls with ICR<1 refinanced / seed', 2), ('rolls', 'maturity rolls / seed', 1),
                          ('wd_end', 'wholesale debt y20, $B', 1), ('wd_mean', 'wholesale debt mean, $B', 1),
                          ('wd_end_open', 'wholesale debt y20 / opening', 2), ('wd_eq_mean', 'wholesale debt / equity mean', 2),
                          ('wd_eq_max', 'wholesale debt / equity max', 2), ('wd_ta_end', 'wholesale debt / assets y20, %', 1),
                          ('eq_end_open', 'equity y20 / opening', 2),
                          ('cash_end', 'cash (treasury) y20, $B', 1), ('cash_mean', 'cash mean, $B', 1), ('cash_ta_mean', 'cash / assets mean, %', 1),
                          ('roe_q_mean', 'quarterly ROE mean, %', 1)]:
            row(lab, g(xa, k), g(xb, k), d)
        for k, lab, d in [('roe_q_p5', 'quarterly ROE p5, %', 1), ('roe_q_p1', 'quarterly ROE p1, %', 1), ('roe_ttm_mean', 'TTM ROE mean, %', 1),
                          ('roe_ttm_p5', 'TTM ROE p5, %', 1), ('eq_min_open', 'min equity / opening', 3), ('eq_dd', 'max book-equity drawdown, %', 1),
                          ('neg_q', 'loss quarters / seed', 2), ('shut_lines', 'shut-out lines / seed', 2), ('rep_amt', 'principal repaid when refused, $B', 2),
                          ('sf_n', 'unfunded shortfalls / seed', 2), ('sf_amt', 'unfunded shortfall, $B', 2), ('emerg_n', 'emergency debt issues / seed', 2),
                          ('emerg_amt', 'emergency debt, $B', 2), ('revolver_lines', 'revolver draw lines / seed', 2),
                          ('equity_rescue_lines', 'emergency equity lines / seed', 2), ('dead', 'failures / seed', 2), ('reorg', 'reorganisations / seed', 2),
                          ('defaults', 'payment-default audits / seed', 2), ('pdef_q', 'quarters in payment default / seed', 2)]:
            row(lab, g(ta, k), g(tb, k), d)
        for name, d in (('r2c', ta), ('r2d', tb)):
            rs = [r for s in seeds for r in d[s]['reasons']]
            why, rt = {}, {}
            for r in rs:
                why[r['why']] = why.get(r['why'], 0) + 1
                rt[r['rt']] = rt.get(r['rt'], 0) + 1
            pooled = [r for s in seeds for r in d[s]['roe_q']]
            extra_txt = (f'; reasons {dict(sorted(why.items(), key=lambda kv: -kv[1]))}; rating {dict(sorted(rt.items()))}; '
                         f'max spread {max(r["spr"] for r in rs):.3f}') if rs else ''
            print(f'  {name}: refusals {len(rs)}{extra_txt}; pooled qROE p5 {100 * A.pct(pooled, 0.05):.1f} p1 {100 * A.pct(pooled, 0.01):.1f}; '
                  f'failed seeds {[s for s in seeds if d[s]["dead"]]}')
    # board-wide
    hdr('BOARD')
    ba = {s: R.board_stats(a[s]) for s in seeds}
    bb = {s: R.board_stats(b[s]) for s in seeds}
    for k, lab in [('deaths', 'bankruptcies / seed (all firms)'), ('reorgs', 'reorganisations / seed'), ('pay_def_firms', 'firms with payment-default audits / seed'),
                   ('shut_all', 'shut-out lines / seed (all firms)'), ('nonfinite', 'non-finite samples')]:
        row(lab, {s: ba[s][k] for s in seeds}, {s: bb[s][k] for s in seeds}, 2)
    ref = lambda rec: sum(1 for v in R.asdict(rec.get('rolls')).values() for r in v if not r['ok'])
    row('refusals / seed, six tapped lenders', {s: ref(a[s]) for s in seeds}, {s: ref(b[s]) for s in seeds}, 2)
    for name, recs in (('r2c', a), ('r2d', b)):
        deaths = {}
        for s in seeds:
            for k in recs[s]['dead']:
                deaths.setdefault(k, []).append(s)
        allr = [(t, r) for s in seeds for t, v in R.asdict(recs[s].get('rolls')).items() for r in v if not r['ok']]
        by = {}
        for t, r in allr:
            by[t] = by.get(t, 0) + 1
        print(f'  {name}: deaths {deaths}; refusals by ticker {by}')


def main():
    rec, live_c = load('r2d', 'rec'), load('r2c', 'after')
    strip = lambda r: {k: v for k, v in r.items() if k not in ('secs', 'bank_model_file')}
    ok = [s for s in rec if s in live_c and strip(rec[s]) == strip(live_c[s])]
    print(f'R2C tree reproduction: live rec-* identical to runs/r2c/after-* (all fields but wall time / model path) on {len(ok)}/{len(rec)} seeds')
    rc, rd = load('r2d', 'rc'), load('r2d', 'rd')
    if rc and rd:
        compare(rc, rd, 'REPLAY PAIR (r2c = R2C tree, r2d = working tree, same recorded macro path)')
    compare(live_c, load('r2d', 'after'), 'LIVE PAIR (r2c = runs/r2c/after, r2d = runs/r2d/after, each on its own live macro)')


if __name__ == '__main__':
    main()
