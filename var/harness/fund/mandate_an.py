# mandate_an.py: control vs a head's mandate at year 4 (cut to 53%, bold to 85%), per seed and mean.
import json, statistics as st, math
R = 'var/harness/fund/runs'
def load(arm, s): return json.loads(open(f'{R}/{arm}-{s}.jsonl').readline())
rows = {}
for arm in ['cut', 'bold']:
    print(arm)
    for s in (1, 2, 3):
        c, a = load('control', s), load(arm, s)
        qc = {q['t']: q for q in c['quarters']}; qa = {q['t']: q for q in a['quarters']}
        def at(t, k, q): return q[round(t, 3)][k] if round(t, 3) in q else float('nan')
        # board price index: per tick list; ticks per year 360
        bi_c, bi_a = c['board'], a['board']
        def board_rel(t): i = int(t * 360) - 1; return (bi_a[i] / bi_a[int(4.0*360)-1]) / (bi_c[i] / bi_c[int(4.0*360)-1]) - 1
        line = []
        for t in (4.0, 4.5, 5.0, 5.75, 6.5, 8.0, 9.0):
            line.append(f"t{t}: own {at(t,'own',qa)*100:.2f}/{at(t,'own',qc)*100:.2f} board {board_rel(t)*100:+.2f}%")
        print(f"  seed {s}: " + ' | '.join(line))
        print(f"          draw/GDP y8 {at(8.0,'draw',qa)*100:.3f}% vs {at(8.0,'draw',qc)*100:.3f}%, exp real {at(8.0,'exp_real',qa)*100:.2f}% vs {at(8.0,'exp_real',qc)*100:.2f}%, pol_eq {at(8.0,'pol_eq',qa):.3f}, weight {at(8.0,'weight',qa):.4f} target {at(8.0,'target',qa):.4f}")
