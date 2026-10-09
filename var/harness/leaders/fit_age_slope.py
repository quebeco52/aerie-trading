"""Log-linear exit hazard in a leader's CURRENT age, h(a) = exp(alpha + beta * a), fitted by maximum likelihood on the
COSPAL Western European leader spells (data/cospal_we_spells.csv), each spell running from its age at selection to its
end. Also reports the centring age c with exposure-weighted mean of exp(beta * (a - c)) equal to one, so status hazards
measured over all ages keep their averages. Pure Python (no numpy here)."""
import csv, math

def load(exclude):
    spells = []
    for r in csv.DictReader(open('var/harness/leaders/data/cospal_we_spells.csv')):
        if r['age_at_selection'] == '' or r['country'] in exclude:
            continue
        a0 = float(r['age_at_selection']) + 0.5
        spells.append((a0, a0 + float(r['years']), r['exit_observed'] == '1'))
    return spells

def fit(spells):
    def ll(al, be):
        s = 0.0
        for a0, a1, ev in spells:
            s += (al + be * a1 if ev else 0.0) - math.exp(al) * (math.exp(be * a1) - math.exp(be * a0)) / be
        return s
    al, be = math.log(0.15) - 0.03 * 50, 0.03
    for _ in range(200):  # Newton with numerical derivatives
        h = 1e-5
        g1 = (ll(al + h, be) - ll(al - h, be)) / (2 * h)
        g2 = (ll(al, be + h) - ll(al, be - h)) / (2 * h)
        h11 = (ll(al + h, be) - 2 * ll(al, be) + ll(al - h, be)) / h ** 2
        h22 = (ll(al, be + h) - 2 * ll(al, be) + ll(al, be - h)) / h ** 2
        h12 = (ll(al + h, be + h) - ll(al + h, be - h) - ll(al - h, be + h) + ll(al - h, be - h)) / (4 * h * h)
        det = h11 * h22 - h12 * h12
        da, db = (h22 * g1 - h12 * g2) / det, (h11 * g2 - h12 * g1) / det
        al, be = al - da, be - db
        if abs(da) + abs(db) < 1e-10:
            break
    se = math.sqrt(-h11 / det)
    # centring age: exposure-weighted mean of exp(beta * a) over all leader-years
    num = sum((math.exp(be * a1) - math.exp(be * a0)) / be for a0, a1, _ in spells)
    expo = sum(a1 - a0 for a0, a1, _ in spells)
    c = math.log(num / expo) / be
    return al, be, se, c, len(spells), sum(ev for *_, ev in spells), expo

for label, excl in (('WE8 excluding Belgium', {'BEL'}), ('all WE8', set())):
    al, be, se, c, n, ev, expo = fit(load(excl))
    print('%-22s n=%d exits=%d exposure=%.0fy  beta=%.4f/yr (se %.4f)  HR/yr=%.4f  HR/10yr=%.2f  centre c=%.1f  rate at c=%.3f'
          % (label, n, ev, expo, be, se, math.exp(be), math.exp(10 * be), c, math.exp(al + be * c)))
