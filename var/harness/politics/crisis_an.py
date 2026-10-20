#!/usr/bin/env python3
# crisis_an.py <stage dir> [burn years]: per arm (new, head) crises per seed, per-crisis drag jump and capital,
# gap SD / mean, mean gap over 3 years after each crisis; paired new - head per seed with se.
import json, sys, glob, os, statistics as st

stage = sys.argv[1]
burn = float(sys.argv[2]) if len(sys.argv) > 2 else 5.0


def load(tree):
    out = {}
    for f in sorted(glob.glob(os.path.join(stage, tree, 'on_*.jsonl'))):
        seed = int(f.rsplit('_', 1)[1].split('.')[0])
        rows, ev = [], []
        for line in open(f):
            r = json.loads(line)
            (ev if r.get('ev') == 'crisis' else rows).append(r)
        gaps = [r['gap'] for r in rows if r['t'] > burn]
        post = []
        for e in ev:
            post += [r['gap'] for r in rows if e['t'] < r['t'] <= e['t'] + 3.0]
        out[seed] = dict(n=len(ev), sd=st.pstdev(gaps), mean=st.mean(gaps), post=st.mean(post) if post else None, ev=ev)
    return out


arms = {t: load(t) for t in ('head', 'new')}
seeds = sorted(set(arms['head']) & set(arms['new']))


def ms(xs):
    xs = [x for x in xs if x is not None]
    if not xs:
        return 'n/a'
    se = st.stdev(xs) / len(xs) ** 0.5 if len(xs) > 1 else float('nan')
    return f'{st.mean(xs):+.4f} ({se:.4f})'


print(f'stage {stage}, seeds {seeds}, burn {burn}y')
print('\nper crisis:')
print(f"{'arm':5}{'seed':>5}{'t':>8}{'req':>8}{'built':>8}{'ccyb':>7}{'scReq':>7}{'scBlt':>7}{'djump':>8}")
for t in ('head', 'new'):
    for s in seeds:
        for e in arms[t][s]['ev']:
            print(f"{t:5}{s:5}{e['t']:8.2f}{e['req']:8.4f}{e['built']:8.4f}{e['ccyb']:7.4f}{e['scaleReq']:7.3f}{e['scaleBuilt']:7.3f}{e['djump']:8.4f}")
print('\nper seed (head | new):')
for s in seeds:
    h, n = arms['head'][s], arms['new'][s]
    fp = lambda x: 'n/a' if x is None else f'{x:+.4f}'
    print(f"  s{s}: crises {h['n']}|{n['n']}  gapSD {h['sd']:.4f}|{n['sd']:.4f}  gapMean {h['mean']:+.4f}|{n['mean']:+.4f}  post3y {fp(h['post'])}|{fp(n['post'])}")
print(f"\n{'quantity':16}{'head':>18}{'new':>18}{'new-head (se)':>20}  n")
for k, lab in (('n', 'crises/seed'), ('sd', 'gap SD'), ('mean', 'gap mean'), ('post', 'gap 3y post')):
    h = [arms['head'][s][k] for s in seeds]
    n = [arms['new'][s][k] for s in seeds]
    d = [b - a for a, b in zip(h, n) if a is not None and b is not None]
    print(f'{lab:16}{ms(h):>18}{ms(n):>18}{ms(d):>20}  {len(seeds)}')
for t in ('head', 'new'):
    ev = [e for s in seeds for e in arms[t][s]['ev']]
    if ev:
        print(f"{t}: {len(ev)} crises, mean djump {st.mean(e['djump'] for e in ev):.4f}, mean built-req {st.mean(e['built'] - e['req'] for e in ev):+.4f}, "
              f"mean scale built/req {st.mean(e['scaleBuilt'] / e['scaleReq'] for e in ev):.3f}")
