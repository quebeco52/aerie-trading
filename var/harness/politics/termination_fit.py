"""How often cabinets fall between elections, from ParlGov (view_cabinet.csv in parlgov/): Scandinavian cabinets since
1945. A spell is a run of cabinets with the same parties seated by the same election (a new prime minister or a
reshuffle is not a fall); it ends in a fall when the next cabinet has other parties and no election came between, and
is censored when an election ends it (the Diet's calendar is fixed, so early elections have no place here). A constant
hazard per year of office (King, Alt, Burns & Laver 1990), minority and majority cabinets apart.
python3 termination_fit.py"""
import csv, collections, datetime, os, math
D = os.path.join(os.path.dirname(__file__) or '.', 'parlgov')
REGIONS = {'Scandinavia': {'DNK', 'SWE', 'NOR'}, 'Norway': {'NOR'},
           'Western Europe': {'AUT', 'BEL', 'CHE', 'DEU', 'DNK', 'ESP', 'FIN', 'FRA', 'GBR', 'GRC', 'IRL', 'ISL', 'ITA', 'LUX', 'MLT', 'NLD', 'NOR', 'PRT', 'SWE'}}
cab = collections.defaultdict(list)
for r in csv.DictReader(open(f'{D}/view_cabinet.csv')):
    cab[r['cabinet_id']].append(r)
def date(s): return datetime.date.fromisoformat(s)
for label, countries in REGIONS.items():
    by_country = collections.defaultdict(list)
    for cid, rs in cab.items():
        r = rs[0]
        if r['country_name_short'] in countries and r['start_date'] >= '1945-01-01':
            parties = frozenset(x['party_id'] for x in rs if x['cabinet_party'] == '1')
            seats = sum(int(x['seats'] or 0) for x in rs if x['cabinet_party'] == '1')
            total = int(r['election_seats_total'] or 0) or sum(int(x['seats'] or 0) for x in rs)
            by_country[r['country_name_short']].append(dict(start=date(r['start_date']), election=r['election_id'], parties=parties,
                                                            caretaker=r['caretaker'] == '1', minority=2 * seats <= total))
    exposure = collections.Counter(); falls = collections.Counter(); spells = collections.Counter()
    for cs in by_country.values():
        cs.sort(key=lambda c: c['start'])
        cs = [c for c in cs if not c['caretaker']]
        i = 0
        while i < len(cs) - 1:
            j = i
            while j + 1 < len(cs) and cs[j + 1]['election'] == cs[i]['election'] and cs[j + 1]['parties'] == cs[i]['parties']:
                j += 1
            if j + 1 >= len(cs): break
            nxt = cs[j + 1]
            kind = 'minority' if cs[i]['minority'] else ('single-party majority' if len(cs[i]['parties']) == 1 else 'majority coalition')
            exposure[kind] += (nxt['start'] - cs[i]['start']).days / 365.25
            spells[kind] += 1
            if nxt['election'] == cs[i]['election']:
                falls[kind] += 1
            i = j + 1
    for kind in ('minority', 'majority coalition', 'single-party majority'):
        if not exposure[kind]: continue
        h = falls[kind] / exposure[kind]
        print(f"{label:14s} {kind:22s}: {falls[kind]:3d} falls in {exposure[kind]:6.1f} cabinet-years ({spells[kind]} spells)  hazard {h:.4f}/yr "
              f"(se {math.sqrt(falls[kind]) / exposure[kind]:.4f}); over a 4-year term {1 - math.exp(-4 * h):.2f}")
