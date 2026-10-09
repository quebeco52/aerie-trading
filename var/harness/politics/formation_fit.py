"""Fits the formation constants exactly on recorded Diets (formation_iv.php): no Monte Carlo.

Every attempt weighs the same cabinets, and attempt t succeeds with p_t = 1/(1+exp(R - s(t-1) - IV)) (IV the log-sum of
the cabinets' odds): the bar of no deal falls by s each attempt. A formation of n attempts of Exp(mu) days lasts
Gamma(n, mu). The cabinet that forms is drawn in proportion to its odds. Targets:
  a : the anti-system strength, per unit a cabinet party stands from the Council median: the Chartists and the Common
      Lot, the Council axis's two ends, sit in cabinet as seldom as Scandinavia's radical parties, 5.7% (ParlGov,
      parlgov_targets.py; 4 of 70)
  R : 32% of formations need more than one attempt (Golder 2010: 'nearly a third')
  s : the formations' spread, sd 33.9 days on a mean of 33.7 (Baeck, Hellstroem, Lindvall & Teorell 2023, Table 1)
  mu: formations average 33.7 days (the same table)
Checks (ParlGov, Scandinavia; Western Europe in brackets): the largest party in cabinet 63%, the two largest together
1.5%, minority 84%, single-party 47%, the outgoing cabinet re-formed unchanged 46%; the party leading the cabinet (its
largest) under 10% of the seats 0% (4.2%), not one of the two largest 10.7% (12.9%), a cabinet under 25% of the seats
2.9% (1.6%).
python3 formation_fit.py iv*.jsonl
"""
import json, math, sys
RADICAL, FIRST_FAIL, MEAN_DAYS = 0.057, 0.32, 33.73
rows = [json.loads(l) for f in sys.argv[1:] for l in open(f)]
hung = [r for r in rows if not r['majority']]
KEYS = ['minority', 'largest', 'grand', 'sq', 'single', 'chartists', 'common_lot']

def logsum(u):
    top = max(u)
    return top + math.log(sum(math.exp(x - top) for x in u))

def leader(r, i):
    """The cabinet's largest party, which leads it."""
    return min((f for f in range(8) if r['members'][i] >> f & 1), key=lambda f: r['rank'][f])

def shares(a, full=False):
    keys = KEYS + (['pm10', 'pmtop2', 'cab25'] if full else [])
    out = dict.fromkeys(keys, 0.0)
    ivs = []
    for r in hung:
        u = [b + a * c for b, c in zip(r['base'], r['challenge'])]
        iv = logsum(u); ivs.append(iv)
        w = [math.exp(x - iv) for x in u]
        for key in KEYS:
            out[key] += sum(wi for wi, f in zip(w, r[key]) if f)
        if full:
            for i, wi in enumerate(w):
                lead = leader(r, i)
                out['pm10'] += wi * (r['partyShare'][lead] < 0.10)
                out['pmtop2'] += wi * (r['rank'][lead] > 2)
                out['cab25'] += wi * (r['cabinetShare'][i] < 0.25)
    return {key: v / len(hung) for key, v in out.items()}, ivs

def bisect(f, lo, hi, it=60):
    flo = f(lo)
    for _ in range(it):
        mid = (lo + hi) / 2
        fm = f(mid)
        if (fm > 0) == (flo > 0): lo, flo = mid, fm
        else: hi = mid
    return (lo + hi) / 2

radical = lambda s: (s['chartists'] + s['common_lot']) / 2
for a in (0.0, -1.0, -2.5, -4.0):
    s, _ = shares(a)
    print(f"  a {a:5.1f}: radical {radical(s):.3f}, " + ", ".join(f"{k} {v:.3f}" for k, v in s.items()))
a = bisect(lambda a: radical(shares(a)[0]) - RADICAL, -12.0, 0.0)
s, ivs = shares(a, full=True)
SD_DAYS, N = 33.90, 400
def attempts(iv, r0, step):
    alive, out = 1.0, []
    for t in range(1, N + 1):
        x = r0 - step * (t - 1) - iv
        p = 1.0 / (1.0 + math.exp(x)) if x < 700 else 0.0
        out.append(alive * p)
        alive *= 1.0 - p
    out[-1] += alive
    return out
def moments(r0, step):
    et = et2 = fail = 0.0
    for iv in ivs:
        pt = attempts(iv, r0, step)
        fail += 1.0 - pt[0]
        et += sum((n + 1) * q for n, q in enumerate(pt))
        et2 += sum((n + 1) * (n + 2) * q for n, q in enumerate(pt))
    return fail / len(ivs), et / len(ivs), et2 / len(ivs)
bar = bisect(lambda b: moments(b, 0.0)[0] - FIRST_FAIL, -10.0, 12.0)
cv = lambda step: math.sqrt(moments(bar, step)[2] / moments(bar, step)[1] ** 2 - 1.0)
for step in (0.0, 0.1, 0.25, 0.5):
    print(f"  step {step:.2f}: cv {cv(step):.3f} (target {SD_DAYS / MEAN_DAYS:.3f})")
step = 0.0 if cv(0.0) <= SD_DAYS / MEAN_DAYS else bisect(lambda x: cv(x) - SD_DAYS / MEAN_DAYS, 0.0, 5.0)
fail, et, et2 = moments(bar, step)
mu = MEAN_DAYS / et
def gamma_surv(n, x):
    return math.exp(-x) * sum(x ** i / math.factorial(i) for i in range(n))
over90 = sum(sum(q * gamma_surv(n + 1, 90.0 / mu) for n, q in enumerate(attempts(iv, bar, step)[:60])) for iv in ivs) / len(ivs)
print(f"FIT: anti-system {a:.3f}/unit  bar {bar:.3f}  step {step:.3f}  attempt days {mu:.2f}")
print(f"  Chartists {s['chartists']:.3f} Common Lot {s['common_lot']:.3f} (radical 0.057) | largest in cabinet {s['largest']:.3f} (0.63) | two largest {s['grand']:.3f} (0.015) | "
      f"minority {s['minority']:.3f} (0.84) | single-party {s['single']:.3f} (0.47) | re-formed {s['sq']:.3f} (0.46)")
print(f"  leading party under 10% {s['pm10']:.3f} (0 / 0.042) | not one of the two largest {s['pmtop2']:.3f} (0.107 / 0.129) | cabinet under 25% {s['cab25']:.3f} (0.029 / 0.016)")
print(f"  first attempt fails {fail:.3f}  E[attempts] {et:.3f}  sd days {mu * math.sqrt(et2 - et * et):.1f} (33.9)  over 90 days {over90:.3f}  hung Diets {len(hung)} of {len(rows)}")
