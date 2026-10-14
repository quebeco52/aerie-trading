#!/usr/bin/env python3
"""Failures and TIER deaths, before vs after, treated UNPAIRED (the arms diverge within a quarter).
Prints: failures per run (mean, Welch se of the difference), runs with >= 1 failure and runs with TIER dead out of n
(Fisher exact one- and two-sided, rate difference and its binomial se), TIER mean year of death, and the
divestiture signature before each TIER death. Signature: a quarter where wholesale debt falls by >= 14% and equity
by >= 20% while |net income| is under a quarter of the equity drop. MergerAndAcquisitionEngine::executeDivestitureSale
takes lostDebt = wholesaleDebt x fraction, so the wholesale-debt drop reads the divested fraction: 0.30-0.50 dying,
0.15-0.30 distressed. Usage: analyse_tier.py [runs dir]"""
import json, glob, os, sys, math, statistics as st

D = os.path.dirname(os.path.abspath(__file__))
R = next((a for a in sys.argv[1:] if not a.startswith('--')), os.path.join(D, 'runs'))
T = 'TIER'


def load(arm):
    out = {}
    for p in glob.glob(os.path.join(R, f'{arm}-*.jsonl')):
        with open(p) as fh:
            line = fh.readline()
        if line:
            r = json.loads(line)
            out[r['seed']] = r
    return out


def hyp(k, n1, n2, K):
    return math.comb(n1, k) * math.comb(n2, K - k) / math.comb(n1 + n2, K)


def fisher(a, n1, b, n2):
    """a of n1 in after, b of n2 in before. One-sided: P(after count >= a). Two-sided: sum of p <= p(observed)."""
    K = a + b
    ks = range(max(0, K - n2), min(n1, K) + 1)
    p0 = hyp(a, n1, n2, K)
    one = sum(hyp(k, n1, n2, K) for k in ks if k >= a)
    two = sum(hyp(k, n1, n2, K) for k in ks if hyp(k, n1, n2, K) <= p0 * (1 + 1e-9))
    return one, min(1.0, two)


def signatures(f):
    q = f['q']; out = []
    for p, c in zip(q, q[1:]):
        if p['eq'] <= 0 or p.get('wd', 0) <= 0:
            continue
        de = p['eq'] - c['eq']; fw = 1 - c.get('wd', 0) / p['wd']
        if fw >= 0.14 and de / p['eq'] >= 0.20 and abs(c['ni']) < 0.25 * de:
            out.append({'t': c['t'], 'frac': fw, 'eq_loss': de / p['eq'], 'pb': p['price'] * p['shares'] / p['eq']})
    return out


B, A = load('before'), load('after')
print(f'unpaired: before n={len(B)}, after n={len(A)}, seeds {min(B)}-{max(B)}')
fb = [len(r['dead']) + len(r['reorgs']) for r in B.values()]
fa = [len(r['dead']) + len(r['reorgs']) for r in A.values()]
d = st.mean(fa) - st.mean(fb); se = math.sqrt(st.variance(fa) / len(fa) + st.variance(fb) / len(fb))
print(f'failures per run (bk+reorg)   before {st.mean(fb):.3f}  after {st.mean(fa):.3f}  diff {d:+.3f} se {se:.3f} t {d / se:.2f}')
for lab, pred in (('runs with >= 1 failure', lambda r: len(r['dead']) + len(r['reorgs']) > 0),
                  ('runs with TIER bankrupt', lambda r: T in r['dead']),
                  ('runs with a non-TIER failure', lambda r: any(t != T for t in r['dead']) or len(r['reorgs']) > 0)):
    b = sum(1 for r in B.values() if pred(r)); a = sum(1 for r in A.values() if pred(r))
    nb, na = len(B), len(A); pb, pa = b / nb, a / na
    se = math.sqrt(pb * (1 - pb) / nb + pa * (1 - pa) / na)
    one, two = fisher(a, na, b, nb)
    print(f'{lab:30s} before {b}/{nb}  after {a}/{na}  rate diff {pa - pb:+.3f} se {se:.3f}  Fisher one-sided {one:.2g} two-sided {two:.2g}')

for arm, RR in (('before', B), ('after', A)):
    yrs = [r['dead'][T] for r in RR.values() if T in r['dead']]
    print(f'\n{arm}: TIER deaths {len(yrs)}, mean year {st.mean(yrs):.1f}' + (f' (range {min(yrs):.1f}-{max(yrs):.1f})' if yrs else '') if yrs else f'\n{arm}: no TIER deaths')
    pre = 0; fr = []
    for s, r in sorted(RR.items()):
        if T not in r['dead']:
            continue
        sig = [x for x in signatures(r['final'][T]) if r['dead'][T] - 4 <= x['t'] <= r['dead'][T]]
        pre += bool(sig); fr += [x['frac'] for x in sig]
        print(f'  seed {s:2d} dies {r["dead"][T]:5.2f}: ' + ('; '.join(f't {x["t"]:.2f} wd -{x["frac"]:.0%} eq -{x["eq_loss"]:.0%} P/B {x["pb"]:.2f}' for x in sig) or 'no signature quarter in prior 4y'))
    allsig = [x for r in RR.values() for x in signatures(r['final'][T])]
    print(f'  deaths preceded by a signature quarter (4y): {pre}/{len(yrs)}; TIER signature quarters in all runs: {len(allsig)} '
          f'({len(allsig) / len(RR):.2f}/run), runs with one: {sum(1 for r in RR.values() if signatures(r["final"][T]))}/{len(RR)}; '
          f'implied fraction 0.30-0.50 (dying): {sum(1 for x in allsig if x["frac"] >= 0.295)}')
    board = sum(len(signatures(f)) for r in RR.values() for t, f in r['final'].items() if t != T)
    print(f'  same signature, all other firms: {board} ({board / len(RR):.2f}/run)')
