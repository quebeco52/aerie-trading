"""Numbers behind each final category (US BEA detail, 1997-2019, gap partialled out) and the game-side consequences.

Commodity-priced lines (Chemicals, Steel, the world commodities) are read on real shares: the macro prices them.
python3 -I final_table.py
"""
import json, math, os, sys
HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from fit_momentum import growth_resid, stats, pooled_phi  # noqa: E402

D = json.load(open(os.path.join(HERE, 'data', 'us_shares.json')))
RV = json.load(open(os.path.join(HERE, 'data', 'real_vs_nominal.json')))
from categories import FINAL as F  # noqa: E402
REAL = {'Chemicals', 'Steel', 'Copper', 'Gold', 'Oil & Gas E&P', 'Oil & Gas Refining & Marketing', 'Farm Products'}
cut = D['years'].index(2019) + 1
gap = D['gap'][:cut]
med = lambda xs: sorted(xs)[len(xs) // 2] if xs else float('nan')

print(f"{'category':16s} {'n':>2s} {'sd1':>6s} {'lr3':>6s} {'lr5':>6s} {'range lr5':>13s} {'phi':>5s} | {'s_eta':>5s} {'HL':>4s} {'gapSD':>6s} {'20ySD':>6s} {'read':>5s}")
lines = []
for cat, spec in F.items():
    names = [n for n in spec['industries'] if n in D['industries']]
    sd1, lr3, lr5, us = [], [], [], []
    for n in names:
        if n in REAL:
            sd1.append(RV[n]['real_sd1']); lr5.append(RV[n]['real_lr5']); lr3.append(float('nan'))
            continue
        u = growth_resid(D['industries'][n][:cut], gap)
        st = stats(u)
        us.append(u); sd1.append(st['sd1']); lr3.append(st['lr'][3]); lr5.append(st['lr'][5])
    phi = pooled_phi(us)[0] if us else float('nan')
    s, hl = spec['drift_vol'], spec['learning_half_life']
    k = 1 - 0.5 ** (0.25 / hl)
    gapsd = s * math.sqrt((1 - k) / (4 * k))
    read = s / 2 * math.sqrt((1 - k) / (k * k))
    l3 = [x for x in lr3 if x == x]
    print(f"{cat:16s} {len(names):2d} {med(sd1):6.3f} {med(l3):6.3f} {med(lr5):6.3f} {min(lr5):6.3f}-{max(lr5):6.3f} {phi:5.2f} | "
          f"{s:5.3f} {hl:4.2f} {gapsd:6.3f} {s * math.sqrt(20):6.3f} {read:5.3f}")
s, hl = 0.08, 2.0
k = 1 - 0.5 ** (0.25 / hl)
print(f"{'stress test':16s}{'':55s} | {s:5.3f} {hl:4.2f} {s * math.sqrt((1 - k) / (4 * k)):6.3f} {s * math.sqrt(20):6.3f} {s / 2 * math.sqrt((1 - k) / (k * k)):5.3f}")
