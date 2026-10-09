"""Indirect inference for the fund-financed stabilisation rule: python3 var/harness/stab_fit.py [runs dir]

Replays CreditFiscalSubsystem::calculateFundStabilisation offline on the nostab arm's recorded paths (gap reading,
tax rate, purchases), builds the annual discretionary balance the IMF Norway 2025 Table 5 regression is run on, and
searches (beta, phi) so the engine's regression lands on Norway's gap +0.450 and lagged change -0.452. kappa stays at
the Auerbach persistence. The rule's own feedback on the gap is left out: the purchases channel's multiplier is ~0.2
(StabImpulseProbeTest), so a stabilisation of 1% of GDP moves the gap ~0.25pp. A full harness batch confirms the pick.
"""
import math, os, sys, statistics as st

H = os.path.dirname(os.path.abspath(__file__))
exec(compile(open(os.path.join(H, 'stab_an.py')).read().split('\narms = {a: load(a)')[0], 'stab_an', 'exec'))

KAPPA = 0.175
TARGET = (0.450, -0.452)
SE = (0.194, 0.093)


TREND_YEARS = 15.0   # MacroEngine::FUND_STABILISATION_GAP_TREND_YEARS; 0 replays the uncentred rule


def replay(r, beta, phi, trend_years=TREND_YEARS):
    """Quarterly rows with the rule's stabilisation replayed on this run's gap readings (rounds every half year).
    The gap as it stands is read against its trailing average, stepped here a quarter at a time."""
    D = D0 = last = trend = 0.0
    w = 1.0 - math.exp(-0.25 / trend_years) if trend_years > 0 else 0.0
    out = []
    for q in r['quarters']:
        t = q['t']
        half = round(t * 2.0)
        if abs(t * 2.0 - half) < 1e-6 and t > 0:           # a budget round falls on this quarter's sample
            if half % 2 == 0:                               # the round that opens a year
                last, D0 = D - D0, D
            D = D0 - beta * (q['gap'] - trend) - phi * last - KAPPA * D0
        if t > 0:                                           # the average is of the readings before the round's
            trend += w * (q['gap'] - trend)
        out.append({**q, 'stab': D})
    return {**r, 'quarters': out}


def moments(runs, beta, phi):
    groups = []
    for r in runs.values():
        a = annual(replay(r, beta, phi))
        Y, X = [], []
        for p2, p1, c in zip(a, a[1:], a[2:]):
            Y.append(c['B'] - p1['B'])
            X.append([c['gap'], p1['B'] - p2['B']])
        groups.append((Y, X))
    b, se, n = ols_fe_cluster(groups)
    return b


runs = load('nostab')
print(f"{len(runs)} nostab runs from {D}")
print("current rule (0.30, 0.452):", ['%+.3f' % v for v in moments(runs, 0.30, 0.452)])
best = None
for beta in [0.20, 0.25, 0.30, 0.35, 0.40, 0.45, 0.50, 0.60]:
    for phi in [0.452, 0.8, 1.0, 1.2, 1.4, 1.6]:
        b = moments(runs, beta, phi)
        j = ((b[0] - TARGET[0]) / SE[0]) ** 2 + ((b[1] - TARGET[1]) / SE[1]) ** 2
        print(f"  beta {beta:.2f} phi {phi:.3f}: gap {b[0]:+.3f} lagged {b[1]:+.3f}  J {j:6.2f}")
        if best is None or j < best[0]:
            best = (j, beta, phi, b)
print(f"best: beta {best[1]:.2f} phi {best[2]:.3f} -> gap {best[3][0]:+.3f} lagged {best[3][1]:+.3f} (J {best[0]:.2f})")
