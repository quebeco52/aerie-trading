"""WELL / HONY / DOVE behaviour table per seed: opening, growth, margins, ROE, P/E, price path, mix and drivers."""
import glob, json, math, statistics as st, sys

arm = sys.argv[1] if len(sys.argv) > 1 else 'v1'
for path in sorted(glob.glob(f'runs/{arm}-s*.json')):
    d = json.load(open(path))
    print(f"=== seed {d['seed']}  reorgs {d['reorgs']} defaults {d['defaults']} dead {d['dead']}")
    for t in ('WELL', 'HONY', 'DOVE'):
        o, q = d['opening'][t], d['q'].get(t, [])
        if not q:
            print(t, 'no quarters'); continue
        cap0 = o['px'] * o['sh']
        print(f" {t}: open cap ${cap0/1e9:.0f}B ({100*cap0/o['board']:.2f}% of board), P/B {o['px']/o['bvps']:.2f}, "
              f"P/E {o['px']/o['eps'] if o['eps'] > 0 else float('nan'):.1f}, rev ${o['rev']/1e9:.1f}B, eq ${o['eq']/1e9:.0f}B, "
              f"float ${o['float']/1e9:.0f}B, htm {o['htm']:.2f}, {o['rating']}")
        yrs = q[-1]['t'] - q[0]['t']
        g = (q[-1]['rev'] / q[0]['rev']) ** (1 / yrs) - 1 if yrs > 0 and q[0]['rev'] > 0 else float('nan')
        om = [r['om'] for r in q]
        roe = [4 * r['ni'] / r['eq'] for r in q if r['eq'] > 0]
        pe = [r['px'] / r['eps'] for r in q if r['eps'] > 0]
        pb = [r['px'] / r['bvps'] for r in q if r['bvps'] > 0]
        loss = sum(1 for r in q if r['ni'] < 0)
        caps = [r['px'] * r['sh'] for r in q]
        peak, mdd = caps[0], 0.0
        for c in caps:
            peak = max(peak, c); mdd = min(mdd, c / peak - 1)
        lr = [math.log(caps[i] / caps[i - 1]) for i in range(1, len(caps)) if caps[i - 1] > 0 and caps[i] > 0]
        vol = st.pstdev(lr) * 2 if len(lr) > 2 else float('nan')
        ratings = sorted({r['rating'] for r in q})
        print(f"   rev g {100*g:.1f}%/y, om {100*min(om):.0f}..{100*st.median(om):.0f}..{100*max(om):.0f}%, "
              f"ROE {100*min(roe):.0f}..{100*st.median(roe):.0f}..{100*max(roe):.0f}%, loss q {loss}/{len(q)}, "
              f"P/E med {st.median(pe) if pe else float('nan'):.1f}, P/B {min(pb):.2f}..{st.median(pb):.2f}..{max(pb):.2f}, "
              f"end cap ${caps[-1]/1e9:.0f}B, maxDD {100*mdd:.0f}%, vol {100*vol:.0f}%, ratings {ratings}, "
              f"distress {len(d['distress'].get(t, []))}")
        mix = {}
        for r in q:
            tot = sum(r['streams'].values()) or 1
            for k, v in r['streams'].items():
                mix.setdefault(k, []).append(v / tot)
        print('   mix ' + ', '.join(f"{k} {100*min(v):.0f}..{100*max(v):.0f}%" for k, v in mix.items()))
        if t in ('WELL', 'DOVE'):
            fe = [r['float'] / r['eq'] for r in q if r['eq'] > 0]
            fr = [r['float'] / (4 * r['rev']) for r in q if r['rev'] > 0]
            # Equity's move against what it would have been with the default 40% held to maturity.
            alt = [(r['mark'] * 0.6 - r['rec']) / r['eq'] for r in q if r['eq'] > 0]
            rec = [r['rec'] / r['eq'] for r in q if r['eq'] > 0]
            cy = [r['cy'] for r in q]
            print(f"   float/eq {min(fe):.1f}..{max(fe):.1f}, float/rev {min(fr):.1f}..{max(fr):.1f}, carrying y "
                  f"{100*min(cy):.1f}..{100*max(cy):.1f}%, recognised mark/eq {100*min(rec):+.0f}..{100*max(rec):+.0f}% "
                  f"(at 40% HTM it would add {100*min(alt):+.0f}..{100*max(alt):+.0f}%)")
        if t == 'WELL':
            ad = [r['y30'] - r['ann_tr'] for r in q if r['ann_tr'] > 0]
            print(f"   30y {100*min(r['y30'] for r in q):.1f}..{100*max(r['y30'] for r in q):.1f}%, over trend "
                  f"{100*min(ad):+.2f}..{100*max(ad):+.2f}pp")
        if t == 'HONY':
            dev = [r['ins'] / r['ins_tr'] - 1 for r in q if r['ins_tr'] > 0]
            lv = [r['ins'] for r in q]
            fid = [r['rate'] / r['neutral'] for r in q if r['neutral'] > 0]
            print(f"   insurance rate level {min(lv):.2f}..{max(lv):.2f}, over trend {100*min(dev, default=0):+.0f}.."
                  f"{100*max(dev, default=0):+.0f}%, policy/neutral {min(fid):.2f}..{max(fid):.2f}")
