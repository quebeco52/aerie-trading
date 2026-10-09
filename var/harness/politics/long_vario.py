import sys, math, statistics as st, collections, itertools, os
sys.argv = [sys.argv[0]]
exec(open('vote_fit.py').read().split("for label, countries in")[0])
s = series(WEST, 0.05)
out = collections.defaultdict(list)
for x in s.values():
    n = st.mean(math.exp(v) for v in x.values())
    for a, b in itertools.combinations(sorted(x), 2):
        g = round((b - a) / 5) * 5
        if 5 <= g <= 75:
            out[g].append(n * (x[b] - x[a]) ** 2 / 2)
for g in sorted(out):
    v = out[g]; print(f"gap {g:2d}: {1000*st.mean(v):6.1f}  pairs {len(v):5d}")
