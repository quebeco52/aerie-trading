"""Long-run mean of each priced law and its persistence from one term to the next (16 quarters), pooled over seeds.
Each law is measured as the earnings feel it (PolicyCapitalization): the tax shift and the levy as they stand, the rules on
extraction by their unit-cost factor (FirmEconomics::calculateExtractionCostFactor), the stamp duty by the share turnover
it leaves (MacroTransmission::calculateStampDutyVolumeFactor).
Run: python lever_means.py runs9/levers_*.jsonl   (older runs/levers_*.jsonl carry only 'tax' and 'levy')"""
import json, sys
import numpy as np

TFP_LOSS = 0.048            # FinancialConstants::ENVIRONMENTAL_REGULATION_TFP_LOSS
SEMI_ELASTICITY = 52.68     # FinancialConstants::STAMP_DUTY_VOLUME_SEMI_ELASTICITY
FOUNDING_DUTY = 0.0005      # FinancialConstants::STAMP_DUTY_RATE

MEASURES = {
    'corporateTax': ('tax', lambda x: x),
    'bankLevyRate': ('levy', lambda x: x),
    'extractionStringency': (None, lambda s: 1.0 / np.maximum(0.01, 1.0 - TFP_LOSS * s)),
    'stampDutyRate': (None, lambda t: np.exp(-SEMI_ELASTICITY * 2.0 * (t - FOUNDING_DUTY))),
}

rows = [json.loads(l) for f in sys.argv[1:] for l in open(f)]
seeds = sorted({r['seed'] for r in rows})
rows = [next(r for r in rows if r['seed'] == s) for s in seeds]
for lever, (old_key, measure) in MEASURES.items():
    key = lever if lever in rows[0] else old_key
    if key is None or key not in rows[0]:
        continue
    xs = [measure(np.array(r[key], dtype=float)) for r in rows]
    allx = np.concatenate(xs)
    mean = allx.mean()
    num = sum(((x[16:] - mean) * (x[:-16] - mean)).sum() for x in xs)
    den = sum(((x - mean) ** 2).sum() for x in xs)
    pairs = sum(len(x) - 16 for x in xs)
    rho = (num / pairs) / (den / len(allx))
    # Seed-level spread of the mean and of the persistence, for their standard errors.
    means = [x.mean() for x in xs]
    rhos = []
    for x in xs:
        m = x.mean()
        d = ((x - m) ** 2).mean()
        rhos.append(((x[16:] - m) * (x[:-16] - m)).mean() / d if d > 0 else np.nan)
    print(f"{lever}: seeds {len(xs)}, quarters {len(allx)}, mean {mean:.6f} (se {np.std(means) / np.sqrt(len(means)):.6f}), "
          f"sd {allx.std():.6f}, term persistence {rho:.3f} (sd across seeds {np.nanstd(rhos):.2f})")
