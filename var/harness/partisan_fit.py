"""Partisan shift in the mean of government purchases: gov_fit.py's log-OU with the party in power in the mean.

   d s = kappa (theta + b x party - s) dt + sigma dW,   s = log(real civilian purchases / real potential)

The regression is gov_fit.py's (quarterly, trend, lagged share, lagged gap) with the party added, so b is the shift
in the purchases level a government holds, and the residual with the party in is the variance that is not politics.
Party is +1 Democratic, -1 Republican: the presidency, and separately unified control (presidency and both chambers,
divided = 0), since a parliamentary majority is the District's analogue of a US trifecta. The budget a new
government passes first takes effect with the fiscal year after its first October, hence the enactment lags.
"""
import json, math, os, csv

H = os.path.dirname(os.path.abspath(__file__))
src = open(os.path.join(H, 'credit_fit.py')).read()
exec(src[src.index('# --- Linear algebra'):src.index('# --- 1. Premium dynamics')])
D = json.load(open(os.path.join(H, 'policy_data.json')))
fred = lambda i: {r['observation_date']: float(r[i]) for r in csv.DictReader(open(os.path.join(H, 'fred', i + '.csv'))) if r[i] not in ('', '.')}
sl, nd, df = fred('SLCE'), fred('FNDEFX'), fred('FDEFX')
civ = {k: v * (sl[k] + nd[k]) / (sl[k] + nd[k] + df[k]) for k, v in D['GCEC1'].items() if k in sl and k in nd and k in df}
fed_nd = {k: v * nd[k] / (sl[k] + nd[k] + df[k]) for k, v in D['GCEC1'].items() if k in sl and k in nd and k in df}
pot = D['GDPPOT']
gap = {k: 100 * (v / pot[k] - 1) for k, v in D['GDPC1'].items() if k in pot}

PRESIDENT = [(1981, -1), (1989, -1), (1993, 1), (2001, -1), (2009, 1), (2017, -1), (2021, 1)]
UNIFIED = [((1993, 1), (1994, 4), 1), ((2001, 1), (2001, 2), -1), ((2003, 1), (2006, 4), -1), ((2009, 1), (2010, 4), 1), ((2017, 1), (2018, 4), -1)]


def president(y, q):
    p = 0
    for start, party in PRESIDENT:
        if y >= start:
            p = party
    return p


def unified(y, q):
    for (y0, q0), (y1, q1), party in UNIFIED:
        if (y0, q0) <= (y, q) <= (y1, q1):
            return party
    return 0


def shifted(y, q, lag):
    t = y * 4 + (q - 1) - lag
    return t // 4, t % 4 + 1


out = {}
for name, series in (('civilian', civ), ('federal non-defence', fed_nd)):
    share = {k: math.log(v / pot[k]) for k, v in series.items() if k in pot}
    ks = sorted(k for k in share if k in gap)
    for label, fn in (('presidency', president), ('unified control', unified)):
        for lag in (0, 3):
            Y, X = [], []
            for i in range(2, len(ks)):
                k = ks[i]
                if not ('1985-01' <= k <= '2019-10'):
                    continue
                y, q = int(k[:4]), (int(k[5:7]) - 1) // 3 + 1
                Y.append(share[k] - share[ks[i - 1]])
                X.append([1.0, i / 4.0, share[ks[i - 1]], gap[ks[i - 1]] / 100.0, fn(*shifted(y, q, lag + 1))])
            b, se, r2, e = ols(Y, X, nw_lags=4)
            m, sd, sk, ku = moments(e)
            shift = -b[4] / b[2]
            shift_se = se[4] / abs(b[2])
            print(f"{name:20s} {label:16s} lag {lag}q: party {b[4]:+.5f} ({se[4]:.5f}) t {b[4] / se[4]:+.2f}/q -> mean shift {shift:+.4f} "
                  f"({shift_se:.4f}) log per party unit; persistence {b[2]:+.4f}; resid sd {sd:.5f}")
            out[f"{name}|{label}|{lag}"] = {'party': b[4], 'party_se': se[4], 'shift': shift, 'shift_se': shift_se, 'persist': b[2], 'resid_sd': sd}
json.dump(out, open(os.path.join(H, 'partisan_fit.json'), 'w'), indent=1)
