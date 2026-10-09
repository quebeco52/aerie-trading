"""What real hung parliaments seated, from ParlGov (https://www.parlgov.org/data/parlgov-development_csv-utf-8/
view_cabinet.csv and view_party.csv, in parlgov/): the first non-caretaker cabinet after each post-1945 election in
which no party held a majority. Scandinavia (Denmark, Sweden, Norway) is the bloc-parliament target; Western Europe is
shown for reference. Radical parties are ParlGov's Communist/Socialist and Right-wing families.
python3 parlgov_targets.py"""
import csv, collections, os
D = os.path.join(os.path.dirname(__file__) or '.', 'parlgov')
fam = {r['party_id']: r['family_name'] for r in csv.DictReader(open(f'{D}/view_party.csv'))}
rows = list(csv.DictReader(open(f'{D}/view_cabinet.csv')))
RADICAL = {'Communist/Socialist', 'Right-wing'}
REGIONS = {
    'Scandinavia': {'DNK', 'SWE', 'NOR'},
    'Western Europe': {'AUT', 'BEL', 'CHE', 'DEU', 'DNK', 'ESP', 'FIN', 'FRA', 'GBR', 'GRC', 'IRL', 'ISL', 'ITA', 'LUX', 'MLT', 'NLD', 'NOR', 'PRT', 'SWE'},
}
for label, countries in REGIONS.items():
    cabinets = collections.defaultdict(list)
    for r in rows:
        if r['country_name_short'] in countries and r['start_date'] >= '1945-01-01':
            cabinets[r['cabinet_id']].append(r)
    first = collections.defaultdict(list)
    for cid, rs in cabinets.items():
        if rs[0]['caretaker'] != '1':
            first[rs[0]['election_id']].append((rs[0]['start_date'], cid))
    members = {cid: frozenset(r['party_id'] for r in rs if r['cabinet_party'] == '1') for cid, rs in cabinets.items()}
    n = largest = two = minority = single = reformed = compared = radical_seen = radical_in = 0
    for starts in first.values():
        cid = sorted(starts)[0][1]
        rs = cabinets[cid]
        seats = {r['party_id']: int(r['seats'] or 0) for r in rs}
        total = int(rs[0]['election_seats_total'] or 0) or sum(seats.values())
        order = sorted(seats, key=lambda p: -seats[p])
        if seats[order[0]] * 2 > total:
            continue
        cabinet = members[cid]
        n += 1
        largest += order[0] in cabinet
        two += order[0] in cabinet and order[1] in cabinet
        minority += sum(seats[p] for p in cabinet) * 2 <= total
        single += len(cabinet) == 1
        if rs[0]['previous_cabinet_id'] in members:
            compared += 1
            reformed += members[rs[0]['previous_cabinet_id']] == cabinet
        for p, s in seats.items():
            if s * 100 >= total * 3 and fam.get(p) in RADICAL:
                radical_seen += 1
                radical_in += p in cabinet
    print(f"{label}: {n} cabinets | largest party in cabinet {largest / n:.3f} | two largest together {two / n:.3f} | minority {minority / n:.3f} "
          f"| single-party {single / n:.3f} | outgoing re-formed {reformed / compared:.3f} | radical parties in cabinet {radical_in / radical_seen:.3f} ({radical_in}/{radical_seen})")
