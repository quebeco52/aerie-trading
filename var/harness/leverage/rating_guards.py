#!/usr/bin/env python3
"""Credit-rating stability: paired and Welch t for every rating_stability.py metric, plus payment defaults and
revolver draws per seed. Usage: rating_guards.py R0 R2 (from the repo root)."""
import glob, json, math, re, statistics as st, sys, os, importlib.util

H = os.path.dirname(os.path.abspath(__file__))
A, B = sys.argv[1], sys.argv[2]
spec = importlib.util.spec_from_file_location('rs', f'{H}/rating_stability.py')
sys_argv = sys.argv
sys.argv = [sys_argv[0], A, B]
import io, contextlib
with contextlib.redirect_stdout(io.StringIO()):
    rs = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(rs)
sys.argv = sys_argv


def extra(arm, seed):
    rec = json.loads(open(f'{H}/runs/{arm}-{seed}.jsonl').readline())
    draws = sum(1 for ev in rec['events'].values() for e in ev
                if re.search(r'^Drew .* revolving credit facility', e['d']))
    return {'payment defaults / seed': len(rec.get('defaults', [])), 'revolver draws / seed': draws}


paired = sorted(rs.seeds(A) & rs.seeds(B))
ra = {s: {**rs.seed_metrics(A, s), **extra(A, s)} for s in paired}
rb = {s: {**rs.seed_metrics(B, s), **extra(B, s)} for s in paired}
print(f'arms {A} vs {B}, paired seeds n={len(paired)}')
print(f'{"metric":<26} {A:>8} {B:>8} {"diff":>8} {"seP":>7} {"tP":>6} {"seW":>7} {"tW":>6}  {A} range / {B} range')
for key in ra[paired[0]]:
    a = [ra[s][key] for s in paired]
    b = [rb[s][key] for s in paired]
    d = [y - x for x, y in zip(a, b)]
    n = len(d)
    sep = st.stdev(d) / math.sqrt(n)
    sew = math.sqrt(st.variance(a) / n + st.variance(b) / n)
    tp = st.mean(d) / sep if sep > 0 else math.nan
    tw = st.mean(d) / sew if sew > 0 else math.nan
    print(f'{key:<26} {st.mean(a):8.4f} {st.mean(b):8.4f} {st.mean(d):+8.4f} {sep:7.4f} {tp:6.2f} {sew:7.4f} {tw:6.2f}'
          f'  {min(a):.3g}-{max(a):.3g} / {min(b):.3g}-{max(b):.3g}')
