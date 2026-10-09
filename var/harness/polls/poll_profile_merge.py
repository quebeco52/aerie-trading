"""Merge poll_profile_shard.php outputs into poll_profile.php's table. python3 poll_profile_merge.py '<glob of shard jsonl>'"""
import json, glob, sys, collections
err = collections.defaultdict(lambda: [0.0, 0]); gap = collections.defaultdict(lambda: [0.0, 0]); files = sorted(glob.glob(sys.argv[1]))
for f in files:
    r = json.loads(open(f).read().strip().splitlines()[-1])
    for src, dst in ((r['errors'], err), (r['gap'], gap)):
        for k, (s, n) in src.items():
            dst[int(k)][0] += s; dst[int(k)][1] += n
M = [1, 2, 3, 6, 9, 12, 18, 24, 30, 36, 42, 47]
print(f"({len(files)} seeds)")
print("1. Poll error on the result, (poll - vote)^2 / p(1-p) x 1e4, parties of 3% and over")
print("   months out   z2      n")
for m in M:
    if m in err: print(f"   {m:3d}        {err[m][0] / err[m][1]:6.1f}  {err[m][1]:6d}")
print("2. The cabinet's polls against its result (points), by month of the term; share of month 36")
base = gap[36][0] / gap[36][1]
for m in M:
    if m in gap:
        g = gap[m][0] / gap[m][1]
        print(f"   {m:3d}   {g:+6.2f}  share {g / base:5.2f}  n {gap[m][1]}")
