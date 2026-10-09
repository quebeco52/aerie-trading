"""Cross-checks of the COSPAL fit on two independent leader datasets.

  data/horiuchi2013_leaders.csv          Horiuchi, Laing & 't Hart (2015) replication data (Dataverse BOF2WR),
                                         converted from Stata by dta114.py; 448 leaders analysed, 23 democracies, to 1 Oct 2009
  data/obrien2015/partyleaders.tab       O'Brien (2015) replication data (Dataverse 27631), party-years 1965-2013

Prints: tenure (Kaplan-Meier) and age at selection in the Western European / Nordic Horiuchi countries; the
leaders who took over a sitting head of government's party (igt == 1); O'Brien's annual exit rates for the
Nordic countries by government status; the age agreement between COSPAL and Horiuchi for the same leaders.

python3 crosscheck.py
"""
import csv, math, os, collections, datetime, unicodedata, sys
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from fit_leader_exit import km, pctl

HERE = os.path.dirname(os.path.abspath(__file__))
WE = ['AUT', 'DEU', 'DNK', 'ESP', 'GBR', 'GRC', 'IRL', 'LUX', 'MLT', 'NLD', 'NOR', 'PRT', 'SWE']
NORDIC = ['DNK', 'NOR', 'SWE']


def norm(s):
    s = unicodedata.normalize('NFKD', s).encode('ascii', 'ignore').decode().lower()
    return ' '.join(w for w in s.replace('.', ' ').replace('-', ' ').split() if not w.startswith('('))


def horiuchi():
    rows = list(csv.DictReader(open(os.path.join(HERE, 'data', 'horiuchi2013_leaders.csv'))))
    out = []
    for label, cs in (('Western Europe (13)', WE), ('Nordic (DNK NOR SWE)', NORDIC)):
        sub = [r for r in rows if r['country'] in cs and r['party_seq'] != '1']   # as in the paper: drop each party's first leader
        sp = []
        for r in sub:
            a = datetime.date.fromisoformat(r['in_date'])
            b = datetime.date.fromisoformat(r['out_date']) if r['out_date'] else datetime.date(2009, 10, 1)
            sp.append(((b - a).days / 365.25, 1 if r['out_date'] else 0))
        med, S, rmean = km(sp)
        ages = [float(r['age']) for r in sub if r['age']]
        m = sum(ages) / len(ages); sd = math.sqrt(sum((x - m) ** 2 for x in ages) / (len(ages) - 1))
        out.append('Horiuchi %s: leaders %d (exits %d); KM median %.2f y; S(2) %.2f S(5) %.2f S(10) %.2f; restricted mean(40y) %.2f y; '
                   'arithmetic mean incl. censored %.2f y' % (label, len(sp), sum(e for _, e in sp), med, S(2), S(5), S(10), rmean,
                                                              sum(t for t, _ in sp) / len(sp)))
        out.append('    age at selection: n %d mean %.1f sd %.1f min %d p10 %.0f p50 %.0f p90 %.0f max %d' % (
            len(ages), m, sd, min(ages), pctl(ages, .1), pctl(ages, .5), pctl(ages, .9), max(ages)))
    igt = [r for r in rows if r['country'] in WE and r['igt'] == '1']
    out.append('Horiuchi leaders succeeding a sitting head of government (igt=1), Western Europe: %d' % len(igt))
    for r in igt:
        out.append('    %s %-8s %s %s' % (r['country'], r['party_name'], r['in_date'], r['name']))
    return out, rows


def obrien():
    rows = list(csv.DictReader(open(os.path.join(HERE, 'data', 'obrien2015', 'partyleaders.tab')), delimiter='\t'))
    cl = lambda v: v.strip('"')
    out = ["O'Brien (2015) party-years in her analysis sample (LeaderInclude.wC.wPD == 1); exit = LeaderDeath (leader's tenure ends):"]
    for label, cs in (('Denmark', ['Denmark']), ('Sweden', ['Sweden']), ('Finland', ['Finland']), ('Nordic 3', ['Denmark', 'Sweden', 'Finland']),
                      ('Europe 7', ['Denmark', 'Sweden', 'Finland', 'Austria', 'Germany', 'Ireland', 'United Kingdom'])):
        sub = [r for r in rows if cl(r['Country']) in cs and r['LeaderInclude.wC.wPD'] == '1']
        g = collections.defaultdict(lambda: [0, 0])
        for r in sub:
            k = 'gov' if r['gov'] == '1' else 'opp'
            g[k][0] += int(r['LeaderDeath']); g[k][1] += 1
            g['all'][0] += int(r['LeaderDeath']); g['all'][1] += 1
        new = [float(r['leaderage']) for r in sub if r['NewLeader'] == '1' and r['leaderage']]
        out.append('    %-9s ' % label + '  '.join('%s %d/%d = %.3f/y' % (k, g[k][0], g[k][1], g[k][0] / g[k][1]) for k in ('gov', 'opp', 'all') if g[k][1])
                   + ('   new-leader age mean %.1f (n %d)' % (sum(new) / len(new), len(new)) if new else ''))
    return out


def age_agreement(hrows):
    path = os.path.join(HERE, 'data', 'cospal_we_spells.csv')
    if not os.path.exists(path):
        return ['(run fit_leader_exit.py first for the COSPAL age check)']
    cos = list(csv.DictReader(open(path)))
    h = {}
    for r in hrows:
        if r['age']:
            h.setdefault((r['country'], norm(r['name']).split()[-1], r['in_date'][:4]), float(r['age']))
    diffs = []
    for r in cos:
        if not r['age_at_selection']:
            continue
        k = (r['country'], norm(r['leader']).split()[-1], r['start'][:4])
        if k in h:
            diffs.append((float(r['age_at_selection']) - h[k], r['country'], r['leader'], r['start'][:4], r['age_at_selection'], h[k]))
    big = [d for d in diffs if abs(d[0]) > 2]
    out = ['COSPAL vs Horiuchi age at selection, same leader (country, surname, start year): %d matches, |diff|<=1y: %d, |diff|>2y: %d' % (
        len(diffs), sum(1 for d in diffs if abs(d[0]) <= 1), len(big))]
    for d in big:
        out.append('    %s %s %s COSPAL %s Horiuchi %s' % d[1:])
    return out


if __name__ == '__main__':
    o1, hrows = horiuchi()
    print('\n'.join(o1 + obrien() + age_agreement(hrows)))
