#!/usr/bin/env python3
"""Round 2c: the retail-default intercept (RETAIL_CREDIT_INTERCEPT = -0.06) on top of round 2.

Arms (each on its own live macro path, same mt_srand(seed)):
  BEFORE = HEAD c2f29f4 (runs/r2c/before-*, the tapped rerun; seeds 1-16 checked identical to runs/r2/before-*)
  AFTER  = working tree with the intercept (runs/r2c/after-*)
  PREV   = working tree without the intercept, round 2 (runs/r2/after-*, seeds 1-16 only)

Part 1 (seeds 1-16): round 2's tables with three columns. Part 2 (seeds 1-48): STRK and TALN tail.
"""
import glob, json, math, re, statistics as st, sys
import analyse as A
import analyse_r2 as R

H = A.H
LENDERS = R.LENDERS
IG = {'AAA', 'AA', 'A', 'BBB'}


def load(d, arm):
    out = {}
    for f in glob.glob(f'{H}/runs/{d}/{arm}-*.jsonl'):
        m = re.search(r'/(?:before|after)-(\d+)\.jsonl$', f)
        lines = open(f).read().strip().splitlines()
        if m and lines:
            out[int(m.group(1))] = json.loads(lines[-1])
    return out


def fin(xs):
    return [x for x in xs if x is not None and isinstance(x, (int, float)) and math.isfinite(x)]


def ms(xs, d=2):
    return R.ms(xs, d)


def diff(a, b, seeds):
    return [a[s] - b[s] for s in seeds if a.get(s) is not None and b.get(s) is not None
            and math.isfinite(a[s]) and math.isfinite(b[s])]


def row3(label, b, a, p, seeds, d=2):
    bb, aa = [b[s] for s in seeds], [a[s] for s in seeds]
    pp = [p[s] for s in seeds] if p else []
    cells = [ms(bb, d), ms(aa, d), ms(diff(a, b, seeds), d)]
    if p:
        cells += [ms(pp, d), ms(diff(a, p, seeds), d)]
    print(f'{label:44s} ' + ' '.join(f'{c:>17s}' for c in cells) + f'  {len(diff(a, b, seeds))}')


def hdr3(title, prev=True):
    cols = ['BEFORE c2f29f4', 'AFTER+intercept', 'AFTER-BEFORE'] + (['PREV (no int.)', 'AFTER-PREV'] if prev else [])
    print(f'\n{title}\n{"quantity":44s} ' + ' '.join(f'{c:>17s}' for c in cols) + '  n')


def part1(before, after, prev):
    seeds = sorted(set(before) & set(after) & set(prev) & set(range(1, 17)))
    print(f'PART 1: seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), 20 y, 360 tpy; macro moments drop first {R.BURN} quarters')
    mb, ma, mp = ({s: R.macro_stats(x[s]) for s in seeds} for x in (before, after, prev))
    hdr3('MACRO (quarterly samples)')
    for k, lab in [('rdr_mean', 'retail default EMA mean, %'), ('rdr_sd', 'retail default EMA sd, pp'), ('rdr_p95', 'retail default EMA p95, %'),
                   ('corr_resgap', 'corr(retail EMA, ln(res EMA / trend))'), ('corr_ugap', 'corr(retail EMA, u - NAIRU)'),
                   ('cdr_mean', 'corporate default EMA mean, %'), ('cdr_sd', 'corporate default EMA sd, pp')]:
        row3(lab, {s: mb[s][k] for s in seeds}, {s: ma[s][k] for s in seeds}, {s: mp[s][k] for s in seeds}, seeds, 3)
    rows = [('ttc', 'TTC, %'), ('nco_mean', 'mean NCO, % book'), ('nco_ttc', 'NCO / TTC'), ('win_excess', 'recession cost net of ordinary qtr, % book'),
            ('allow_peak', 'allowance peak, % book'), ('roe_mean', 'ROE TTM mean, %'), ('roe_p5', 'ROE TTM p5, %'),
            ('cet1_min', 'CET1 min, %'), ('dead', 'failures / seed'), ('reorg', 'reorganisations / seed'),
            ('defaults', 'payment-default audits / seed'), ('shut', 'bond-market shut-out lines / seed'), ('nim_mean', 'reported NIM mean, %'),
            ('d_nim', 'reported NIM steep - inverted, pp'), ('nonfinite', 'non-finite fields')]
    for t in LENDERS:
        lb, la, lp = ({s: R.lender_stats(x[s], t) for s in seeds} for x in (before, after, prev))
        hdr3(t)
        for k, lab in rows:
            if k in ('cet1_min', 'nim_mean', 'd_nim') and t not in R.BANKS:
                continue
            g = lambda d: {s: (d[s] or {}).get(k) for s in seeds}
            row3(lab, g(lb), g(la), g(lp), seeds, 3 if k in ('ttc', 'nco_mean', 'nco_ttc') else 2)
        for name, d in (('BEFORE', lb), ('AFTER', la), ('PREV', lp)):
            v = [x for x in d.values() if x]
            print(f'  {name}: NCO/TTC range {min(x["nco_ttc"] for x in v):.2f}..{max(x["nco_ttc"] for x in v):.2f}; '
                  f'failed seeds {[s for s in seeds if d[s] and d[s]["dead"]]}; shut-out seeds {sum(1 for s in seeds if d[s] and d[s]["shut"])}')
    bb, ba, bp = ({s: R.board_stats(x[s]) for s in seeds} for x in (before, after, prev))
    hdr3('BOARD')
    for k, lab in [('deaths', 'bankruptcies / seed (all firms)'), ('reorgs', 'reorganisations / seed'), ('pay_def_firms', 'firms with payment-default audits / seed'),
                   ('shut_all', 'bond-market shut-out lines / seed (all firms)'), ('nonfinite', 'non-finite macro/price/equity/cash samples')]:
        row3(lab, {s: bb[s][k] for s in seeds}, {s: ba[s][k] for s in seeds}, {s: bp[s][k] for s in seeds}, seeds, 2)


def nearest_q(q, t):
    best = min(range(len(q)), key=lambda i: abs(q[i]['t'] - t)) if q else None
    return q[best] if best is not None and abs(q[best]['t'] - t) < 0.13 else None


def tail_stats(rec, t):
    f = rec['final'][t]
    q = f['q']
    if not q:
        return None
    roe_q = fin([4 * x['ni'] / x['eq'] for x in q if x['eq'] > 0])
    eqs = [x['eq'] for x in q]
    peak, dd = eqs[0], 0.0
    for e in eqs:
        peak = max(peak, e)
        dd = min(dd, e / peak - 1 if peak > 0 else 0.0)
    eq0 = rec['opening'][t]['eq']
    rolls = R.asdict(rec.get('rolls')).get(t, [])
    refused = [r for r in rolls if not r['ok']]
    reasons = []
    for r in refused:
        why = []
        if r['spr'] >= 0.10:
            why.append('spread')
        if r['floor']:
            why.append('floor')
        if r['icr'] < 1.0:
            why.append('icr<1')
        x = nearest_q(q, r['t'])
        reasons.append({'why': '+'.join(why) or '?', 'rt': r['rt'], 'ig': r['rt'] in IG, 'icr': r['icr'], 'spr': r['spr'],
                        'nineg': (x['ni'] < 0) if x else None, 'sf': r['sf'], 'capt': r['capt']})
    em = R.asdict(rec.get('emerg')).get(t, [])
    ev = R.asdict(rec.get('events')).get(t, [])
    s = R.lender_stats(rec, t)
    return {
        'roe_q_p5': 100 * A.pct(roe_q, 0.05), 'roe_q_p1': 100 * A.pct(roe_q, 0.01), 'roe_q_min': 100 * min(roe_q),
        'roe_q': roe_q, 'roe_ttm_p5': s['roe_p5'], 'roe_ttm_mean': s['roe_mean'],
        'eq_min_open': min(eqs) / eq0 if eq0 > 0 else float('nan'), 'eq_dd': 100 * dd,
        'neg_q': sum(1 for x in q if x['ni'] < 0), 'neg_seed': 1 if any(x['ni'] < 0 for x in q) else 0,
        'shut_lines': sum(1 for e in ev if 'Shut out of the bond market' in e['d']),
        'refused': len(refused), 'refused_seed': 1 if refused else 0, 'reasons': reasons, 'n_rolls': len(rolls),
        'rep_amt': sum(r['rep'] for r in refused) / 1e9, 'rep_eq': 100 * sum(r['rep'] / r['eq'] for r in refused if r['eq'] > 0),
        'sf_n': sum(1 for r in rolls if r['sf'] > 0), 'sf_amt': sum(r['sf'] for r in rolls) / 1e9,
        'emerg_n': len(em), 'emerg_amt': sum(e['amt'] for e in em) / 1e9,
        'emerg_fn': [e['fn'] for e in em],
        'forced_lines': sum(1 for e in ev if 'penalty rates' in e['d']),
        'revolver_lines': sum(1 for e in ev if 'revolving credit' in e['d']),
        'equity_rescue_lines': sum(1 for e in ev if 'emergency stock offering' in e['d']),
        'rvd_q': sum(1 for x in q if x.get('rvd', 0) > 0),
        'pdef_q': sum(1 for x in q if x.get('pdef')),
        'dead': s['dead'], 'reorg': s['reorg'], 'defaults': s['defaults'],
    }


def part2(before, after):
    seeds = sorted(set(before) & set(after))
    print(f'\n\nPART 2: STRK / TALN tail, seeds {seeds[0]}-{seeds[-1]} (n={len(seeds)}), 20 y, 360 tpy, BEFORE c2f29f4 vs AFTER + intercept')
    print('Quarterly ROE = 4 x NI / equity at the report (annualised); per-seed quantiles then averaged; pooled = all seed-quarters.')
    for t in ('STRK', 'TALN'):
        tb = {s: tail_stats(before[s], t) for s in seeds}
        ta = {s: tail_stats(after[s], t) for s in seeds}
        hdr3(t, prev=False)
        for k, lab, d in [('roe_q_p5', 'quarterly ROE p5 (per seed), %', 1), ('roe_q_p1', 'quarterly ROE p1 (per seed), %', 1),
                          ('roe_q_min', 'quarterly ROE min (per seed), %', 1), ('roe_ttm_p5', 'TTM ROE p5, %', 1), ('roe_ttm_mean', 'TTM ROE mean, %', 1),
                          ('eq_min_open', 'min equity / opening equity', 3), ('eq_dd', 'max book-equity drawdown, %', 1),
                          ('neg_q', 'quarters with NI < 0 / seed', 2), ('neg_seed', 'share of seeds with any NI < 0', 2),
                          ('shut_lines', 'shut-out lines (> $0.5B repaid) / seed', 2), ('refused', 'refinancing refusals / seed (tap)', 2),
                          ('refused_seed', 'share of seeds with >= 1 refusal', 2), ('rep_amt', 'principal repaid in cash when refused, $B / seed', 2),
                          ('rep_eq', '  same, summed as % of equity at the time', 1), ('sf_n', 'unfunded shortfalls / seed', 2),
                          ('sf_amt', 'unfunded shortfall, $B / seed', 2), ('emerg_n', 'emergency debt issues / seed', 2),
                          ('emerg_amt', 'emergency debt, $B / seed', 2), ('forced_lines', "'penalty rates' borrow lines / seed", 2),
                          ('revolver_lines', 'revolver draw lines / seed', 2), ('rvd_q', 'quarters with revolver drawn / seed', 2),
                          ('equity_rescue_lines', 'emergency equity lines / seed', 2),
                          ('pdef_q', 'quarters in payment default / seed', 2), ('dead', 'failures / seed', 2),
                          ('reorg', 'reorganisations / seed', 2), ('defaults', 'payment-default audits / seed', 2)]:
            row3(lab, {s: tb[s][k] for s in seeds}, {s: ta[s][k] for s in seeds}, None, seeds, d)
        for name, d in (('BEFORE', tb), ('AFTER', ta)):
            pooled = [r for s in seeds for r in d[s]['roe_q']]
            rs = [r for s in seeds for r in d[s]['reasons']]
            why, rt = {}, {}
            for r in rs:
                why[r['why']] = why.get(r['why'], 0) + 1
                rt[r['rt']] = rt.get(r['rt'], 0) + 1
            ig = [r for r in rs if r['ig']]
            print(f'  {name}: pooled quarterly ROE p5 {100 * A.pct(pooled, 0.05):.1f}%, p1 {100 * A.pct(pooled, 0.01):.1f}% '
                  f'(n={len(pooled)}); refusals {len(rs)} in {sum(d[s]["refused_seed"] for s in seeds)} seeds')
            if rs:
                print(f'    reason {dict(sorted(why.items(), key=lambda kv: -kv[1]))}; rating {dict(sorted(rt.items()))}; '
                      f'investment grade {len(ig)}/{len(rs)}; IG with ICR<1 {sum(1 for r in ig if r["icr"] < 1)}; '
                      f'in a loss quarter {sum(1 for r in rs if r["nineg"])}/{len(rs)}; capital-target exempt {sum(1 for r in rs if r["capt"])}; '
                      f'ICR at refusal median {st.median(r["icr"] for r in rs):.2f}; spread max {max(r["spr"] for r in rs):.3f}')
            print(f'    failed seeds {[s for s in seeds if d[s]["dead"]]}; emergency callers '
                  f'{ {k: sum(fn.count(k) for fn in (d[s]["emerg_fn"] for s in seeds)) for k in ("processEmergencyBorrowing", "processRevolverDraw")} }')
    bb = {s: R.board_stats(before[s]) for s in seeds}
    ba = {s: R.board_stats(after[s]) for s in seeds}
    hdr3('BOARD (48 seeds)', prev=False)
    for k, lab in [('deaths', 'bankruptcies / seed (all firms)'), ('reorgs', 'reorganisations / seed'), ('pay_def_firms', 'firms with payment-default audits / seed'),
                   ('shut_all', 'bond-market shut-out lines / seed (all firms)'), ('nonfinite', 'non-finite samples')]:
        row3(lab, {s: bb[s][k] for s in seeds}, {s: ba[s][k] for s in seeds}, None, seeds, 3)
    for name, recs in (('BEFORE', before), ('AFTER', after)):
        deaths = {}
        for s in seeds:
            for k in recs[s]['dead']:
                deaths[k] = deaths.get(k, 0) + 1
        print(f'  {name}: deaths by ticker {dict(sorted(deaths.items()))}; total {sum(deaths.values())} in {len(seeds)} seeds')


def main():
    before, after, prev = load('r2c', 'before'), load('r2c', 'after'), load('r2', 'after')
    old_before = load('r2', 'before')
    strip = lambda q: [{k: v for k, v in x.items() if k not in ('rvd', 'pdef')} for x in q]
    same = [s for s in old_before if s in before and before[s]['macro_q'] == old_before[s]['macro_q'] and before[s]['dead'] == old_before[s]['dead']
            and all(strip(before[s]['final'][t]['q']) == strip(old_before[s]['final'][t]['q']) for t in LENDERS)]
    print(f'tap neutrality: r2c BEFORE identical to r2 BEFORE (macro path, six lender ledgers, deaths) on {len(same)}/{len([s for s in old_before if s in before])} seeds')
    if 'part2' not in sys.argv:
        part1(before, after, prev)
    if 'part1' not in sys.argv:
        part2(before, after)


if __name__ == '__main__':
    main()
