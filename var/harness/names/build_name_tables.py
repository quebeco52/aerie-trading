"""Builds src/Data/Politics/UsNameFrequencies.php from the public name tables in raw/.

Sources (downloaded 2026-10-05):
  raw/Names_2010Census.csv       https://www2.census.gov/topics/genealogy/2010surnames/names.zip (Comenetz 2016)
  raw/ssa_babynames.csv          SSA card applications 1880-2017, via the R package babynames 1.0.1 (CRAN);
                                 ssa.gov itself refuses scripted downloads
  raw/tzioumis_firstnames.xlsx   Tzioumis (2018), Harvard Dataverse doi:10.7910/DVN/TYJKEZ
  raw/famous_all.csv             Wikidata: humans (P31 Q5) with >= 20 sitelinks, English label, queried in
                                 sitelink bands (the endpoint times out on one query)

Run: <venv with pandas + openpyxl>/bin/python var/harness/names/build_name_tables.py
"""

import re
import unicodedata
from pathlib import Path

import numpy as np
import pandas as pd

HERE = Path(__file__).parent
RAW = HERE / 'raw'
OUT = HERE.parents[2] / 'src' / 'Data' / 'Politics' / 'UsNameFrequencies.php'

SURNAME_COUNT = 2000
GIVEN_PER_DECADE = 300
DECADES = list(range(1890, 2020, 10))
FAMOUS_MIN_SITELINKS = 25
CENSUS_GROUPS = ['pctwhite', 'pctblack', 'pctapi', 'pctaian', 'pct2prace', 'pcthispanic']

# The Census strips apostrophes, spaces and case; these are the names in the top 2,000 whose usual US spelling is not
# plain title case (Mc- names are handled by rule).
SURNAME_SPELLINGS = {
    'OBRIEN': "O'Brien", 'OCONNOR': "O'Connor", 'ONEILL': "O'Neill", 'ONEAL': "O'Neal", 'ONEIL': "O'Neil",
    'ODONNELL': "O'Donnell", 'OCONNELL': "O'Connell", 'OLEARY': "O'Leary", 'OHARA': "O'Hara", 'OMALLEY': "O'Malley",
    'OKEEFE': "O'Keefe", 'OROURKE': "O'Rourke", 'OREILLY': "O'Reilly",
    'MACDONALD': 'MacDonald', 'MACKENZIE': 'MacKenzie',
    'DELEON': 'De Leon', 'DELACRUZ': 'De La Cruz', 'DEJESUS': 'De Jesus', 'DELAROSA': 'De La Rosa',
    'DELATORRE': 'De La Torre', 'DELOSSANTOS': 'De Los Santos', 'DEWITT': 'DeWitt', 'DELONG': 'DeLong',
    'DEVRIES': 'De Vries', 'DELUCA': 'DeLuca', 'DEMARCO': 'DeMarco', 'DAMICO': "D'Amico", 'DASILVA': 'Da Silva',
    'LEBLANC': 'LeBlanc', 'STJOHN': 'St. John', 'STCLAIR': 'St. Clair', 'VANDYKE': 'Van Dyke', 'VANHORN': 'Van Horn',
}


def spell(census: str) -> str:
    if census in SURNAME_SPELLINGS:
        return SURNAME_SPELLINGS[census]
    if census.startswith('MC') and len(census) > 3:
        return 'Mc' + census[2:].capitalize()
    return census.capitalize()


def fill_suppressed(shares: pd.DataFrame) -> pd.DataFrame:
    """Census '(S)' cells share what the reported cells leave of 100 equally (CFPB 2014 BISG methodology)."""
    shares = shares.copy()
    missing = shares.isna()
    remainder = (100.0 - shares.sum(axis=1, skipna=True)).clip(lower=0.0)
    per_cell = remainder / missing.sum(axis=1).replace(0, np.nan)
    for column in shares.columns:
        shares.loc[missing[column], column] = per_cell[missing[column]]
    return shares


def fold(text: str) -> str:
    return unicodedata.normalize('NFKD', text).encode('ascii', 'ignore').decode('ascii')


def php_string(text: str) -> str:
    return "'" + text.replace('\\', '\\\\').replace("'", "\\'") + "'"


def php_number(value: float) -> str:
    text = f'{value:.2f}'.rstrip('0').rstrip('.')
    return text if text not in ('-0', '') else '0'


def wrap(items: list[str], indent: str, width: int = 120) -> list[str]:
    lines, line = [], indent
    for item in items:
        piece = item + ','
        if len(line) + len(piece) + 1 > width and line.strip():
            lines.append(line.rstrip())
            line = indent
        line += piece + ' '
    if line.strip():
        lines.append(line.rstrip())
    return lines


# --- Surnames ---
census = pd.read_csv(RAW / 'Names_2010Census.csv', na_values=['(S)'], keep_default_na=False)
census[CENSUS_GROUPS] = fill_suppressed(census[CENSUS_GROUPS].apply(pd.to_numeric))
population = census[CENSUS_GROUPS].mul(census['count'], axis=0).sum() / census['count'].sum()
named = census[census['name'] != 'ALL OTHER NAMES'].sort_values('rank')
head = named.head(SURNAME_COUNT).copy()
head['spelled'] = head['name'].map(spell)
assert head['spelled'].is_unique

flagged = [n for n in head['name'] if re.match(r'^(O[^AEIOU]|DE[LJW]|LE[BG]|ST[^AEIOUR]|VAN[^CG]|MAC[DK]|DA[MS]|DI[^AEIOU])', n)]
print('check spellings:', ' '.join(f'{n}->{spell(n)}' for n in flagged))
print(f'surnames: top {SURNAME_COUNT} cover {head["count"].sum() / census["count"].sum():.1%} of people')

# --- Given names ---
ssa = pd.read_csv(RAW / 'ssa_babynames.csv')
ssa['decade'] = (ssa['year'] // 10 * 10).astype(int)
by_decade = ssa.groupby(['decade', 'sex', 'name'])['n'].sum().reset_index()
given: dict[int, dict[str, list[tuple[str, int]]]] = {}
for decade in DECADES:
    given[decade] = {}
    for sex in ('M', 'F'):
        table = by_decade[(by_decade['decade'] == decade) & (by_decade['sex'] == sex)].sort_values('n', ascending=False)
        given[decade][sex] = list(zip(table['name'].head(GIVEN_PER_DECADE), table['n'].head(GIVEN_PER_DECADE).astype(int)))
        coverage = table['n'].head(GIVEN_PER_DECADE).sum() / table['n'].sum()
        print(f'given {decade}{sex}: top {GIVEN_PER_DECADE} cover {coverage:.0%}')
given_names = sorted({name for decade in given.values() for table in decade.values() for name, _ in table})

# --- Given-name group shares (Tzioumis 2018) ---
tz = pd.read_excel(RAW / 'tzioumis_firstnames.xlsx', sheet_name='Data')
tz['firstname'] = tz['firstname'].astype(str).str.upper()
tz = tz.set_index('firstname')
tz_groups = ['pctwhite', 'pctblack', 'pctapi', 'pctaian', 'pct2prace', 'pcthispanic']
unlisted = tz[tz_groups].mul(tz['obs'], axis=0).sum() / tz['obs'].sum()
given_shares = {name: tz.loc[name.upper(), tz_groups].tolist() for name in given_names if name.upper() in tz.index}
weight_listed = sum(n for d in given.values() for t in d.values() for name, n in t if name in given_shares)
weight_all = sum(n for d in given.values() for t in d.values() for _, n in t)
print(f'Tzioumis lists {len(given_shares)}/{len(given_names)} given names, {weight_listed / weight_all:.1%} of births')

# --- Famous people ---
wikidata = pd.read_csv(RAW / 'famous_all.csv', names=['item', 'label', 'sitelinks'], dtype={'label': str})
wikidata = wikidata[wikidata['sitelinks'] >= FAMOUS_MIN_SITELINKS]
given_set, surname_set = set(given_names), set(head['spelled'])
famous = set()
for label in wikidata['label'].dropna():
    folded = fold(label).strip()
    parts = folded.split(' ')
    for cut in range(1, len(parts)):
        first, last = ' '.join(parts[:cut]), ' '.join(parts[cut:])
        if first in given_set and last in surname_set:
            famous.add(f'{first} {last}')
famous = sorted(famous)
print(f'famous: {len(famous)} drawable names held by humans with {FAMOUS_MIN_SITELINKS}+ sitelinks')

# --- PHP ---
groups_doc = 'non-Hispanic white, Black, Asian or Pacific Islander, American Indian or Alaska Native, two or more races; Hispanic'
out = [
    '<?php',
    '',
    'declare(strict_types=1);',
    '',
    'namespace App\\Data\\Politics;',
    '',
    '/**',
    ' * US name frequencies the District\'s public figures are named from (App\\Data\\Politics\\AerieNames). Generated by',
    ' * var/harness/names/build_name_tables.py; edit the script, not this file.',
    ' *',
    ' * Surnames: 2010 Census surname file (Comenetz 2016), suppressed cells filled as in the CFPB\'s BISG method (2014).',
    ' * Given names: Social Security card applications by year of birth, 1880-2017 (SSA; R package babynames 1.0.1).',
    ' * Given-name group shares: mortgage applications, 2007-2010 (Tzioumis 2018, Scientific Data 5:180025).',
    f' * Famous people: Wikidata humans with {FAMOUS_MIN_SITELINKS} or more sitelinks, English label, folded to ASCII.',
    ' */',
    'final class UsNameFrequencies',
    '{',
    '    // --- Groups ---',
    f'    /** The race and ethnicity groups both sources report, in column order: {groups_doc}. */',
    "    public const GROUPS = ['white', 'black', 'api', 'aian', 'multiracial', 'hispanic'];",
    f'    /** Each group\'s share of everyone the 2010 Census counted, the surnames below the top {SURNAME_COUNT:,} included (%). */',
    '    public const POPULATION_SHARES = [' + ', '.join(php_number(v) for v in population.tolist()) + '];',
    '',
    '    // --- Surnames ---',
    f'    /** The {SURNAME_COUNT:,} most common surnames in the 2010 Census, most common first: the people bearing it, then each group\'s share of them (%). */',
    '    public const SURNAMES = [',
]
for _, row in head.iterrows():
    values = [str(int(row['count']))] + [php_number(row[g]) for g in CENSUS_GROUPS]
    out.append(f"        {php_string(row['spelled'])} => [{', '.join(values)}],")
out += [
    '    ];',
    '',
    '    // --- Given names ---',
    f'    /** The {GIVEN_PER_DECADE} most common given names of each sex among Social Security card applicants born in each decade, by decade: applicants bearing it. */',
    '    public const GIVEN = [',
]
for decade in DECADES:
    out.append(f'        {decade} => [')
    for sex in ('M', 'F'):
        out.append(f"            '{sex}' => [")
        out += wrap([f'{php_string(name)} => {n}' for name, n in given[decade][sex]], ' ' * 16)
        out.append('            ],')
    out.append('        ],')
out += [
    '    ];',
    '',
    '    /** Each group\'s share of mortgage applicants bearing a given name above (%), for the names Tzioumis (2018) reports. */',
    '    public const GIVEN_SHARES = [',
]
for name in sorted(given_shares):
    out.append(f"        {php_string(name)} => [{', '.join(php_number(v) for v in given_shares[name])}],")
out += [
    '    ];',
    '    /** Each group\'s share of all the mortgage applicants Tzioumis (2018) reports (%): the shares for a given name it does not list. */',
    '    public const GIVEN_UNLISTED_SHARES = [' + ', '.join(php_number(v) for v in unlisted.tolist()) + '];',
    '',
    '    // --- Famous people ---',
    f'    /** Names the tables above can make that a famous person holds ({FAMOUS_MIN_SITELINKS}+ Wikidata sitelinks), so no public figure is drawn under one. */',
    '    public const FAMOUS = [',
]
out += wrap([php_string(name) for name in famous], ' ' * 8)
out += ['    ];', '}', '']
OUT.write_text('\n'.join(out))
print(f'wrote {OUT} ({OUT.stat().st_size / 1024:.0f} KB)')
