"""Max |importTariffRate| and |tariffTradeLag| over every tick of the recorded macro paths. Usage: python3 -I tariff_check.py 1-16."""
import os, re, sys, zlib

H = os.path.dirname(os.path.abspath(__file__))
lo, hi = (int(x) for x in sys.argv[1].split('-'))
mx = {'importTariffRate': 0.0, 'tariffTradeLag': 0.0}
ticks = 0
for s in range(lo, hi + 1):
    d = zlib.decompress(open(f'{H}/../loon/macro/path-{s}.gz', 'rb').read()).decode('latin1')
    for k in mx:
        vals = [abs(float(v)) for v in re.findall(r's:\d+:"' + k + r'";[di]:([-0-9.E+]+);', d)]
        mx[k] = max(mx[k], max(vals))
        ticks += len(vals) if k == 'tariffTradeLag' else 0
print(f'paths {lo}-{hi}, {ticks} recorded ticks: ' + ', '.join(f'max |{k}| = {v:g}' for k, v in mx.items()))
