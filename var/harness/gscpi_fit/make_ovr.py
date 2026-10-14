#!/usr/bin/env python3
"""make_ovr.py L <factory loading>   -> ovrL: CommodityLogisticsSubsystem with DELIVERY_FACTORY_LOADING set
   make_ovr.py F <factory loading>   -> ovrF: that plus MacroTransmission GSCPI_* constants from fit.json
Copies are src-relative under var/harness/gscpi_fit/ovr<arm>/, loaded in front of src by gscpi.php's OVR autoloader."""
import json, os, re, sys

H = os.path.dirname(os.path.abspath(__file__))
SRC = os.path.abspath(os.path.join(H, '../../../src'))


def patch(rel, consts, arm):
    text = open(f'{SRC}/{rel}').read()
    for name, val in consts.items():
        text, n = re.subn(rf'(public const {name} = )[^;]+;', rf'\g<1>{val!r};', text)
        assert n == 1, name
    os.makedirs(os.path.dirname(f'{H}/ovr{arm}/{rel}'), exist_ok=True)
    open(f'{H}/ovr{arm}/{rel}', 'w').write(text)


arm, fl = sys.argv[1], float(sys.argv[2])
if arm == 'R':  # raw inputs, own regressor per input: constants from fit2.json (means already folded); loading is in src
    f = json.load(open(f'{H}/fit2.json'))
    c = {'GSCPI_FREIGHT_MEAN': f['mean']['fr'], 'GSCPI_FREIGHT_SD': f['sd']['fr'],
         'GSCPI_INVENTORY_GAP_MEAN': f['mean']['inv'], 'GSCPI_INVENTORY_GAP_SD': f['sd']['inv'],
         'GSCPI_DELIVERY_MEAN': f['mean']['del'], 'GSCPI_DELIVERY_SD': f['sd']['del'],
         'GSCPI_FREIGHT_DEMAND_BETA': f['beta']['fr'], 'GSCPI_INVENTORY_DEMAND_BETA': f['beta']['inv'],
         'GSCPI_DELIVERY_DEMAND_BETA': f['beta']['del'], 'GSCPI_FREIGHT_WEIGHT': f['weight']['fr'],
         'GSCPI_INVENTORY_WEIGHT': f['weight']['inv'], 'GSCPI_DELIVERY_WEIGHT': f['weight']['del'],
         'GSCPI_WEIGHTED_SD': f['weighted_sd']}
    c = {k: float(f'{v:.4g}') for k, v in c.items()}
    patch('Service/Math/MacroTransmission.php', c, arm)
    print(json.dumps(c)); sys.exit(0)
patch('Service/Macro/Subsystem/CommodityLogisticsSubsystem.php', {'DELIVERY_FACTORY_LOADING': round(fl, 2)}, arm)
if arm in ('F', 'G'):
    f = json.load(open(f'{H}/fit.json'))
    field = {'og': 'outputGapEma', 'gg': 'globalDemandGapEma', 'dg': 'domesticDemandGapEma'}[f['regressor']]
    cl = f'{H}/ovr{arm}/Service/Macro/Subsystem/CommodityLogisticsSubsystem.php'
    text = open(cl).read(); assert text.count('demandGap: $state->outputGapEma') == 1
    open(cl, 'w').write(text.replace('demandGap: $state->outputGapEma', f'demandGap: $state->{field}'))
    c = {'GSCPI_FREIGHT_MEAN': f['mean']['fr'], 'GSCPI_FREIGHT_SD': f['sd']['fr'],
         'GSCPI_INVENTORY_GAP_MEAN': f['mean']['inv'], 'GSCPI_INVENTORY_GAP_SD': f['sd']['inv'],
         'GSCPI_DELIVERY_MEAN': f['mean']['del'], 'GSCPI_DELIVERY_SD': f['sd']['del'],
         'GSCPI_FREIGHT_DEMAND_BETA': f['beta']['fr'], 'GSCPI_INVENTORY_DEMAND_BETA': f['beta']['inv'],
         'GSCPI_DELIVERY_DEMAND_BETA': f['beta']['del'], 'GSCPI_FREIGHT_WEIGHT': f['weight']['fr'],
         'GSCPI_INVENTORY_WEIGHT': f['weight']['inv'], 'GSCPI_DELIVERY_WEIGHT': f['weight']['del'],
         'GSCPI_WEIGHTED_SD': f['weighted_sd']}
    if arm == 'G':  # fold the purge's mean shift beta * mean(gap) into the standardizing means, so each purged z has mean 0
        gm = f['gap_mean']
        c['GSCPI_FREIGHT_MEAN'] -= f['sd']['fr'] * f['beta']['fr'] * gm
        c['GSCPI_INVENTORY_GAP_MEAN'] += f['sd']['inv'] * f['beta']['inv'] * gm
        c['GSCPI_DELIVERY_MEAN'] -= f['sd']['del'] * f['beta']['del'] * gm
    patch('Service/Math/MacroTransmission.php', {k: float(f'{v:.4g}') for k, v in c.items()}, arm)
    print(json.dumps({k: float(f'{v:.4g}') for k, v in c.items()}))
