"""Paired NEW - HEAD option-E moments (formation_run.php rows, election formations only; fall rows carry no blocs).
mandate votes: share of elections where one bloc holds >= 151 seats. losing-bloc cabinets: of those, the share whose
cabinet's largest party (by seats) declared for the other bloc. Both leaders in govt: both bloc leaders in cabinet or
support. python3 mandate_an.py <runs dir>"""
import json, glob, sys, os, statistics as st, math
d = sys.argv[1]; MAJ = 151
def moments(path):
    el = [r for r in map(json.loads, open(path)) if not r.get('fall')]
    hits = mand = both = two = 0
    for r in el:
        b, s = r['blocs'], r['seats']
        lead = max(r['cabinet'], key=lambda p: (s[p], r['shares'][p]))
        won = [l for l in set(b.values()) if sum(s[p] for p in b if b[p] == l) >= MAJ]
        if won:
            mand += 1; hits += b[lead] != won[0]
        top2 = sorted(s, key=lambda p: (-s[p], -r['shares'][p]))[:2]; two += all(p in r['cabinet'] for p in top2)
        both += sum(1 for l in set(b.values()) if l in r['cabinet'] + r['support']) > 1
    return {'mandate votes': mand / len(el), 'losing-bloc cabinet | mandate': hits / mand if mand else float('nan'),
            'losing-bloc cabinets, count': hits, 'both leaders in govt': both / len(el), 'two largest in cabinet': two / len(el)}
seeds = sorted(int(os.path.basename(f)[4:-6]) for f in glob.glob(f"{d}/new_*.jsonl") if os.path.exists(f"{d}/head_{os.path.basename(f)[4:]}"))
H = {s: moments(f"{d}/head_{s}.jsonl") for s in seeds}; N = {s: moments(f"{d}/new_{s}.jsonl") for s in seeds}
print(f"n = {len(seeds)} seeds, paired NEW - HEAD")
print(f"{'quantity':32s} {'HEAD':>8s} {'NEW':>8s} {'diff':>8s} {'se':>7s} {'t':>6s}")
for k in H[seeds[0]]:
    h = [H[s][k] for s in seeds]; n = [N[s][k] for s in seeds]; dd = [b - a for a, b in zip(h, n)]
    se = st.stdev(dd) / math.sqrt(len(dd))
    print(f"{k:32s} {st.mean(h):8.3f} {st.mean(n):8.3f} {st.mean(dd):+8.3f} {se:7.3f} {st.mean(dd) / se if se else float('nan'):+6.2f}")
print(f"pooled losing-bloc cabinets HEAD {sum(H[s]['losing-bloc cabinets, count'] for s in seeds)} NEW {sum(N[s]['losing-bloc cabinets, count'] for s in seeds)}")
