"""Finch (FINC) behaviour table per seed: opening, revenue growth, margins, ROE, P/E, price path, ratings, stream mix."""
import glob, json, math, statistics as st, sys

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
for path in sorted(glob.glob(f'runs/{arm}-s*.json')):
    d = json.load(open(path))
    o, q = d['opening'], d['q']
    if not q:
        print(path, 'no quarters'); continue
    cap0 = o['px'] * o['sh']
    print(f"seed {d['seed']}: open cap ${cap0/1e9:.1f}B ({100*cap0/o['board']:.2f}% of board ${o['board']/1e12:.1f}T), "
          f"P/B {o['px']/o['bvps']:.2f}, rev ${o['rev']/1e9:.1f}B, eq ${o['eq']/1e9:.1f}B, debt ${o['debt']/1e9:.1f}B, {o['rating']}")
    yrs = q[-1]['t'] - q[0]['t']
    g = (q[-1]['rev'] / q[0]['rev']) ** (1 / yrs) - 1 if yrs > 0 and q[0]['rev'] > 0 else float('nan')
    om = [r['om'] for r in q]
    roe = [4 * r['ni'] / r['eq'] for r in q if r['eq'] > 0]
    pe = [r['px'] / r['eps'] for r in q if r['eps'] > 0]
    loss = sum(1 for r in q if r['ni'] < 0)
    caps = [r['px'] * r['sh'] for r in q]
    peak, mdd = caps[0], 0.0
    for c in caps:
        peak = max(peak, c); mdd = min(mdd, c / peak - 1)
    lr = [math.log(caps[i] / caps[i - 1]) for i in range(1, len(caps)) if caps[i - 1] > 0 and caps[i] > 0]
    vol = st.pstdev(lr) * 2 if len(lr) > 2 else float('nan')
    tr = [r['streams'].get('trading', 0) / max(1, sum(r['streams'].values())) for r in q if r['streams']]
    ratings = sorted({r['rating'] for r in q})
    print(f"   rev growth {100*g:.1f}%/y, om {100*min(om):.0f}..{100*st.median(om):.0f}..{100*max(om):.0f}%, "
          f"ROE med {100*st.median(roe):.0f}% ({100*min(roe):.0f}..{100*max(roe):.0f}), loss q {loss}/{len(q)}, "
          f"P/E med {st.median(pe):.1f}, end cap ${caps[-1]/1e9:.0f}B, maxDD {100*mdd:.0f}%, q-vol {100*vol:.0f}%")
    print(f"   trading share {100*min(tr):.0f}..{100*max(tr):.0f}%, ratings {ratings}, reorgs {d['reorgs']}, "
          f"defaults {d['defaults']}, dead {d['dead']}, distress lines {len(d['distress'])}")
    # Revenue against the drivers: correlation of quarterly revenue change with VIX and the policy rate.
    dr = [math.log(q[i]['rev'] / q[i - 1]['rev']) for i in range(1, len(q)) if q[i - 1]['rev'] > 0 and q[i]['rev'] > 0]
    vx = [q[i]['vix'] for i in range(1, len(q))][:len(dr)]
    if len(dr) > 4:
        print(f"   corr(rev change, vol index) {st.correlation(dr, vx):+.2f}")
