"""Ring-down by channel: python3 ring_an.py <file> -> cumulative gap contribution of each channel, per unit kick."""
import json, sys
r = json.load(open(sys.argv[1]))
names = sorted({k for row in r for k in row['c']}, key=lambda k: -max(abs(sum(rr['c'].get(k, 0) for rr in r[:h + 1])) for h in range(len(r))))
hs = [0, 2, 4, 6, 8, 10, 12, 16, 20, 24, 32]
print(f"{'channel':28s}" + ''.join(f"{h:>7d}q" for h in hs))
print(f"{'gap':28s}" + ''.join(f"{r[h]['gap']:+8.2f}" for h in hs))
for k in names[:12]:
    print(f"{k:28s}" + ''.join(f"{sum(rr['c'].get(k, 0) for rr in r[:h + 1]):+8.2f}" for h in hs))
if 'x' in r[0]:
    print('--- levels, per unit kick (pp per pp of gap)')
    for k in r[0]['x']:
        print(f"{k:28s}" + ''.join(f"{r[h]['x'][k]:+8.2f}" for h in hs))
