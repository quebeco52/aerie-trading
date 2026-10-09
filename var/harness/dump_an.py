"""Read a `make macro-dump` file with quarter_diagnostics: python3 var/harness/dump_an.py [var/macro-gap-history.jsonl]

Prints the path (quarter AVERAGES where recorded), what held the policy rate, the shocks drawn, and each quarter's
gap move by family, then run-level statistics. Splits on config_fingerprint when a file spans a recalibration.
Quarters recorded after 2026-09-27 also carry the rule's productivity see-through (column "seen"), a labour section
(unemployment and NAIRU flows, wage-growth terms) and a households section (house-price fundamental, sentiment);
their tables print only when some quarter has them.
"""
import json, math, sys, os
from collections import Counter, defaultdict
H = os.path.dirname(os.path.abspath(__file__))
path = sys.argv[1] if len(sys.argv) > 1 else os.path.join(H, '..', 'macro-gap-history.jsonl')
rows = [json.loads(l) for l in open(path)]
# An empty PHP array reaches JSON as [], not {}.
obj = lambda x: x if isinstance(x, dict) else {}
FAM = {'monetary': ['monetaryDrag'], 'fiscal': ['fiscalStimulus', 'fundStabilisation', 'automaticStabiliser'],
       'disturb': ['demandShock'], 'disaster': ['demandDisaster', 'disasterCompensator'],
       'credit': ['creditFrictionDrag', 'premiumDrag', 'crisisDeleveragingDrag', 'crisisCompensator', 'lendingStandardsDrag', 'householdNewBorrowing', 'householdDeleveragingDrag'],
       'premComp': ['premiumCompensator'], 'wealth': ['housingWealthEffect', 'equityWealthEffect', 'financeOutput'],
       'supply': ['energySupplyDrag', 'freightSupplyDrag', 'catastropheSupplyDrag', 'productivitySupply'],
       'external': ['netExportDrag', 'netExports'], 'capacity': ['momentum', 'cubicConstraint', 'capitalDrag', 'inventoryDrag', 'policyUncertaintyDrag']}
segs = Counter(r.get('config_fingerprint') for r in rows)
# Several seeds (a DiagnosticsRunTest file): skip the per-quarter tables, keep the statistics and the screen.
multi = len({r.get('seed') for r in rows}) > 1
print(f"{len(rows)} quarters, t {rows[0]['total_time']:.2f}-{rows[-1]['total_time']:.2f}, fingerprints {dict(segs)}, tpy {Counter(r.get('ticks_per_year') for r in rows)}")

def pct(x): return f"{100*x:+6.2f}" if x is not None else "   —  "
if not multi: print("\n  t     gapAvg  gapEnd  inflAvg polAvg tgtAvg   seen  10yAvg  uAvg   ebpAvg | floor evans ceil panic | events")
for r in ([] if multi else rows):
    d = r.get('quarter_diagnostics')
    if not d: continue
    a = d['averages']; c = obj((d.get('policy') or {}).get('constraints'))
    ev = obj(d.get('events'))
    evs = ' '.join(f"{k.replace('systemic.', 's.')}×{v['count']}" + (f"({v['sum']:+.3f})" if k in ('demandDisaster', 'creditCrisis') else '')
                   for k, v in sorted(ev.items()) if k not in ('excessBondPremium', 'catastrophe') or v['count'] > 3)
    seen = obj((d.get('policy') or {}).get('terms')).get('productivitySeenThrough')
    print(f"{r['total_time']:5.2f} {pct(a['outputGap'])} {pct(r['output_gap'])} {pct(a['inflation'])} {pct(a['policyRate'])} {pct(a['targetRate'])} {pct(seen)} {pct(a['yield10y'])} {pct(a['unemploymentRate'])} {pct(a['excessBondPremium'])} | "
          f"{c.get('lowerBound', 0):4.0%} {c.get('evansHold', 0):5.0%} {c.get('hikeCeiling', 0):4.0%} {c.get('panicSpeed', 0):4.0%} | {evs}")

if not multi: print("\n  t     Δgap  | " + ' '.join(f"{k:>8s}" for k in FAM) + " | resid")
for r in ([] if multi else rows):
    g = r.get('gap_channels')
    if not g: continue
    c = g['contributions']
    fam = {k: sum(c.get(ch, 0.0) for ch in v) for k, v in FAM.items()}
    print(f"{r['total_time']:5.2f} {100*g['change']:+6.2f} | " + ' '.join(f"{100*fam[k]:+8.2f}" for k in FAM) + f" | {100*(g['diffusion']+g['clamp']+g.get('external', 0)+g['unexplained']):+5.2f}")

# Labour and households sections (quarters recorded with them only). Unemployment/NAIRU moves are flows over the
# quarter in pp; wage terms are quarter averages in pp; the house fundamental is in log points x100 against baseline.
lab_rows = [r for r in rows if obj(r.get('quarter_diagnostics')).get('labour')]
if lab_rows and not multi:
    print("\n  t    uAvg  okunTgt  NAIRU |  du   =  gap  toward | fire% | dNAIRU scar  heal | wage = prod  expect tight   EC    lag  rigid%")
    for r in lab_rows:
        d = r['quarter_diagnostics']; L = d['labour']; a = d['averages']
        un, na, w = obj(L.get('unemployment')), obj(L.get('nairu')), obj(L.get('wageGrowth'))
        ut, nt, wt = obj(un.get('terms')), obj(na.get('terms')), obj(w.get('terms'))
        print(f"{r['total_time']:5.2f} {100*a['unemploymentRate']:5.2f} {100*L.get('okunTarget', 0):6.2f}  {100*a.get('nairu', r['nairu']):5.2f} | {100*un.get('change', 0):+5.2f} {100*ut.get('outputGap', 0):+6.2f} {100*ut.get('towardNairu', 0):+6.2f} | {100*L.get('firingShare', 0):4.0f}% | "
              f"{100*na.get('change', 0):+6.3f} {100*nt.get('scarring', 0):5.3f} {100*nt.get('healing', 0):+6.3f} | {100*w.get('average', 0):4.2f} {100*wt.get('productivity', 0):5.2f} {100*wt.get('expectations', 0):6.2f} {100*wt.get('tightness', 0):+5.2f} {100*wt.get('errorCorrection', 0):+5.2f} {100*wt.get('stickyLag', 0):+5.2f} {100*L.get('downwardRigidShare', 0):4.0f}%")
hh_rows = [r for r in rows if obj(r.get('quarter_diagnostics')).get('households')]
if hh_rows and not multi:
    print("\n  t   houseFund = userCost unemp income  cat  lending credit clamp | px/fund | sentFund = misery moment fear rates energy cat   gap | index")
    for r in hh_rows:
        H_ = r['quarter_diagnostics']['households']
        hf, sf = obj(H_.get('houseFundamental')), obj(H_.get('sentimentFundamental'))
        ht, st_ = obj(hf.get('terms')), obj(sf.get('terms'))
        print(f"{r['total_time']:5.2f} {100*hf.get('average', 0):+7.2f}   {100*ht.get('userCost', 0):+6.2f} {100*ht.get('unemployment', 0):+5.2f} {100*ht.get('income', 0):+5.2f} {100*ht.get('catastropheDamage', 0):+5.2f} {100*ht.get('lendingStandards', 0):+6.2f} {100*ht.get('creditSupply', 0):+6.2f} {100*ht.get('clamp', 0):+5.2f} | {100*H_.get('housePriceToFundamental', 0):+6.2f} | "
              f"{sf.get('average', 0):6.1f}  {st_.get('misery', 0):+6.1f} {st_.get('momentum', 0):+6.1f} {st_.get('fear', 0):+5.1f} {st_.get('rates', 0):+5.1f} {st_.get('energy', 0):+6.1f} {st_.get('catastrophe', 0):+5.1f} {st_.get('outputGap', 0):+5.1f} | {r['consumer_sentiment_index']:5.1f}")

D = [r['quarter_diagnostics'] for r in rows if r.get('quarter_diagnostics')]
gaps = [d['averages']['outputGap'] for d in D]
def sd(x): m = sum(x)/len(x); return math.sqrt(sum((v-m)**2 for v in x)/len(x))
def acf(x, k): m = sum(x)/len(x); v = sum((a-m)**2 for a in x); return sum((x[i]-m)*(x[i-k]-m) for i in range(k, len(x)))/v
print(f"\ngap (quarter avg): mean {100*sum(gaps)/len(gaps):+.2f} sd {100*sd(gaps):.2f} min {100*min(gaps):+.2f} max {100*max(gaps):+.2f} ACF4 {acf(gaps,4):+.2f} ACF8 {acf(gaps,8):+.2f}"
      f"  | CBO 1985-2019: sd 1.63, ACF4 .65, ACF8 .27")
infl = [d['averages']['inflation'] for d in D]
print(f"inflation: mean {100*sum(infl)/len(infl):.2f} sd {100*sd(infl):.2f} | policy mean {100*sum(d['averages']['policyRate'] for d in D)/len(D):.2f}")
cons = defaultdict(float)
for d in D:
    for k, v in obj(d['policy']['constraints']).items(): cons[k] += v / len(D)
print('share of run under each constraint:', {k: f"{v:.0%}" for k, v in cons.items()})
ev = defaultdict(lambda: [0, 0.0])
for d in D:
    for k, v in obj(d['events']).items(): ev[k][0] += v['count']; ev[k][1] += v['sum']
print('events over the run:', {k: v[0] for k, v in sorted(ev.items())})
seen = [obj(d['policy'].get('terms')).get('productivitySeenThrough') for d in D if d.get('policy')]
seen = [x for x in seen if x is not None]
if seen:
    print(f"rule's productivity see-through: sd {100*sd(seen):.2f}pp, |x|>1pp in {sum(abs(x) > 0.01 for x in seen)/len(seen):.0%} of quarters")
if lab_rows:
    L = [r['quarter_diagnostics']['labour'] for r in lab_rows]
    print(f"labour: firing {sum(l.get('firingShare', 0) for l in L)/len(L):.0%} of the time, downward-rigid wages {sum(l.get('downwardRigidShare', 0) for l in L)/len(L):.0%}, "
          f"NAIRU scarred {100*sum(obj(obj(l.get('nairu')).get('terms')).get('scarring', 0) for l in L):.2f}pp and healed {100*sum(obj(obj(l.get('nairu')).get('terms')).get('healing', 0) for l in L):+.2f}pp over the run")
for sec, key in (('inflation', 'terms'), ('policy', 'terms')):
    t = defaultdict(float)
    for d in D:
        for k, v in obj(d[sec][key]).items(): t[k] += v / len(D)
    print(f'{sec} terms, run average (pp):', {k: round(100*v, 2) for k, v in t.items()})


# --- Weirdness screen -------------------------------------------------------------------------------------------
# Reads a live dump (macro_report column names) or a DiagnosticsRunTest file (wire keys, several seeds) alike.
def get(r, *keys):
    for k in keys:
        if r.get(k) is not None:
            return r[k]
    return None

flags = defaultdict(list)
def flag(name, r, detail=''):
    flags[name].append((r.get('seed'), round(r['total_time'], 2), detail))

by_seed = defaultdict(list)
for r in rows:
    by_seed[r.get('seed')].append(r)

STUCK = ['policy_rate', 'inflation', 'unemployment_rate', 'macro_credit_spread', 'high_yield_credit_spread', 'excess_bond_premium',
         'energy_price_index', 'sovereign_debt_to_gdp', 'consumer_sentiment_index', ('yield10y', 'yield_10y')]
years_run = 0.0
for seed, rs in by_seed.items():
    years_run += rs[-1]['total_time'] - rs[0]['total_time']
    prev_fp = None
    runs = defaultdict(int)
    for i, r in enumerate(rs):
        fp = r.get('config_fingerprint')
        if prev_fp is not None and fp != prev_fp:
            flag('constants changed mid-run', r, f'{prev_fp}->{fp}')
        prev_fp = fp
        for k, v in r.items():
            if isinstance(v, float) and (math.isnan(v) or math.isinf(v)):
                flag('NaN/inf in a field', r, k)
        g, d = r.get('gap_channels'), r.get('quarter_diagnostics')
        if i > 0 and not d:
            flag('quarter without diagnostics', r)
        if g:
            if abs(g.get('external', 0.0)) > 1e-9: flag('gap moved between ticks (external)', r, f"{100*g['external']:+.3f}pp")
            if abs(g['unexplained']) > 1e-9: flag('gap unexplained', r, f"{100*g['unexplained']:+.3f}pp")
            if abs(g['clamp']) > 1e-12: flag('gap hit its clamp', r, f"{100*g['clamp']:+.3f}pp")
        if d and d.get('inflation') and d.get('policy'):
            a = d['averages']; inf = d['inflation']; pol = d['policy']; cons = obj(pol.get('constraints')); terms = obj(pol.get('terms'))
            gap, infl, rate = a['outputGap'], a['inflation'], a['policyRate']
            if abs(sum(obj(inf['terms']).values()) - inf['average']) > 1e-9: flag('inflation account does not close', r)
            if pol.get('averageTarget') is not None and abs(sum(terms.values()) - pol['averageTarget']) > 1e-9: flag('target account does not close', r)
            if cons.get('evansHold', 0) > 0 and gap > 0: flag('Evans hold while the gap is positive', r, f"gap {100*gap:+.2f}, held {cons['evansHold']:.0%}")
            if cons.get('lowerBound', 0) > 0 and gap > 0.005: flag('at the lower bound in a boom', r, f"gap {100*gap:+.2f}")
            if terms.get('qeShadow', 0) < -0.005 and gap > 0: flag('QE shadow still >50bp with a positive gap', r, f"qe {100*terms['qeShadow']:+.2f}, gap {100*gap:+.2f}")
            if a.get('policyRate', 0) < a.get('targetRate', 0) - 0.015 and cons.get('lowerBound', 0) == 0 and gap > 0: flag('rate >150bp under the rule off the floor in a boom', r, f"rate {100*rate:.2f} rule {100*a['targetRate']:.2f}")
            if cons.get('targetCap', 0) > 0: flag('target above the 20% cap', r)
            if cons.get('panicSpeed', 0) > 0: flag('inflation panic speed engaged', r, f"{cons['panicSpeed']:.0%}")
            if rate > 0.10: flag('policy rate above 10%', r, f"{100*rate:.2f}")
            if infl > 0.05 or infl < 0.0: flag('inflation outside 0-5%', r, f"{100*infl:.2f}")
            if gap > 0.04 or gap < -0.06: flag('gap outside -6..+4%', r, f"{100*gap:+.2f}")
            y10 = a.get('yield10y')
            if y10 is not None and y10 - rate < -0.015: flag('curve inverted by >150bp', r, f"10y-policy {100*(y10-rate):+.2f}")
            if y10 is not None and y10 < 0.005: flag('10y below 0.5%', r, f"{100*y10:.2f}")
            if a['highYieldCreditSpread'] > 0.12: flag('HY spread above 12%', r, f"{100*a['highYieldCreditSpread']:.2f}")
            if a['excessBondPremium'] > 0.03: flag('bond premium above 3pp', r, f"{100*a['excessBondPremium']:.2f}")
            if a['unemploymentRate'] > 0.09 or a['unemploymentRate'] < 0.03: flag('unemployment outside 3-9%', r, f"{100*a['unemploymentRate']:.2f}")
        # Satellite accounts: level accounts close exactly; the unemployment flows rebuild the change between two
        # quarter-end rows up to the 4dp column rounding (a harness file carries full floats).
        for section in ('labour', 'households'):
            for name, acct in obj(obj(d).get(section)).items():
                if isinstance(acct, dict) and 'average' in acct and abs(sum(obj(acct.get('terms')).values()) - acct['average']) > 1e-9:
                    flag(f'{section}.{name} account does not close', r)
        un = obj(obj(obj(d).get('labour')).get('unemployment'))
        if un and i > 0 and rs[i - 1].get('unemployment_rate') is not None and abs(r['unemployment_rate'] - rs[i - 1]['unemployment_rate'] - un['change']) > 1.5e-4:
            flag('unemployment flows do not rebuild the change', r, f"{100*(r['unemployment_rate'] - rs[i - 1]['unemployment_rate']):+.3f} vs {100*un['change']:+.3f}pp")
        debt = get(r, 'sovereign_debt_to_gdp')
        if debt is not None and (debt > 1.5 or debt < 0.4): flag('debt/GDP outside 40-150%', r, f"{debt:.2f}")
        for k in STUCK:
            keys = k if isinstance(k, tuple) else (k,)
            v = get(r, *keys); pv = get(rs[i - 1], *keys) if i > 0 else None
            name = keys[0]
            if v is not None and v == pv and not (name == 'policy_rate' and v <= -0.0049):
                runs[name] += 1
                if runs[name] == 8: flag('series frozen for 8+ quarters', r, name)
            else:
                runs[name] = 0

counts = Counter()
for d in D:
    for k, v in obj(d['events']).items(): counts[k] += v['count']
print(f"\n=== weirdness screen: {len(by_seed)} run(s), {years_run:.0f} simulated years ===")
century = 100.0 / max(years_run, 1e-9)
print(f"per century: demand disasters {counts['demandDisaster']*century:.1f} (λ 0.20/yr → 20), credit crises {counts['creditCrisis']*century:.1f} (District ~3.2 since 2026-09-28's JRST funding term; JST panel ~2.3), "
      f"curve-inversion alarms {counts['systemic.yield_curve_inversion_alarm']*century:.0f} quarters, "
      f"liquidity freezes {counts['systemic.systemic_liquidity_freeze']*century:.1f}")
if not flags:
    print('no flags')
for name, hits in sorted(flags.items(), key=lambda x: -len(x[1])):
    share = len(hits) / max(len(D), 1)
    examples = '; '.join(f"seed {s} t{t} {det}".strip() for s, t, det in hits[:3])
    print(f"  {len(hits):5d} ({share:5.1%} of quarters)  {name}   e.g. {examples}")
