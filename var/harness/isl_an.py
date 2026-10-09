"""Rank IS points: python3 isl_an.py <dir> <tag>... -> J = ACF distance + gap distance under the US rate path."""
import json, os, re, subprocess, sys
H = os.path.dirname(os.path.abspath(__file__))
D = json.load(open(os.path.join(H, 'policy_fit.json')))
rows = []
RULE = os.environ.get('RULE') == '1'
SD = os.environ.get('SD') == '1'
for tag in sys.argv[2:]:
    out = subprocess.run(['python3', os.path.join(H, 'policy_engine_an.py'), sys.argv[1], tag, 'none', tag + "path"], capture_output=True, text=True).stdout
    Ja = float(re.search(r'J_acf ([.\d]+)', out).group(1))
    rule = D['rule']
    rho, phpi, phy = map(float, re.search(r'engine rho ([.\d]+) \([.\d]+/yr\), inflation ([-.\d]+), gap ([-.\d]+)', out).groups())
    Jr = ((rho - rule['rho']) / rule['rho_se']) ** 2 + ((phpi - rule['phi_pi']) / rule['phi_pi_se']) ** 2 + ((phy - rule['phi_y']) / rule['phi_y_se']) ** 2
    Jp = float(re.search(r'J_path ([.\d]+)', out).group(1))
    sd = float(re.search(r'sd ([.\d]+) \(US', out).group(1))
    Js = ((sd - 2.33) / 0.26) ** 2 if SD else 0.0
    path = re.search(r'per unit surprise: (.*?)  \|', out).group(1)
    acf = re.search(r'ACF engine: (.*)', out).group(1)
    bands = re.search(r'bands: (.*)', out).group(1)
    rf = f'{sys.argv[1]}/{tag}ring.json'
    ring = ''
    if os.path.exists(rf):
        r = json.load(open(rf))
        ring = f" | ring 4q {r[4]:+.2f} 8q {r[8]:+.2f} 12q {r[12]:+.2f} min {min(r):+.2f}"
    rows.append((Ja + Jp + Js + (Jr if RULE else 0), f"{tag}: J {Ja + Jp + Js + (Jr if RULE else 0):.1f} (ACF {Ja:.1f}, path {Jp:.1f}, sd {Js:.1f}) rule {Jr:.1f} [rho {rho:.3f} pi {phpi:.2f} y {phy:.2f}]{ring}\n    path {path}\n    ACF {acf}\n    {bands}"))
for _, s in sorted(rows):
    print(s)
