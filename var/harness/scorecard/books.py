"""Opening-books check for the operating rows that build their own books (ops77.OPS77, non-financial): cash/assets and
net D/E medians at years 0/1/5/20, share change by year 5, covenant breaches in years 1-3, opening P/E, and
champion+king breaks (streak >= 25) with the firm at its cash floor. Paired against the first arm.
Cash floor: the target rate k = cash / max(4 x quarterly revenue, equity) on the first row of the reference arm (books
open at the treasury target there), applied per firm and seed in every arm; "at floor" = cash <= 1.2 x k x base (the
liquidity buffer: nothing to deploy). An estimate: the row does not carry the engine's target.
Usage (from this directory): python3 books.py <arm_a> <arm_b> [--ref <arm>] [--list]
"""
import os
import statistics as st
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, os.path.join(os.path.dirname(os.path.abspath(__file__)), '..', 'lib'))
from payout import load, at  # noqa: E402
from ops77 import OPS77  # noqa: E402
from stats import paired, fmt  # noqa: E402

YEARS = (0, 1, 5, 20)
BUFFER = 1.20


def ops(run):
    return [tk for tk in OPS77 if tk in run['meta'] and not run['meta'][tk].get('fin')]


def base(r):
    return max(4 * r['rev'], r['eq'], 1e7)


def measures(run, ref, detail):
    out = {}
    tks = ops(run)
    out['firms'] = len(tks)
    for y in YEARS:
        rows = [(run['final'][tk]['q'][0] if y == 0 and run['final'][tk]['q'] else at(run['final'][tk]['q'], y)) for tk in tks]
        rows = [r for r in rows if r and r['ta'] > 0 and r['eq'] > 0]
        out[f'cash/assets y{y}'] = st.median(r['cash'] / r['ta'] for r in rows)
        out[f'net D/E y{y}'] = st.median((r['debt'] - r['cash']) / r['eq'] for r in rows)
    ch = []
    for tk in tks:
        q = run['final'][tk]['q']
        a, b = q[0], at(q, 5)
        if a and b and a['shares'] > 0:
            ch.append(b['shares'] / a['shares'] - 1)
    out['shares y5/y0-1 median'] = st.median(ch)
    out['shares down >2% by y5'] = sum(c < -0.02 for c in ch)
    out['shares up >2% by y5'] = sum(c > 0.02 for c in ch)
    nb, fq, nh = set(), 0, set()
    for tk in tks:
        for r in run['final'][tk]['q']:
            if 1 <= r['t'] <= 3 + 1e-6:
                if r.get('covL') and r.get('ndE') is not None and r['ndE'] > r['covL']:
                    nb.add(tk)
                    fq += 1
                if r.get('head') is False:
                    nh.add(tk)
    out['covenant breach firms y1-3'] = len(nb)
    out['covenant breach firm-qtrs y1-3'] = fq
    out['no-headroom firms y1-3'] = len(nh)
    out['GRIP breach y1-3'] = int('GRIP' in nb)
    detail.setdefault('breach', []).append((run['seed'], sorted(nb)))
    pe = [r['price'] / r['eps'] for r in (run['final'][tk]['q'][0] for tk in tks) if r.get('eps') and r['eps'] > 0]
    out['opening P/E (77, median)'] = st.median(pe)
    allpe = [f['q'][0]['price'] / f['q'][0]['eps'] for f in run['final'].values() if f['q'] and (f['q'][0].get('eps') or 0) > 0]
    out['opening P/E (board, median)'] = st.median(allpe)
    # Champion+king breaks and the cash floor.
    k = {}
    for tk in tks:
        r0 = ref['final'][tk]['q'][0]
        k[tk] = r0['cash'] / base(r0)
    brk, floor = 0, 0
    for tk in tks:
        q = run['final'][tk]['q']
        for i in range(1, len(q)):
            sa, sb = q[i - 1].get('streak') or 0, q[i].get('streak') or 0
            if sa >= 25 and sb < sa:
                brk += 1
                rr = q[i - 1]
                ratio = rr['cash'] / (k[tk] * base(rr)) if k[tk] > 0 else float('inf')
                floor += ratio <= BUFFER
                detail.setdefault('champ', []).append((run['seed'], tk, round(q[i]['t'], 2), sa, round(ratio, 2)))
    out['champion+king breaks (77)'] = brk
    out['  of them at cash floor'] = floor
    return out


def main():
    args = sys.argv[1:]
    ref_arm = args[args.index('--ref') + 1] if '--ref' in args else None
    listing = '--list' in args
    arms = [a for i, a in enumerate(args) if not a.startswith('--') and (i == 0 or args[i - 1] != '--ref')]
    ref_arm = ref_arm or arms[-1]
    refs = load(ref_arm)
    details = {a: {} for a in arms}
    data = {a: {s: measures(r, refs[s], details[a]) for s, r in load(a).items() if s in refs} for a in arms}
    print(f'{"":34}' + ''.join(f'{a:>10}' for a in arms) + ''.join(f'   {b}-{arms[0]} (se) t n' for b in arms[1:]))
    for key in data[arms[0]][min(data[arms[0]])]:
        line = f'  {key:32}' + ''.join(f'{st.mean(d[s][key] for s in d):10.3f}' for d in data.values())
        for b in arms[1:]:
            line += '   ' + fmt(paired({s: data[arms[0]][s][key] for s in data[arms[0]]}, {s: data[b][s][key] for s in data[b]}))
        print(line)
    if listing:
        for a in arms:
            print(f'{a} breach firms y1-3: ' + '; '.join(f's{s} {" ".join(t)}' for s, t in details[a]['breach']))
            print(f'{a} champion+king breaks (seed, firm, year, streak, cash / target): '
                  + '; '.join(f's{c[0]} {c[1]} y{c[2]} {c[3]} {c[4]}' for c in details[a].get('champ', [])))


if __name__ == '__main__':
    main()
