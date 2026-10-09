"""Power A/B: rec5 (no power index; BIRD's gas 'crush') vs rec6 (wholesale power index; merchant P x Q), 16 live seeds x 20y."""
import glob, json, math, statistics as st, sys
from collections import Counter
H = sys.argv[1] if len(sys.argv) > 1 else '.'
ARMS = {'rec5': 'control', 'rec6': 'power'}


def load(arm):
    return {int(f.rsplit('-', 1)[1].split('.')[0]): json.loads(open(f).readline()) for f in glob.glob(f'{H}/runs2/{arm}-*.jsonl')}


def corr(x, y):
    mx, my = st.mean(x), st.mean(y)
    den = math.sqrt(sum((a - mx) ** 2 for a in x) * sum((b - my) ** 2 for b in y))
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / den if den > 0 else float('nan')


def slope(x, y):
    mx, my = st.mean(x), st.mean(y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sum((a - mx) ** 2 for a in x)


def pct(v, p):
    s = sorted(v); return s[min(len(s) - 1, max(0, int(round(p * (len(s) - 1)))))]


data = {a: load(a) for a in ARMS}
seeds = sorted(set.intersection(*[set(d) for d in data.values()]))
print(f'paired seeds: {len(seeds)}')
for ticker in ['BIRD', 'WADE']:
    print(f'\n{ticker:34s}' + ''.join(f'{v:>12s}' for v in ARMS.values()))
    rows = {}
    for a in ARMS:
        oms, losses, n, cg, cp, dead, el = [], 0, 0, [], [], 0, []
        for s in seeds:
            f = data[a][s]['final'][ticker]
            dead += 1 if f['dead'] else 0
            q = [x for x in f['q'] if x['rev'] > 0]
            oms += [x['om'] for x in q]; losses += sum(1 for x in q if x['ebit'] < 0); n += len(q)
            cg.append(corr([x['gas'] for x in q], [x['om'] for x in q]))
            if all('power' in x and x['power'] > 0 for x in q):
                cp.append(corr([x['power'] for x in q], [x['om'] for x in q]))
                # year-on-year log revenue on year-on-year log power: the merchant share of revenue, if P x Q holds
                dr = [math.log(q[i]['rev'] / q[i - 4]['rev']) for i in range(4, len(q))]
                dp = [math.log(q[i]['power'] / q[i - 4]['power']) for i in range(4, len(q))]
                el.append(slope(dp, dr))
        rows[a] = {
            'operating margin p5': pct(oms, .05), 'operating margin p50': st.median(oms), 'operating margin p95': pct(oms, .95),
            'loss-quarter share': losses / n, 'corr(margin, gas) median': st.median(cg),
            'corr(margin, power) median': st.median(cp) if cp else float('nan'),
            'YoY revenue elasticity to power': st.median(el) if el else float('nan'), 'deaths': dead,
        }
    for k in rows['rec5']:
        print(f'  {k:32s}' + ''.join(f'{rows[a][k]:12.3f}' for a in ARMS))

print()
for a, label in ARMS.items():
    deaths = Counter(t for s in seeds for t in (data[a][s]['dead'] or {}))
    reorgs = Counter(x['t'] for s in seeds for x in (data[a][s]['reorgs'] or []))
    print(f'{label:8s} market deaths {sum(deaths.values())} {dict(deaths)}  reorgs {sum(reorgs.values())} {dict(reorgs)}')

# The macro power index as the firms saw it (quarterly EMA at report time), against the EIA four-hub targets.
qs = [x for s in seeds for x in data['rec6'][s]['final']['BIRD']['q'] if x.get('power', 0) > 0]
lp = [math.log(x['power']) for x in qs]; lg = [math.log(x['gas']) for x in qs]
print(f"\nrec6 power EMA at report: mean {st.mean(x['power'] for x in qs):.1f} | log sd {st.pstdev(lp):.3f} | log-log slope on gas {slope(lg, lp):.3f} | corr {corr(lg, lp):+.2f}")
