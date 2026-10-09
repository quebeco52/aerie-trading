"""Party-leader exit: fits the post-election exit logit and the between-election hazard on COSPAL x ParlGov.

Data (all on disk):
  data/cospal/cospal_aggregated_2019.tab  COSPAL v2 (Cross, Pilet & Pruysers 2019), party-year panel 1965-2018, CC0
  ../politics/parlgov/view_election.csv   ParlGov elections (vote share, seats)
  ../politics/parlgov/view_cabinet.csv    ParlGov cabinets (members, PM party, start date)

Sample: the eight Western European COSPAL countries (AUT BEL DEU DNK ESP GBR NOR PRT). Party-years with a
collective leadership are dropped. A leader exit is a COSPAL leadership change with a named successor.

Outputs (printed): model tables, hazards, timing shares, tenure/age summaries, PM-succession counts.
Writes data/cospal_we_party_elections.csv (the logit sample) and data/cospal_we_spells.csv (leader spells).

python3 fit_leader_exit.py
"""
import csv, math, os, datetime, collections, re, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import glm

HERE = os.path.dirname(os.path.abspath(__file__))
PG = os.path.join(HERE, '..', 'politics', 'parlgov')
D = datetime.date

COUNTRY = {'Austria': 'AUT', 'Belgium': 'BEL', 'Germany': 'DEU', 'Denmark': 'DNK', 'Spain': 'ESP', 'UK': 'GBR',
           'Norway': 'NOR', 'Portugal': 'PRT'}
# COSPAL party name -> ParlGov party ids (vote shares summed when several appear in one election)
PARTY = {
    ('UK', 'Conservative'): [773, 1496], ('UK', 'Labour'): [1556], ('UK', 'Liberal'): [659],
    ('UK', 'Liberal Democrats'): [659], ('UK', 'SDP'): [1547],
    ('Germany', 'CDU'): [808], ('Germany', 'CSU'): [1180], ('Germany', 'Die Grünen'): [772],
    ('Germany', 'B´90/Die Grünen'): [772], ('Germany', 'FDP'): [543], ('Germany', 'PDS-Die Linke'): [791],
    ('Germany', 'SPD'): [558], ('Germany', 'AfD'): [2253],
    ('Belgium', 'CD&V'): [723], ('Belgium', 'CDH'): [1192], ('Belgium', 'Ecolo'): [161], ('Belgium', 'Groen'): [1594],
    ('Belgium', 'MR'): [915, 454], ('Belgium', 'N-VA'): [501], ('Belgium', 'Open VLD'): [1110],
    ('Belgium', 'PS'): [1378], ('Belgium', 'SP.a'): [1029, 1113], ('Belgium', 'VB'): [993],
    ('Austria', 'BZÖ'): [1536], ('Austria', 'FPÖ'): [50], ('Austria', 'Greens'): [1429], ('Austria', 'LIF'): [955],
    ('Austria', 'ÖVP'): [1013], ('Austria', 'SPÖ'): [973], ('Austria', 'NEOS'): [2255],
    ('Spain', 'PP'): [645], ('Spain', 'CDC'): [894], ('Spain', 'PCE'): [118], ('Spain', 'IU'): [118],
    ('Spain', 'PNV'): [1361], ('Spain', 'PSOE'): [902], ('Spain', 'Ciudadanos'): [2375], ('Spain', 'Podemos'): [2376],
    ('Spain', 'PDeCAT'): [2605],
    ('Portugal', 'CDS'): [251], ('Portugal', 'PC'): [514, 11, 1295], ('Portugal', 'PS'): [725], ('Portugal', 'PSD'): [1273],
    ('Norway', 'Ap'): [104], ('Norway', 'FrP (ALP)'): [351], ('Norway', 'FrP'): [351], ('Norway', 'H'): [1435],
    ('Norway', 'KrF'): [1538], ('Norway', 'SF'): [1773, 81], ('Norway', 'SV (SF)'): [1773, 81], ('Norway', 'SV'): [1773, 81],
    ('Norway', 'SP (B)'): [702], ('Norway', 'SP'): [702], ('Norway', 'V'): [647],
    ('Denmark', 'CD Centre Democrats'): [1324], ("Denmark", "DF People's Party"): [1418],
    ('Denmark', 'DKP Communist Party'): [1239], ('Denmark', 'Enhedslisten'): [306], ('Denmark', 'FP Progress Party'): [978],
    ('Denmark', 'KF Conservative Party'): [590], ("Denmark", "KrF Christian People's Party"): [1331],
    ('Denmark', 'Liberal Alliance'): [376], ('Denmark', 'RV Radikal Venstre'): [211], ('Denmark', 'SD Social Democrats'): [1629],
    ("Denmark", "SF Socialist People's Party"): [1644], ('Denmark', 'Venstre'): [1605],
}
# Parties renamed inside COSPAL are one organisation (one leader sequence)
ORG = {('UK', 'Liberal Democrats'): ('UK', 'Liberal'), ('Germany', 'B´90/Die Grünen'): ('Germany', 'Die Grünen'),
       ('Spain', 'IU'): ('Spain', 'PCE'), ('Norway', 'FrP'): ('Norway', 'FrP (ALP)'), ('Norway', 'SV (SF)'): ('Norway', 'SF'),
       ('Norway', 'SV'): ('Norway', 'SF'), ('Norway', 'SP'): ('Norway', 'SP (B)')}


def cl(v):
    return v.strip().strip('"').strip()


def cospal_date(v):
    try:
        x = int(round(float(v)))
    except ValueError:
        return None
    if not 18000000 < x < 21000000:
        return None
    y, m, d = x // 10000, x // 100 % 100, x % 100
    if not 1 <= m <= 12:
        return None
    d = min(max(d, 1), 28) if d > 28 or d < 1 else d
    return D(y, m, d)


def iso(s):
    return D(int(s[:4]), int(s[5:7]), int(s[8:10]))


# ---------------------------------------------------------------- ParlGov
def load_parlgov(countries):
    elections = collections.defaultdict(dict)       # country -> election_id -> date
    votes = {}                                        # (election_id, party_id) -> (vote, seats)
    for r in csv.DictReader(open(os.path.join(PG, 'view_election.csv'))):
        if r['election_type'] != 'parliament' or r['country_name_short'] not in countries:
            continue
        eid = int(r['election_id'])
        elections[r['country_name_short']][eid] = iso(r['election_date'])
        v = float(r['vote_share']) if r['vote_share'] else None
        s = int(float(r['seats'])) if r['seats'] else 0
        old = votes.get((eid, int(r['party_id'])))
        if old:
            v = (old[0] or 0) + (v or 0); s += old[1]
        votes[(eid, int(r['party_id']))] = (v, s)
    el = {c: sorted((d, e) for e, d in m.items()) for c, m in elections.items()}
    cabs = collections.defaultdict(dict)
    for r in csv.DictReader(open(os.path.join(PG, 'view_cabinet.csv'))):
        c = r['country_name_short']
        if c not in countries:
            continue
        cid = int(r['cabinet_id'])
        cab = cabs[c].setdefault(cid, {'start': iso(r['start_date']), 'eid': int(r['election_id']), 'name': r['cabinet_name'].strip(),
                                       'caretaker': r['caretaker'] == '1', 'parties': set(), 'pm': None})
        if r['cabinet_party'] == '1':
            cab['parties'].add(int(r['party_id']))
        if r['prime_minister'] == '1':
            cab['pm'] = int(r['party_id'])
    cab_list = {c: sorted(m.values(), key=lambda x: (x['start'], x['name'])) for c, m in cabs.items()}
    return el, votes, cab_list


def cabinet_at(cl_, t):
    """Cabinet in force on date t (last one started on or before t)."""
    lo, hi = 0, len(cl_)
    while lo < hi:
        mid = (lo + hi) // 2
        if cl_[mid]['start'] <= t:
            lo = mid + 1
        else:
            hi = mid
    return cl_[lo - 1] if lo else None


def status(cab, ids):
    if cab is None:
        return 'opp'
    if cab['pm'] in ids:
        return 'pm'
    if cab['parties'] & set(ids):
        return 'gov'
    return 'opp'


# ---------------------------------------------------------------- COSPAL
def load_cospal():
    rows = list(csv.DictReader(open(os.path.join(HERE, 'data', 'cospal', 'cospal_aggregated_2019.tab')), delimiter='\t'))
    orgs = collections.OrderedDict()
    for r in rows:
        country, pname = cl(r['Country']), cl(r['PartyName'])
        if country not in COUNTRY or (country, pname) not in PARTY:
            continue
        key = ORG.get((country, pname), (country, pname))
        o = orgs.setdefault(key, {'country': COUNTRY[country], 'name': key[1], 'ids_by_year': {}, 'years': set(),
                                  'collective': set(), 'events': []})
        y = int(float(cl(r['Year'])))
        o['ids_by_year'][y] = PARTY[(country, pname)]
        if cl(r['collectiveleadership']) == '1.0':
            o['collective'].add(y)
        else:
            o['years'].add(y)
        if cl(r['changeofleader']) == '1.0':
            name = cl(r['Namenewleader'])
            if name in ('98', '99', '') or 'collective' in name.lower():
                continue
            dt = cospal_date(r['Dateofleadershipchange'])
            missing = dt is None
            if missing:
                dt = D(y, 7, 1)
            age = None
            try:
                a = float(cl(r['age']))
                if 18 <= a <= 95:
                    age = a
            except ValueError:
                pass
            reason = cl(r['reasonforendofleadership'])
            o['events'].append({'date': dt, 'name': name, 'age': age, 'fm': reason == '1.0', 'reason': reason,
                                'date_missing': missing, 'collective_year': y in o['collective']})
    for key in [k for k, o in orgs.items() if not o['years']]:
        del orgs[key]                     # collective leadership throughout (Ecolo, AfD)
    for o in orgs.values():
        o['events'].sort(key=lambda e: e['date'])
        # drop same-name repeats (duplicated rows of one change)
        ev, seen = [], set()
        for e in o['events']:
            k = (e['name'], e['date'])
            if k not in seen:
                ev.append(e); seen.add(k)
        o['events'] = ev
        ys = sorted(o['years'])
        o['cov_start'], o['cov_end'] = D(ys[0], 1, 1), D(ys[-1], 12, 31)
    return orgs


def covered(o, a, b):
    """Every calendar year from date a to date b is a non-collective panel year."""
    return all(y in o['years'] for y in range(a.year, b.year + 1))


def ids_at(o, t):
    ys = sorted(o['ids_by_year'])
    best = ys[0]
    for y in ys:
        if y <= t.year:
            best = y
    return o['ids_by_year'][best]


def vote(votes, eid, ids):
    vs = [votes[(eid, i)] for i in ids if (eid, i) in votes]
    if not vs:
        return None, 0
    v = [x[0] for x in vs if x[0] is not None]
    return (sum(v) if v else None), sum(x[1] for x in vs)


def km(spells, horizon=40.0):
    """Kaplan-Meier on (years, event) pairs -> (median, S(10), restricted mean to horizon, S(t) fn)."""
    times = sorted(set(t for t, e in spells if e))
    s, surv = 1.0, []
    for t in times:
        at_risk = sum(1 for x, _ in spells if x >= t)
        d = sum(1 for x, e in spells if e and x == t)
        s *= 1 - d / at_risk
        surv.append((t, s))

    def S(t):
        v = 1.0
        for tt, ss in surv:
            if tt <= t:
                v = ss
            else:
                break
        return v
    med = next((t for t, ss in surv if ss <= 0.5), None)
    rmean, prev_t, prev_s = 0.0, 0.0, 1.0
    for t, ss in surv:
        if t > horizon:
            break
        rmean += prev_s * (t - prev_t); prev_t, prev_s = t, ss
    rmean += prev_s * (horizon - prev_t)
    return med, S, rmean


def pctl(xs, q):
    xs = sorted(xs)
    k = (len(xs) - 1) * q
    f = int(math.floor(k)); c = min(f + 1, len(xs) - 1)
    return xs[f] + (xs[c] - xs[f]) * (k - f)


def main():
    orgs = load_cospal()
    countries = set(COUNTRY.values())
    el, votes, cabs = load_parlgov(countries)
    out = []

    # ------------------------------------------------ (A) party x election sample
    sample = []
    for key, o in orgs.items():
        c = o['country']
        elist = el[c]
        for k in range(1, len(elist)):
            E, eid = elist[k]
            prevE, prev_eid = elist[k - 1]
            nextE = elist[k + 1][0] if k + 1 < len(elist) else None
            if E < o['cov_start'] or E + datetime.timedelta(days=365) > o['cov_end']:
                continue
            if not covered(o, E - datetime.timedelta(days=1), E + datetime.timedelta(days=365)):
                continue
            ids = ids_at(o, E)
            v1, s1 = vote(votes, eid, ids)
            v0, s0 = vote(votes, prev_eid, ids)
            if s1 <= 0 or v1 is None or v0 is None:
                continue
            # a leader must have been in office at E: some event before E or panel started before E
            before = status(cabinet_at(cabs[c], E - datetime.timedelta(days=1)), ids)
            after_cabs = [x for x in cabs[c] if x['eid'] == eid]
            first = next((x for x in after_cabs if not x['caretaker']), after_cabs[0] if after_cabs else None)
            after = status(first, ids)
            win = [e for e in o['events'] if E < e['date'] <= E + datetime.timedelta(days=365)]
            win6 = [e for e in win if e['date'] <= E + datetime.timedelta(days=182)]
            lead = [e for e in o['events'] if e['date'] <= E]
            start = lead[-1]['date'] if lead else None
            first_el = start is not None and start > prevE
            age_at_E = None
            if lead and lead[-1]['age'] is not None:
                age_at_E = lead[-1]['age'] + (E - start).days / 365.25
            sample.append({
                'country': c, 'party': o['name'], 'election': E.isoformat(), 'vote': v1, 'dv': v1 - v0, 'seats': s1,
                'before': before, 'after': after, 'exit12': int(bool(win)), 'exit6': int(bool(win6)),
                'exit12_nofm': int(any(not e['fm'] for e in win)), 'fm_only': int(bool(win) and all(e['fm'] for e in win)),
                'first_election': int(first_el), 'tenure': ((E - start).days / 365.25) if start else '',
                'age': round(age_at_E, 1) if age_at_E is not None else '',
                'days_to_next_election': (nextE - E).days if nextE else '',
                'exit_names': '; '.join(e['name'] for e in win)})
    with open(os.path.join(HERE, 'data', 'cospal_we_party_elections.csv'), 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(sample[0].keys())); w.writeheader(); w.writerows(sample)

    n = len(sample)
    out.append('=== (A) Party x election sample (WE8 COSPAL, parliamentary parties): n=%d, exits within 12m=%d (%.1f%%), within 6m=%d (%.1f%%)' % (
        n, sum(s['exit12'] for s in sample), 100 * sum(s['exit12'] for s in sample) / n,
        sum(s['exit6'] for s in sample), 100 * sum(s['exit6'] for s in sample) / n))
    out.append('    elections %s to %s; countries %s' % (min(s['election'] for s in sample), max(s['election'] for s in sample),
                                                        dict(collections.Counter(s['country'] for s in sample))))
    out.append('    dv (pp): mean %.2f sd %.2f p10 %.1f p50 %.1f p90 %.1f' % (
        sum(s['dv'] for s in sample) / n, math.sqrt(sum((s['dv'] - sum(x['dv'] for x in sample) / n) ** 2 for s in sample) / (n - 1)),
        pctl([s['dv'] for s in sample], .1), pctl([s['dv'] for s in sample], .5), pctl([s['dv'] for s in sample], .9)))
    # cell rates
    cell = collections.defaultdict(lambda: [0, 0])
    for s in sample:
        dvc = 'loss>=1' if s['dv'] <= -1 else ('gain>=1' if s['dv'] >= 1 else 'flat')
        lost = s['before'] in ('pm', 'gov') and s['after'] == 'opp'
        st = 'lost office' if lost else s['after']
        cell[(st, dvc)][0] += s['exit12']; cell[(st, dvc)][1] += 1
        cell[(st, 'all')][0] += s['exit12']; cell[(st, 'all')][1] += 1
        cell[('all', dvc)][0] += s['exit12']; cell[('all', dvc)][1] += 1
        cell[('all', 'all')][0] += s['exit12']; cell[('all', 'all')][1] += 1
    out.append('    raw 12-month exit share by status after election x vote change (exits/n):')
    for st in ('pm', 'gov', 'opp', 'lost office', 'all'):
        out.append('      %-12s ' % st + '  '.join('%s %d/%d=%.0f%%' % (d, cell[(st, d)][0], cell[(st, d)][1],
                                                                  100 * cell[(st, d)][0] / max(1, cell[(st, d)][1]))
                                                for d in ('loss>=1', 'flat', 'gain>=1', 'all')))

    def X_of(s, spec):
        lost = 1.0 if (s['before'] in ('pm', 'gov') and s['after'] == 'opp') else 0.0
        row = [1.0]
        for v in spec:
            if v == 'dv': row.append(s['dv'])
            elif v == 'dv_loss': row.append(min(s['dv'], 0.0))
            elif v == 'dv_gain': row.append(max(s['dv'], 0.0))
            elif v == 'pm': row.append(1.0 if s['after'] == 'pm' else 0.0)
            elif v == 'gov': row.append(1.0 if s['after'] == 'gov' else 0.0)
            elif v == 'lost': row.append(lost)
            elif v == 'first': row.append(float(s['first_election']))
            elif v == 'age60': row.append(1.0 if s['age'] != '' and s['age'] >= 60 else 0.0)
            elif v == 'age_dec': row.append((s['age'] - 50.0) / 10.0)
        return row

    specs = [('M1', ['dv', 'pm', 'gov', 'lost'], 'exit12', None),
             ('M2 (asymmetric)', ['dv_loss', 'dv_gain', 'pm', 'gov', 'lost'], 'exit12', None),
             ('M3 (+first election as leader)', ['dv', 'pm', 'gov', 'lost', 'first'], 'exit12', None),
             ('M1 excluding force-majeure exits', ['dv', 'pm', 'gov', 'lost'], 'exit12_nofm', 'fm_only'),
             ('M1 on 6-month window', ['dv', 'pm', 'gov', 'lost'], 'exit6', None),
             ('M4 (+age, leaders with known age)', ['dv', 'pm', 'gov', 'lost', 'age_dec'], 'exit12', 'no_age')]
    for s_ in sample:
        s_['no_age'] = int(s_['age'] == '')
    for title, spec, yname, drop in specs:
        sub = [s for s in sample if not (drop and s[drop])]
        res = glm.fit([X_of(s, spec) for s in sub], [s[yname] for s in sub], 'logit')
        out.append(glm.report(res, ['const'] + spec, '--- logit %s: P(%s)' % (title, yname)))

    noBEL = lambda s: s['country'] != 'BEL'
    for title, keep, spec in (('M1 excluding Belgium (party presidents are never PM)', noBEL, ['dv', 'pm', 'gov', 'lost']),
                              ('M1 Denmark + Norway only', lambda s: s['country'] in ('DNK', 'NOR'), ['dv', 'pm', 'gov', 'lost']),
                              ('M5 reduced, all WE8', lambda s: True, ['dv', 'pm']),
                              ('M5 reduced, excluding Belgium', noBEL, ['dv', 'pm']),
                              ('M6 asymmetric reduced, excluding Belgium', noBEL, ['dv_loss', 'dv_gain', 'pm'])):
        sub = [s for s in sample if keep(s)]
        res = glm.fit([X_of(s, spec) for s in sub], [s['exit12'] for s in sub], 'logit')
        out.append(glm.report(res, ['const'] + spec, '--- logit %s: P(exit12)' % title))

    # ------------------------------------------------ (B) between-election hazard and timing
    expo = collections.defaultdict(float)       # (window, status) -> years
    evs = collections.defaultdict(int)
    evs_nofm = collections.defaultdict(int)
    since = []                                   # days since last election, per exit (dated, non-FM)
    since_all = []
    exposure_days = collections.Counter()        # days-since-election bucket exposure (months)
    cexpo, cevs = collections.defaultdict(float), collections.defaultdict(int)
    gexpo = collections.defaultdict(float)       # mid-term exposure by (grace, status): grace = leader chosen after the last election
    gevs = collections.defaultdict(int)
    for key, o in orgs.items():
        c = o['country']
        elist = el[c]
        for k in range(len(elist)):
            E, eid = elist[k]
            nxt = elist[k + 1][0] if k + 1 < len(elist) else D(2019, 1, 1)
            a, b = max(E, o['cov_start']), min(nxt, o['cov_end'] + datetime.timedelta(days=1))
            if a >= b:
                continue
            ids = ids_at(o, E)
            _, seats = vote(votes, eid, ids)
            if seats <= 0:
                continue
            t = a
            while t < b:
                if t.year in o['years']:
                    win = 'post12' if (t - E).days < 365 else 'mid'
                    st = status(cabinet_at(cabs[c], t), ids)
                    expo[(win, st)] += 1 / 365.25
                    cexpo[(c, win, st)] += 1 / 365.25
                    exposure_days[min((t - E).days // 30, 60)] += 1
                    if win == 'mid':
                        g = any(E < e['date'] <= t for e in o['events'])
                        gexpo[(g, st)] += 1 / 365.25
                t += datetime.timedelta(days=1)
            for e in o['events']:
                if a < e['date'] <= b - datetime.timedelta(days=0) and e['date'] < b and e['date'].year in o['years']:
                    win = 'post12' if (e['date'] - E).days <= 365 else 'mid'
                    st = status(cabinet_at(cabs[c], e['date'] - datetime.timedelta(days=1)), ids)
                    evs[(win, st)] += 1
                    cevs[(c, win, st)] += 1
                    if win == 'mid':
                        g = any(E < x['date'] < e['date'] for x in o['events'])
                        gevs[(g, st)] += 1
                    if not e['fm']:
                        evs_nofm[(win, st)] += 1
                    if not e['date_missing']:
                        since_all.append((e['date'] - E).days)
                        if not e['fm']:
                            since.append((e['date'] - E).days)
    out.append('\n=== (B) Exit hazard per leader-year, parliamentary parties (all exits | excluding force majeure)')
    for win in ('post12', 'mid'):
        for st in ('pm', 'gov', 'opp'):
            y = expo[(win, st)]
            out.append('    %-6s %-4s exposure %7.1f y  exits %3d  hazard %.3f/y | %3d  %.3f/y' % (
                win, st, y, evs[(win, st)], evs[(win, st)] / y if y else 0, evs_nofm[(win, st)], evs_nofm[(win, st)] / y if y else 0))
        y = sum(expo[(win, s)] for s in ('pm', 'gov', 'opp')); e_ = sum(evs[(win, s)] for s in ('pm', 'gov', 'opp'))
        e2 = sum(evs_nofm[(win, s)] for s in ('pm', 'gov', 'opp'))
        out.append('    %-6s all  exposure %7.1f y  exits %3d  hazard %.3f/y | %3d  %.3f/y' % (win, y, e_, e_ / y, e2, e2 / y))
    for g in (False, True):
        out.append('    mid-term, leader %-30s ' % ('chosen after the last election' if g else 'has fought the last election') + '  '.join(
            '%s %d/%.0fy=%.3f' % (st, gevs[(g, st)], gexpo[(g, st)], gevs[(g, st)] / gexpo[(g, st)] if gexpo[(g, st)] else 0) for st in ('pm', 'gov', 'opp'))
            + '  all %d/%.0fy=%.3f' % (sum(gevs[(g, st)] for st in ('pm', 'gov', 'opp')), sum(gexpo[(g, st)] for st in ('pm', 'gov', 'opp')),
                                       sum(gevs[(g, st)] for st in ('pm', 'gov', 'opp')) / sum(gexpo[(g, st)] for st in ('pm', 'gov', 'opp'))))
    for cc in sorted(set(k[0] for k in cexpo)):
        parts = []
        for win in ('post12', 'mid'):
            for st in ('pm', 'gov', 'opp'):
                y = cexpo[(cc, win, st)]
                parts.append('%s-%s %d/%.0f=%.2f' % (win, st, cevs[(cc, win, st)], y, cevs[(cc, win, st)] / y if y else float('nan')))
        out.append('    %s  %s' % (cc, '  '.join(parts)))
    nb = lambda win, st: (sum(cevs[(cc, win, st)] for cc in set(k[0] for k in cexpo) if cc != 'BEL'),
                          sum(cexpo[(cc, win, st)] for cc in set(k[0] for k in cexpo) if cc != 'BEL'))
    out.append('    excluding BEL: ' + '  '.join('%s-%s %d/%.0f=%.3f' % (w, st, nb(w, st)[0], nb(w, st)[1], nb(w, st)[0] / nb(w, st)[1])
                                             for w in ('post12', 'mid') for st in ('pm', 'gov', 'opp')))
    tot_y = sum(expo.values()); tot_e = sum(evs.values())
    out.append('    overall exposure %.1f y, exits %d, hazard %.3f/y (mean spell if constant: %.1f y)' % (tot_y, tot_e, tot_e / tot_y, tot_y / tot_e))
    tot_days = sum(exposure_days.values())
    for lim in (182, 365):
        sh = sum(1 for d in since if d <= lim) / len(since)
        sh_all = sum(1 for d in since_all if d <= lim) / len(since_all)
        ex = sum(v for m, v in exposure_days.items() if m * 30 < lim) / tot_days
        out.append('    exits within %3d days of last election: %.1f%% of non-FM exits (n=%d), %.1f%% of all dated exits (n=%d); share of exposure time %.1f%%' % (
            lim, 100 * sh, len(since), 100 * sh_all, len(since_all), 100 * ex))

    # ------------------------------------------------ (C) spells: tenure and age at selection
    spells, ages, rows_sp = [], [], []
    for key, o in orgs.items():
        ev = [e for e in o['events'] if not e['collective_year'] and e['date'] >= o['cov_start']]
        for i, e in enumerate(ev):
            end = ev[i + 1]['date'] if i + 1 < len(ev) else None
            # censor at panel end or at the first collective year after the start
            cens = o['cov_end']
            for y in sorted(o['collective']):
                if y > e['date'].year:
                    cens = min(cens, D(y, 1, 1)); break
            if end is not None and end <= cens:
                dur, ev_ = (end - e['date']).days / 365.25, 1
            else:
                dur, ev_ = (cens - e['date']).days / 365.25, 0
            if dur <= 0:
                continue
            spells.append((dur, ev_))
            if e['age'] is not None:
                ages.append(e['age'])
            rows_sp.append({'country': o['country'], 'party': o['name'], 'leader': e['name'], 'start': e['date'].isoformat(),
                            'end': end.isoformat() if (end and ev_) else '', 'years': round(dur, 2), 'exit_observed': ev_,
                            'age_at_selection': e['age'] if e['age'] is not None else '', 'start_date_missing': int(e['date_missing'])})
    with open(os.path.join(HERE, 'data', 'cospal_we_spells.csv'), 'w', newline='') as f:
        w = csv.DictWriter(f, fieldnames=list(rows_sp[0].keys())); w.writeheader(); w.writerows(rows_sp)
    med, S, rmean = km(spells)
    out.append('\n=== (C) COSPAL WE8 leader spells starting in panel: n=%d, exits observed %d' % (len(spells), sum(e for _, e in spells)))
    out.append('    KM median %.2f y; S(2)=%.2f S(4)=%.2f S(5)=%.2f S(10)=%.2f S(15)=%.2f; restricted mean (40y) %.2f y' % (
        med, S(2), S(4), S(5), S(10), S(15), rmean))
    m = sum(ages) / len(ages)
    sd = math.sqrt(sum((a - m) ** 2 for a in ages) / (len(ages) - 1))
    out.append('    age at first selection: n=%d mean %.1f sd %.1f min %d p10 %.0f p25 %.0f p50 %.0f p75 %.0f p90 %.0f max %d' % (
        len(ages), m, sd, min(ages), pctl(ages, .1), pctl(ages, .25), pctl(ages, .5), pctl(ages, .75), pctl(ages, .9), max(ages)))
    out.append('    share of spells lasting > 10 y (KM): %.2f; > 4 y: %.2f' % (S(10), S(4)))
    # life tables: annual exit hazard by tenure band and by current age band
    tb = [(0, 1), (1, 2), (2, 4), (4, 6), (6, 10), (10, 99)]
    ab = [(0, 45), (45, 55), (55, 60), (60, 65), (65, 99)]
    te, tx = collections.Counter(), collections.Counter()
    ae, ax = collections.Counter(), collections.Counter()
    for r in rows_sp:
        dur, ev_ = r['years'], r['exit_observed']
        for lo, hi in tb:
            if dur > lo:
                te[(lo, hi)] += min(dur, hi) - lo
                if ev_ and lo < dur <= hi:
                    tx[(lo, hi)] += 1
        if r['age_at_selection'] != '':
            a0 = float(r['age_at_selection']) + 0.5
            for lo, hi in ab:
                seg = max(0.0, min(a0 + dur, hi) - max(a0, lo))
                ae[(lo, hi)] += seg
                if ev_ and lo <= a0 + dur < hi:
                    ax[(lo, hi)] += 1
    out.append('    annual exit hazard by tenure: ' + '  '.join('%d-%sy %d/%.0f=%.3f' % (lo, hi if hi < 99 else '', tx[(lo, hi)], te[(lo, hi)], tx[(lo, hi)] / te[(lo, hi)]) for lo, hi in tb))
    out.append('    annual exit hazard by age:    ' + '  '.join('%s-%s %d/%.0f=%.3f' % (lo if lo else '', hi if hi < 99 else '', ax[(lo, hi)], ae[(lo, hi)], ax[(lo, hi)] / ae[(lo, hi)]) for lo, hi in ab))

    # ------------------------------------------------ (D) leader changes in the PM's party
    def norm(x):
        return x.lower().replace('ø', 'o').replace('ö', 'o').replace('ü', 'u').replace('é', 'e').replace('á', 'a').replace('ã', 'a')

    out.append('\n=== (D) COSPAL leader changes while the party held the premiership (ParlGov), WE8')
    kinds = collections.Counter()
    lines = []
    for key, o in orgs.items():
        c = o['country']
        for e in o['events']:
            ids = ids_at(o, e['date'])
            cab = cabinet_at(cabs[c], e['date'] - datetime.timedelta(days=1))
            if status(cab, ids) != 'pm':
                continue
            nxt_el = next((d for d, _ in el[c] if d >= e['date']), D(2100, 1, 1))
            sur = norm(e['name'].split()[-1])[:5]
            near = [x for x in cabs[c] if e['date'] - datetime.timedelta(days=180) <= x['start'] < nxt_el and x['pm'] in ids]
            if sur in norm(cab['name']):
                kind = 'new leader already PM (PM first, party post after)'
            elif any(sur in norm(x['name']) for x in near):
                kind = 'new leader becomes PM before next election'
            else:
                kind = 'PM and party leader are different people'
            kinds[kind] += 1
            lines.append('      %s %-22s %s -> %-28s PM cabinet: %-16s next election %s : %s' % (
                c, o['name'][:22], e['date'], e['name'][:28], cab['name'], nxt_el if nxt_el.year < 2100 else '-', kind))
    out.append('    %d changes of leader in a party holding the premiership: %s' % (sum(kinds.values()), dict(kinds)))
    out.extend(lines)

    # ------------------------------------------------ (E) ParlGov: PM changes between elections, same party
    WIDE = ['AUT', 'BEL', 'DNK', 'DEU', 'ESP', 'FIN', 'GBR', 'IRL', 'ISL', 'LUX', 'NLD', 'NOR', 'PRT', 'SWE', 'AUS', 'CAN', 'NZL']
    el2, _, cabs2 = load_parlgov(set(WIDE))
    roman = re.compile(r'\s+(?:[IVX]+)$')
    out.append('\n=== (E) ParlGov 1945-2023: PM replaced by a same-party PM with no election in between (cabinet name = PM)')
    tot = collections.Counter()
    for c in WIDE:
        cl_ = [x for x in cabs2.get(c, []) if x['start'] >= D(1945, 1, 1) and x['pm'] is not None]
        intra, pm_years, names = 0, 0.0, []
        for a, b in zip(cl_, cl_[1:]):
            pm_years += (b['start'] - a['start']).days / 365.25
            na, nb = roman.sub('', a['name']).strip(), roman.sub('', b['name']).strip()
            if a['eid'] == b['eid'] and a['pm'] == b['pm'] and na.replace('.', '').split() != nb.replace('.', '').split()[:len(na.replace('.', '').split())]:
                intra += 1
                names.append('%s %s->%s' % (b['start'].year, na, nb))
        if cl_:
            pm_years += (D(2023, 12, 31) - cl_[-1]['start']).days / 365.25
        nel = sum(1 for d, _ in el2.get(c, []) if d >= D(1945, 1, 1))
        tot['intra'] += intra; tot['years'] += pm_years; tot['el'] += nel
        out.append('    %s elections %2d  intra-party PM changes %2d  per decade %.2f  : %s' % (
            c, nel, intra, 10 * intra / pm_years if pm_years else 0, ', '.join(names)))
    out.append('    total: %d intra-party mid-term PM changes over %.0f years = %.3f per year' % (tot['intra'], tot['years'], tot['intra'] / tot['years']))
    print('\n'.join(out))


if __name__ == '__main__':
    main()
