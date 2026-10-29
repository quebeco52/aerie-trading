"""Rookery (ROOK) behaviour table per seed: opening, growth, margins, ROE, P/E, price path, stream mix and drivers."""
import glob, json, math, statistics as st, sys

arm = sys.argv[1] if len(sys.argv) > 1 else 'live'
for path in sorted(glob.glob(f'runs/{arm}-s*.json')):
    d = json.load(open(path))
    o, q = d['opening'], d['q']
    if not q:
        print(path, 'no quarters'); continue
    cap0 = o['px'] * o['sh']
    print(f"seed {d['seed']}: open cap ${cap0/1e9:.1f}B ({100*cap0/o['board']:.2f}% of board ${o['board']/1e12:.1f}T), "
          f"P/B {o['px']/o['bvps']:.2f}, rev ${o['rev']/1e9:.1f}B, eq ${o['eq']/1e9:.1f}B, debt ${o['debt']/1e9:.1f}B, "
          f"{o['rating']}, float {q[0]['float']}")
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
    share = [c / r['board'] for c, r in zip(caps, q) if r['board'] > 0]
    rev_board = [4 * r['rev'] / r['board'] for r in q if r['board'] > 0]
    ratings = sorted({r['rating'] for r in q})
    print(f"   rev growth {100*g:.1f}%/y, om {100*min(om):.0f}..{100*st.median(om):.0f}..{100*max(om):.0f}%, "
          f"ROE med {100*st.median(roe):.0f}% ({100*min(roe):.0f}..{100*max(roe):.0f}), loss q {loss}/{len(q)}, "
          f"P/E med {st.median(pe):.1f}, end cap ${caps[-1]/1e9:.0f}B, maxDD {100*mdd:.0f}%, q-vol {100*vol:.0f}%")
    print(f"   cap share of board {100*min(share):.2f}..{100*st.median(share):.2f}..{100*max(share):.2f}%, "
          f"annual rev / board cap {10000*st.median(rev_board):.1f}bp, ratings {ratings}, reorgs {d['reorgs']}, "
          f"defaults {d['defaults']}, dead {d['dead']}, distress lines {len(d['distress'])}")
    mix = {}
    for r in q:
        tot = sum(r['streams'].values()) or 1
        for k, v in r['streams'].items():
            mix.setdefault(k, []).append(v / tot)
    print('   mix ' + ', '.join(f"{k} {100*min(v):.0f}..{100*max(v):.0f}%" for k, v in mix.items()))
    to = [r['to'] for r in q]; tod = [r['to_dev'] for r in q]; wd = [r['w_dev'] for r in q]
    cr = [math.sqrt(r['cvar'] / r['cvar_tr']) - 1 for r in q if r['cvar_tr'] > 0]
    print(f"   turnover {min(to):.2f}..{st.median(to):.2f}..{max(to):.2f}/y, dev {100*min(tod):+.0f}..{100*max(tod):+.0f}%, "
          f"wealth dev {100*min(wd):+.0f}..{100*max(wd):+.0f}%, commodity vol/trend {100*min(cr, default=0):+.0f}..{100*max(cr, default=0):+.0f}%")
    eq = [r['streams'].get('equity_trading', 0) for r in q]
    de = [math.log(eq[i] / eq[i - 1]) for i in range(1, len(eq)) if eq[i - 1] > 0 and eq[i] > 0]
    vx = [q[i]['vix'] for i in range(1, len(q))][:len(de)]
    if len(de) > 4:
        print(f"   corr(equity fee change, vol index) {st.correlation(de, vx):+.2f}")
