"""Rank premium-drag points: J = GZ local projection + ACF + rate path + sd + rule. python3 prem_an.py <dir> <tag>..."""
import json, os, re, subprocess, sys
H = os.path.dirname(os.path.abspath(__file__))
IRF = json.load(open(os.path.join(H, 'credit_fit.json')))['irf']
rows = []
for tag in sys.argv[2:]:
    out = subprocess.run(['python3', os.path.join(H, 'credit_engine_an.py'), sys.argv[1], tag], capture_output=True, text=True).stdout
    lp = dict((int(h), float(g)) for h, g in re.findall(r'(\d+)q ([-+.\d]+)\|', re.search(r'LP, gap response.*', out).group(0)))
    Jl = sum(((lp[h] - IRF[str(h)][0]) / IRF[str(h)][1]) ** 2 for h in lp if str(h) in IRF and h > 0)
    env = dict(os.environ, RULE='1', SD='1')
    o2 = subprocess.run(['python3', os.path.join(H, 'isl_an.py'), sys.argv[1], tag], capture_output=True, text=True, env=env).stdout
    J = float(re.search(r': J ([.\d]+)', o2).group(1))
    rows.append((J + Jl, f"{tag}: total {J + Jl:.1f} (LP {Jl:.1f}; {o2.splitlines()[0].split(': ', 1)[1]})\n    LP " + '  '.join(f"{h}q {v:+.2f}/{IRF[str(h)][0]:+.2f}" for h, v in lp.items() if str(h) in IRF) + '\n' + '\n'.join(o2.splitlines()[2:4])
                 + '\n    ' + re.search(r'crises .*', out).group(0)))
for _, s in sorted(rows):
    print(s)
