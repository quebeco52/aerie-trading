"""Input-cost exposures from the BEA input-output accounts (Leontief price model, exogenous commodity prices).

Industry-technology direct requirements A = D.B from the 2017 detail Make and Use tables (before redefinitions, producers'
prices). Commodity-producing industries whose prices the macro sets are exogenous nodes X; every other industry passes its
costs through. An industry's cost per $ of output moves with exogenous price x by E_xj = A_xj + A_xN (I - A_NN)^-1 A_Nj
(Miller & Blair 2009, s2.6). Retail pass-through of wholesale power and gas is fitted on EIA monthly data. Exposures are
divided by the industry's variable cost ratio (intermediate inputs + compensation over output), the models' own unit.

Usage: python3 io_weights.py [--php OUT.php]   (reads io_data.json and power_data.json, written by io_data.py / power_real.py)
"""
import json, math, os, re, statistics as st, sys
HERE = os.path.dirname(os.path.abspath(__file__))
PROJECT = '/home/quebeco/Projects/Code/Private/aerie-trading'
d = json.load(open(os.path.join(HERE, 'io_data.json')))
pw = json.load(open(os.path.join(HERE, 'power_data.json')))

# --- Retail pass-through (12-month trailing means, log-log with a linear trend: the engine applies general inflation) ---
months = [f'{y}-{m:02d}' for y in range(2017, 2026) for m in range(1, 13)]
def trail(x, w=12): return [st.mean(x[i - w + 1:i + 1]) for i in range(w - 1, len(x))]
def elasticity_with_trend(retail, wholesale):
    y = [math.log(v) for v in trail(retail)]; x1 = [math.log(v) for v in trail(wholesale)]; x2 = [i / 12 for i in range(len(y))]
    m1, m2, my = st.mean(x1), st.mean(x2), st.mean(y)
    s11 = sum((a - m1) ** 2 for a in x1); s22 = sum((b - m2) ** 2 for b in x2); s12 = sum((a - m1) * (b - m2) for a, b in zip(x1, x2))
    s1y = sum((a - m1) * (c - my) for a, c in zip(x1, y)); s2y = sum((b - m2) * (c - my) for b, c in zip(x2, y))
    return (s1y * s22 - s2y * s12) / (s11 * s22 - s12 * s12)
wholesale_power = [st.mean(pw['power'][f'{h}|{k}'] for h in pw['hubs']) for k in months]
EPS_POWER = elasticity_with_trend([d['industrial_power_price'][k] for k in months], wholesale_power)
EPS_GAS = elasticity_with_trend([d['industrial_gas_price'][k] for k in months], [pw['henry_hub'][k] for k in months])

# --- Direct requirements, industry technology ---
use, make = d['use'], d['make']
industries = [c for c in d['use_cols'] if c in make and c in use['T008'] and use['T008'][c] > 0]
commodities = [r for r in d['use_rows'] if r not in ('T005', 'V00100', 'V00200', 'V00300', 'T006', 'T008')]
X = {j: use['T008'][j] for j in industries}
Q = make['T007']
idx = {j: i for i, j in enumerate(industries)}
n = len(industries)
A = [[0.0] * n for _ in range(n)]
# Scrap is a by-product the Make table credits to whoever generates it (state and local government, waste management),
# but its price follows the metals cycle: 23% of iron and steel mills' output, 26% of secondary aluminium's. It is priced
# as a metals commodity directly, not passed through the industries that happen to shed it.
SCRAP = 'S00401'
for c in commodities:
    q = Q.get(c, 0.0)
    if q <= 0 or c == SCRAP:
        continue  # noncomparable imports and adjustments: no domestic producer to pass a price through
    producers = [(idx[i], make[i][c] / q) for i in industries if make[i].get(c, 0.0) > 0]
    for j in industries:
        b = use[c].get(j, 0.0) / X[j]
        if b:
            col = idx[j]
            for i, share in producers:
                A[i][col] += share * b

# Generator fuel: BEA's detail table books electric power's gas at 1.6% and oil at 2.4% of output. EIA's fuel receipts for
# 2017 (Electric Power Annual Table 7.4: 9,952 TBtu of gas at $3.37, 190 TBtu of petroleum at $7.10) are 7.4% and 0.3%, so
# the measured fuel bill replaces BEA's direct entries for that column.
EIA_GENERATOR_FUEL_2017 = {'211000': 9952e6 * 3.37 / 1e6, '324110': 190e6 * 7.10 / 1e6}  # $ million
for code, dollars in EIA_GENERATOR_FUEL_2017.items():
    A[idx[code]][idx['221100']] = dollars / X['221100']

# --- Exogenous nodes and their macro channels ---
def channel_of(code):
    if code == '324110': return 'energy'
    if code in ('211000', '221200'): return 'gas'
    if code == '221100': return 'electricity'
    if code.startswith('331') or code.startswith('2122'): return 'metals'
    if code.startswith('111') or code.startswith('112'): return 'agri'
    if code == '483000': return 'freight'
    return None
exo = [j for j in industries if channel_of(j)]
endo = [j for j in industries if not channel_of(j)]
ei = [idx[j] for j in exo]; ni = [idx[j] for j in endo]

# LU of (I - A_NN), then Y = (I - A_NN)^-1 A_N,J for the columns the models need.
m = len(ni)
M = [[(1.0 if r == c else 0.0) - A[ni[r]][ni[c]] for c in range(m)] for r in range(m)]
piv = list(range(m))
for k in range(m):
    p = max(range(k, m), key=lambda r: abs(M[r][k]))
    M[k], M[p] = M[p], M[k]; piv[k], piv[p] = piv[p], piv[k]
    mk = M[k]; inv = 1.0 / mk[k]
    for r in range(k + 1, m):
        mr = M[r]
        f = mr[k] * inv
        if f:
            mr[k] = f
            for c in range(k + 1, m):
                mr[c] -= f * mk[c]
def solve(rhs):
    b = [rhs[piv[r]] for r in range(m)]
    for r in range(m):
        mr = M[r]; s = b[r]
        for c in range(r): s -= mr[c] * b[c]
        b[r] = s
    for r in range(m - 1, -1, -1):
        mr = M[r]; s = b[r]
        for c in range(r + 1, m): s -= mr[c] * b[c]
        b[r] = s / mr[r]
    return b

def exposures(j):
    col = idx[j]
    y = solve([A[ni[r]][col] for r in range(m)])
    scrap = use.get(SCRAP, {})
    out = {'metals': {SCRAP: scrap.get(j, 0.0) / X[j] + sum(scrap.get(endo[r], 0.0) / X[endo[r]] * y[r] for r in range(m) if y[r])}}
    for code, e in zip(exo, ei):
        v = A[e][col] + sum(A[e][ni[r]] * y[r] for r in range(m) if y[r])
        ch = channel_of(code)
        if code == '221100': v *= EPS_POWER
        if code == '221200': v *= EPS_GAS
        out.setdefault(ch, {}); out[ch][code] = v
    return out

def variable_cost_ratio(j):
    return (use['T005'].get(j, 0.0) + use['V00100'].get(j, 0.0)) / X[j]

# --- Model -> BEA detail industries, weighted by the firms each model carries (src/Data/Company/InitialMarket.php) ---
# One group per listed firm industry, equal weight per firm; a group's codes share its weight equally.
MAP = {
    'ADVERTISING_AGENCY': [['541800']],                                                    # LYRE
    'APPAREL_MANUFACTURING': [['315000']],                                                 # SHER
    'AUTO_MANUFACTURER': [['336111', '336112']] * 2,                                       # FALC, EIDR
    'BIOTECH': [['325412', '325414']],                                                     # IBIS
    'CHEMICAL': [['325110', '325190', '325211', '325310', '325320', '3259A0']],            # FULM (petrochemicals, specialty, agrochemicals)
    'COMMUNICATION_EQUIPMENT': [['334210', '334220']],                                     # ERNE
    'COMPUTER_HARDWARE': [['334111', '334112', '334118']],                                 # PENG
    'CONGLOMERATE': [['33399A', '335312', '336413', '3259A0']] * 3,                        # OWLS, TRIV, HARR (inherited by BRKW, RAVN)
    'CONSTRUCTION': [['2332A0', '2332C0', '233230', '2332D0', '233240']],                  # IBHI (engineering and construction)
    'CONSUMER_STAPLES': [['312200'], ['311300', '311410', '311230'], ['112300', '1111B0'], ['325620', '325610'], ['312130', '312140']],  # PHIL, SGRB, CROP, LARK, PINT
    'DEFENSE_CONTRACTOR': [['336411', '336412', '336413', '336414', '334511']] * 2,       # GRIP, PTAR
    'EDUCATION': [['611A00', '611B00']],                                                   # STAR
    'FINANCIAL_DATA': [['523A00', '518200']] * 2,                                          # SHRK, TICK
    'HEAVY_MANUFACTURING': [['333415']] * 2 + [['333111', '333120']] * 2 + [['335312', '335313']],  # NUTH, WREN; BUZT, CHUF; BOBY
    'INTERNET_RETAIL': [['454000']],                                                       # WEAV
    'LAW_FIRM': [['541100']],                                                              # CLAW
    'LOGISTICS': [['484000', '492000', '493000']],                                         # CANV
    'LUXURY': [['339910', '316000']],                                                      # PEAC
    'MEDICAL_CARE_FACILITY': [['622000', '621400', '623A00']],                             # CRAN
    'MINING': [['212230', '2122A0', '212100', '2123A0']],                                  # CNDR (base metals, gold, coal, potash)
    'OIL_GAS_PRODUCER': [['211000']],                                                      # SINK
    'RAILROAD': [['482000']] * 2,                                                          # KSTL, REDW
    'REIT': [['531HST'], ['531ORE'], ['531ORE'], ['531ORE']],                              # SHOR; PLZA, ELDE, KITE
    'RESORTS_CASINOS': [['721000', '713200']],                                             # GULL
    'RESTAURANT': [['722110', '722211']] * 2,                                              # BREW, SWFT
    'SECURITY_PROTECTION': [['561600']] * 2,                                               # WATCH, OSPR
    'SEMICONDUCTOR': [['334413']],                                                         # SILC
    'SHIPPING': [['483000']] * 2,                                                          # ALBT, GANN
    'SPECIALTY_INDUSTRIAL_MACHINERY': [['33329A', '33399A', '333993', '33399B']] * 3,      # RIVE, SNDR, ALCA
    'STEEL_MANUFACTURING': [['331110', '331200']],                                         # WING
    'TECH': [['519130', '518200']],                                                        # HUMM
    'TELECOM': [['517110', '517210']],                                                     # LOON
    'TOOLS_AND_ACCESSORIES': [['332200'], ['423800']],                                     # CBIL; MAGP
    'UTILITY': [['221100'], ['221300']],                                                   # BIRD, WADE
    'WASTE_MANAGEMENT': [['562000']],                                                      # CORM
}
# Inputs a model already prices in its own physics, so the basket must not count them again.
EXCLUDE = {
    'CHEMICAL': {'channels': ['energy', 'gas']},             # hydrocarbon feedstock: ChemicalBusinessModel's crack squeeze
}
# A producer's purchases of the commodity it sells (ore bought by miners, alumina within aluminium, steel between mills, gas
# between producers, charter between carriers) move with its own selling price, which the model carries on the revenue side.
OWN_COMMODITY_PRODUCERS = ['MINING', 'OIL_GAS_PRODUCER', 'STEEL_MANUFACTURING', 'SHIPPING']
for model in OWN_COMMODITY_PRODUCERS:
    EXCLUDE.setdefault(model, {}).setdefault('codes', [])
    EXCLUDE[model]['codes'] += sorted({c for group in MAP[model] for c in group if channel_of(c)})
CLASS = {k: ''.join(w.capitalize() for w in k.split('_')) + 'BusinessModel' for k in MAP}
CLASS['TOOLS_AND_ACCESSORIES'] = 'ToolsAndAccessoriesBusinessModel'
CHANNELS = ['energy', 'gas', 'electricity', 'metals', 'agri', 'freight']
# Exposures under 2% of the variable cost base are dropped: a one-sigma commodity move (~30%) on them shifts costs by under 0.6%.
MATERIALITY_FLOOR = 0.02

def current_exposures(cls):
    # The hand-set baskets as committed before this calibration (the models now point at the generated class).
    import subprocess
    src = subprocess.run(['git', '-C', PROJECT, 'show', f'ddffc3c:src/Service/Model/Sector/{cls}.php'], capture_output=True, text=True, check=True).stdout
    mm = re.search(r"INPUT_COST_EXPOSURES\s*=\s*\[([^\]]*)\]", src)
    return {k: float(v) for k, v in re.findall(r"'(\w+)'\s*=>\s*([0-9.]+)", mm.group(1))} if mm else {}

missing = sorted({c for groups in MAP.values() for group in groups for c in group if c not in idx})
assert not missing, f'unknown BEA codes: {missing}'

results = {}
cache = {}
for model, groups in MAP.items():
    ex = EXCLUDE.get(model, {})
    tot = {ch: 0.0 for ch in CHANNELS}; vc = 0.0; pay = 0.0
    for group in groups:
        w = 1.0 / (len(groups) * len(group))
        for j in group:
            if j not in cache:
                cache[j] = exposures(j)
            for ch, bycode in cache[j].items():
                if ch in ex.get('channels', []):
                    continue
                tot[ch] += w * sum(v for code, v in bycode.items() if code not in ex.get('codes', []))
            vc += w * variable_cost_ratio(j)
            pay += w * use['V00100'].get(j, 0.0) / X[j]
    shares = {ch: tot[ch] / vc for ch in CHANNELS}   # (weighted exposure) / (weighted variable cost ratio)
    # Labor is the industry's OWN pay: suppliers' pay reaches it through supplier prices, which move with the aggregate
    # price level the real wage gap is measured against.
    shares['labor'] = pay / vc
    codes = sorted({c for group in groups for c in group})
    results[model] = {'codes': codes, 'vc': vc, 'shares': shares, 'old': current_exposures(CLASS[model])}

print(f'retail pass-through (12-month means, with trend): power {EPS_POWER:.3f}, gas {EPS_GAS:.3f}; industries {n}, exogenous {len(exo)}, endogenous {m}')
print(f"{'model':32s} {'vc':>5s} | " + ' '.join(f'{c[:6]:>13s}' for c in CHANNELS + ['labor']) + ' |  ppi old')
for model, r in results.items():
    cells = ' '.join(f"{r['old'].get(ch, 0):5.2f}->{r['shares'][ch]:6.3f}" for ch in CHANNELS + ['labor'])
    print(f"{model:32s} {r['vc']:5.2f} | {cells} | {r['old'].get('ppi', 0):7.2f}")
json.dump({'eps_power': EPS_POWER, 'eps_gas': EPS_GAS, 'results': results}, open(os.path.join(HERE, 'io_weights.json'), 'w'), indent=1)


# --- PHP data class ---
LABELS = {
    'ADVERTISING_AGENCY': 'Advertising agencies', 'APPAREL_MANUFACTURING': 'Apparel manufacturing (SHER)',
    'AUTO_MANUFACTURER': 'Automobile and light truck assembly', 'BIOTECH': 'Pharmaceutical and biological products',
    'CHEMICAL': 'Petrochemicals, organics, resins, fertilizer and agricultural chemicals (hydrocarbon feedstock priced in the model)',
    'COMMUNICATION_EQUIPMENT': 'Telephone, broadcast and wireless equipment', 'COMPUTER_HARDWARE': 'Computers, storage and peripherals (PENG)',
    'CONGLOMERATE': 'A diversified industrial blend: machinery, motors, aerospace parts, chemical products',
    'CONSTRUCTION': 'Nonresidential, infrastructure, manufacturing and power structures (IBHI)',
    'CONSUMER_STAPLES': 'Tobacco, packaged foods, poultry and grain, personal and household products, wine and spirits (PHIL, SGRB, CROP, LARK, PINT)',
    'DEFENSE_CONTRACTOR': 'Aircraft, engines, parts, missiles and navigation instruments', 'EDUCATION': 'Colleges and other educational services',
    'FINANCIAL_DATA': 'Securities intermediation and exchanges, data processing',
    'HEAVY_MANUFACTURING': 'HVAC equipment, farm and construction machinery, motors and switchgear (NUTH, WREN, BUZT, CHUF, BOBY)',
    'INTERNET_RETAIL': 'Nonstore retailers', 'LAW_FIRM': 'Legal services (CLAW)',
    'LOGISTICS': 'Trucking, couriers and warehousing', 'LUXURY': 'Jewelry and leather goods',
    'MEDICAL_CARE_FACILITY': 'Hospitals, outpatient care and nursing facilities',
    'MINING': 'Copper, gold, coal and potash mining (CNDR; own-commodity trade excluded)',
    'OIL_GAS_PRODUCER': 'Oil and gas extraction (own-commodity trade excluded)', 'RAILROAD': 'Rail transportation',
    'REIT': 'Tenant-occupied housing and other real estate (SHOR; PLZA, ELDE, KITE)', 'RESORTS_CASINOS': 'Accommodation and gambling (GULL)',
    'RESTAURANT': 'Full- and limited-service restaurants', 'SECURITY_PROTECTION': 'Investigation and security services',
    'SEMICONDUCTOR': 'Semiconductor devices (SILC)', 'SHIPPING': 'Water transportation (own-commodity trade excluded)',
    'SPECIALTY_INDUSTRIAL_MACHINERY': 'Industrial, general purpose, packaging and fluid power machinery',
    'STEEL_MANUFACTURING': 'Iron and steel mills and steel products (own-commodity trade excluded; scrap priced as metals)',
    'TECH': 'Internet publishing and data hosting (HUMM)', 'TELECOM': 'Wired and wireless carriers',
    'TOOLS_AND_ACCESSORIES': 'Hand tools (CBIL) and machinery wholesale (MAGP)',
    'UTILITY': 'Electric power (BIRD; generator fuel from EIA receipts) and water systems (WADE)', 'WASTE_MANAGEMENT': 'Waste management and remediation',
}
if '--php' in sys.argv:
    out = sys.argv[sys.argv.index('--php') + 1]
    lines = ['<?php', '', 'declare(strict_types=1);', '', 'namespace App\\Data\\Macro;', '', '/**',
             ' * Input-cost exposures measured from the BEA input-output accounts. Generated by var/harness/io_weights.py; regenerate, do not edit.',
             ' *',
             " * Leontief price model with the commodity prices the macro sets as exogenous (Miller & Blair 2009, s2.6): industry-technology",
             " * direct requirements from the 2017 detail Make and Use tables (402 industries, before redefinitions, producers' prices), and an",
             ' * industry\'s exposure to commodity x per $ of output E_xj = A_xj + A_xN (I - A_NN)^-1 A_Nj, which carries supply-chain content.',
             ' * Nodes: refined products -> energy; oil and gas extraction and gas distribution -> gas; electric power -> electricity; primary',
             ' * metals, metal ores and scrap -> metals; farms -> agri; water transport -> freight. Each exposure is divided by the',
             ' * industry\'s variable cost ratio (intermediate inputs plus compensation over output), the unit INPUT_COST_EXPOSURES is in;',
             ' * a model blends the industries of the firms it carries, one weight per firm.',
             ' * Two corrections to the tables: electric power\'s direct gas and oil are EIA\'s 2017 generator fuel receipts (BEA books under a',
             ' * quarter of them), and scrap, which the Make table credits to whoever sheds it, is priced as metals.',
             ' * Commodity exposures under MATERIALITY_FLOOR are dropped. Labor is the industry\'s own compensation over the same variable cost',
             ' * base and is priced by the real wage gap; suppliers\' pay is left out because it reaches a buyer through supplier prices, which',
             ' * move with the aggregate price level that gap is measured against. Refining is not listed: crude and refinery fuel gas share',
             ' * one BEA row.',
             ' */', 'final class InputOutputExposures', '{',
             '    // --- Retail Pass-Through (EIA monthly 2017-2025, 12-month means, log-log with a linear trend) ---',
             f'    /** Industrial retail electricity per unit of wholesale power (EIA-861M against the four-hub on-peak series); folded into electricity weights. */',
             f'    public const RETAIL_POWER_PASS_THROUGH = {EPS_POWER:.3f};',
             f'    /** Industrial retail gas per unit of Henry Hub (EIA N3035US3); folded into the gas-distribution part of gas weights. */',
             f'    public const RETAIL_GAS_PASS_THROUGH = {EPS_GAS:.3f};', '',
             '    // --- Materiality ---',
             '    /** Exposures under this share of the variable cost base are dropped: a one-sigma commodity move (~30%) on them shifts costs by under 0.6%. */',
             f'    public const MATERIALITY_FLOOR = {MATERIALITY_FLOOR};', '',
             '    // --- Model Exposures (share of the variable cost base per input channel) ---']
    for model in sorted(results):
        r = results[model]
        vals = {ch: round(r['shares'][ch], 3) for ch in CHANNELS if round(r['shares'][ch], 3) >= MATERIALITY_FLOOR}
        vals['labor'] = round(r['shares']['labor'], 3)
        body = ', '.join(f"'{k}' => {v:.3f}".rstrip('0').rstrip('.') if False else f"'{k}' => {v}" for k, v in vals.items())
        lines.append(f"    /** {LABELS[model]}: BEA {', '.join(r['codes'])}. */")
        lines.append(f'    public const {model} = [{body}];')
    lines += ['}', '']
    open(out, 'w').write('\n'.join(lines))
    print('wrote', out)
