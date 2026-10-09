"""One line per arm for the credit-boom round: python3 var/harness/boom_score.py <dir> <arm> [arm ...]

On CreditMacroProbeTest rows (fc_run.sh / boom_arms.sh), burn-in 10y:
  house: annual real house-price growth sd, AR1-3, corr and beta (%/pp) on the change in the gap
         US FHFA/CPI 1977-2019: sd 3.86, AR .71 .33 .00, corr(dgap) +.45 beta 1.16, corr(gap) +.46 beta .97, min -9.0%
  credit: year-end annual change in debt to income, sd and AR1 (US Q4 1977-2019: 3.66pp, .58); corr(3y dDTI, gap) (US +.51)
  cycle: 35y-window medians of mean, sd, share > +2%, share < -3% (CBO 1985-2019: -0.48, 1.62, 1%, 8.6%)
  tail: share of 35y windows with more than 4 quarters under -11% (at the clamp) and with 20+ quarters under -4%
  crises a century.
"""
import glob, json, math, statistics as st, sys

D = sys.argv[1]
BURN = 10.0


def corr(a, b):
    ma, mb = st.mean(a), st.mean(b)
    return sum((x - ma) * (y - mb) for x, y in zip(a, b)) / math.sqrt(sum((x - ma) ** 2 for x in a) * sum((y - mb) ** 2 for y in b))


def beta(y, x):
    mx, my = st.mean(x), st.mean(y)
    return sum((a - mx) * (b - my) for a, b in zip(x, y)) / sum((a - mx) ** 2 for a in x)


print(f"{'arm':14s} | {'house sd  AR1  AR2  AR3 cDg  bDg  p0.5':38s} | {'dDTI  AR1 lead':15s} | {'mean  sd  >+2  <-3':19s} | {'trap long':9s} | cr/c")
for arm in sys.argv[2:]:
    by = {}
    for p in glob.glob(f'{D}/{arm}-*.json'):
        for r in json.load(open(p)):
            by.setdefault(r['seed'], []).append(r)
    if not by:
        continue
    G, G1, G2, G3, DG = [], [], [], [], []
    DD, DD1, L3, LG = [], [], [], []
    win, trap, long_, crises, yrs = [], 0, 0, 0, 0.0
    for q in by.values():
        q = [r for r in q if r['t'] > BURN]
        yr = {}
        for r in q:
            yr.setdefault(math.ceil(r['t'] - 1e-9), []).append(r)
        ys = [v for k, v in sorted(yr.items()) if len(v) == 4]
        hp = [math.log(st.mean(r['resi'] for r in v)) for v in ys]
        gp = [100 * st.mean(r['gap_avg'] for r in v) for v in ys]
        g = [hp[i] - hp[i - 1] for i in range(1, len(hp))]
        G += g[3:]; G1 += g[2:-1]; G2 += g[1:-2]; G3 += g[:-3]
        DG += [gp[i] - gp[i - 1] for i in range(4, len(gp))]
        q4 = [v[-1]['dti'] for v in ys]
        dd = [q4[i] - q4[i - 1] for i in range(1, len(q4))]
        DD += dd[1:]; DD1 += dd[:-1]
        for t in range(12, len(q)):
            L3.append(q[t]['dti'] - q[t - 12]['dti']); LG.append(q[t]['gap_avg'])
        for w0 in range(0, len(q) - 140 + 1, 140):
            w = [100 * r['gap_avg'] for r in q[w0:w0 + 140]]
            n = len(w); m = sum(w) / n
            win.append((m, math.sqrt(sum((x - m) ** 2 for x in w) / n), sum(x > 2 for x in w) / n, sum(x < -3 for x in w) / n))
            if sum(1 for r in q[w0:w0 + 140] if r['gap'] < -0.11) > 4:
                trap += 1
            run = best = 0
            for x in w:
                run = run + 1 if x < -4 else 0
                best = max(best, run)
            if best >= 20:
                long_ += 1
        crises += len({r['crisis'] for r in q if r['crisis'] is not None and r['crisis'] > BURN})
        yrs += q[-1]['t'] - q[0]['t']
    s = sorted(G)
    med = [st.median(c) for c in zip(*win)]
    print(f"{arm:14s} | {100 * st.pstdev(G):4.2f} {corr(G, G1):+.2f} {corr(G, G2):+.2f} {corr(G, G3):+.2f} {corr(G, DG):+.2f} {beta([100 * x for x in G], DG):+.2f} {100 * s[len(s) // 200]:+5.1f} | "
          f"{100 * st.pstdev(DD):4.2f} {corr(DD, DD1):+.2f} {corr(L3, LG):+.2f} | {med[0]:+.2f} {med[1]:.2f} {med[2]:4.1%} {med[3]:4.1%} | {trap / len(win):4.1%} {long_ / len(win):4.1%} | {100 * crises / yrs:.2f}")
print("US             | 3.86 +.71 +.33 +.00 +.45 +1.16  -9.0 | 3.66 +.58 +.51 | -.48 1.62 1.0% 8.6% | CBO 1985-2019")
