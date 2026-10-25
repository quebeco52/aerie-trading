"""US BEA detail-level gross output shares (1997-2025) for the Aerie industries: log share series per industry.

Run from this directory: python3 -I us_shares.py  ->  writes data/us_shares.json
"""
import csv, json, math, os, sys
from collections import defaultdict

HERE = os.path.dirname(os.path.abspath(__file__))
sys.path.insert(0, HERE)
from xlsx import sheet_grid  # noqa: E402

YEARS = list(range(1997, 2026))

FARMS = ['Oilseed farming', 'Grain farming', 'Vegetable and melon farming', 'Fruit and tree nut farming',
         'Greenhouse, nursery, and floriculture production', 'Other crop farming', 'Dairy cattle and milk production',
         'Beef cattle ranching and farming, including feedlots and dual-purpose ranching and farming',
         'Poultry and egg production', 'Animal production, except cattle and poultry and eggs']
GENERATION = ['Hydroelectric power generation', 'Fossil fuel electric power generation', 'Nuclear electric power generation',
              'Solar electric power generation', 'Wind electric power generation', 'Geothermal electric power generation',
              'Biomass electric power generation', 'Other electric power generation']
PACKAGED = ['Breakfast cereal manufacturing', 'Sugar and confectionery product manufacturing', 'Frozen food manufacturing',
            'Fruit and vegetable canning, pickling, and drying', 'Cheese manufacturing', 'Bread and bakery product manufacturing',
            'Cookie, cracker, pasta, and tortilla manufacturing', 'Snack food manufacturing', 'Coffee and tea manufacturing',
            'Seasoning and dressing manufacturing', 'All other food manufacturing', 'Soft drink and ice manufacturing']
HEALTH_SPEND = ['Offices of physicians', 'Offices of dentists', 'Offices of other health practitioners', 'Outpatient care centers',
                'Medical and diagnostic laboratories', 'Home health care services', 'Hospitals', 'Nursing and community care facilities']

# Aerie industry -> BEA detail gross-output lines (summed). None = not tracked / no usable proxy.
AERIE = {
    'Advertising Agencies': ['Advertising, public relations, and related services'],
    'Aerospace & Defense': ['Aircraft manufacturing', 'Aircraft engine and engine parts manufacturing',
                            'Other aircraft parts and auxiliary equipment manufacturing', 'Guided missile and space vehicle manufacturing'],
    'Airlines': ['Air transportation'],
    'Apparel Manufacturing': ['Clothing and clothing accessories stores'],
    'Auto Manufacturers': ['Automobile manufacturing', 'Light truck and utility vehicle manufacturing'],
    'Beverages - Wineries & Distilleries': ['Wineries', 'Distilleries', 'Breweries'],
    'Broadcasting': ['Radio and television broadcasting', 'Cable and other subscription programming'],
    'Building Products & Equipment': ['Air conditioning, refrigeration, and warm air heating equipment manufacturing', 'Millwork',
                                      'Wood kitchen cabinet and countertop manufacturing', 'Plumbing fixture fitting and trim manufacturing',
                                      'Cement manufacturing', 'Ready-mix concrete manufacturing'],
    'Chemicals': ['Petrochemical manufacturing', 'Other basic organic chemical manufacturing', 'Other basic inorganic chemical manufacturing',
                  'Plastics material and resin manufacturing'],
    'Communication Equipment': ['Telephone apparatus manufacturing', 'Broadcast and wireless communications equipment',
                                'Other communications equipment manufacturing'],
    'Computer Hardware': ['Electronic computer manufacturing', 'Computer storage device manufacturing',
                          'Computer terminals and other computer peripheral equipment manufacturing'],
    'Consulting Services': ['Management consulting services', 'Computer systems design services', 'Custom computer programming services',
                            'Environmental and other technical consulting services'],
    'Copper': ['Copper, nickel, lead, and zinc mining'],
    'Drug Manufacturers - General': ['Pharmaceutical preparation manufacturing'],
    'Education & Training Services': ['Other educational services', 'Junior colleges, colleges, universities, and professional schools'],
    'Electrical Equipment & Parts': ['Motor and generator manufacturing', 'Switchgear and switchboard apparatus manufacturing',
                                     'Power, distribution, and specialty transformer manufacturing',
                                     'Communication and energy wire and cable manufacturing',
                                     'All other miscellaneous electrical equipment and component manufacturing'],
    'Engineering & Construction': ['Office and commercial structures', 'Manufacturing structures', 'Power and communication structures',
                                   'Transportation structures and highways and streets', 'Other nonresidential structures',
                                   'Architectural, engineering, and related services'],
    'Farm & Heavy Construction Machinery': ['Farm machinery and equipment manufacturing', 'Construction machinery manufacturing',
                                            'Mining and oil and gas field machinery manufacturing'],
    'Farm Products': FARMS,
    'Financial Data & Stock Exchanges': ['Other financial investment activities', 'Data processing, hosting, and related services'],
    'Gold': ['Iron, gold, silver, and other metal ore mining'],
    'Grocery Stores': ['Food and beverage stores'],
    'Healthcare Plans': HEALTH_SPEND,
    'Household & Personal Products': ['Soap and cleaning compound manufacturing', 'Toilet preparation manufacturing',
                                      'Sanitary paper product manufacturing'],
    'Industrial Distribution': ['Machinery, equipment, and supplies', 'Other durable goods merchant wholesalers'],
    'Integrated Freight & Logistics': ['Truck transportation', 'Couriers and messengers', 'Warehousing and storage'],
    'Internet Content & Information': ['Internet publishing and broadcasting and Web search portals'],
    'Internet Retail': ['Nonstore retailers'],
    'Legal Services': ['Legal services'],
    'Luxury Goods': ['Jewelry and silverware manufacturing', 'Leather and allied product manufacturing'],
    'Marine Ports & Services': ['Scenic and sightseeing transportation and support activities for transportation'],
    'Marine Shipping': ['Water transportation'],
    'Medical Care Facilities': ['Hospitals', 'Nursing and community care facilities', 'Outpatient care centers'],
    'Merchant Houses': ['Grocery and related product wholesalers', 'Other nondurable goods merchant wholesalers'],
    'Nuclear Power Equipment': ['Power boiler and heat exchanger manufacturing', 'Turbine and turbine generator set units manufacturing'],
    'Oil & Gas E&P': ['Oil and gas extraction'],
    'Oil & Gas Refining & Marketing': ['Petroleum refineries'],
    'Packaged Foods': PACKAGED,
    'REIT - Healthcare Facilities': ['Nursing and community care facilities',
                                     'Residential mental health, substance abuse, and other residential care facilities'],
    'REIT - Industrial': ['Warehousing and storage'],
    'REIT - Office': ['Other real estate'],
    'REIT - Residential': ['Tenant-occupied housing'],
    'Railroads': ['Rail transportation'],
    'Residential Construction': ['Single-family residential structures', 'Multifamily residential structures'],
    'Resorts & Casinos': ['Gambling industries (except casino hotels)', 'Accommodation', 'Amusement parks and arcades'],
    'Restaurants': ['Full-service restaurants', 'Limited-service restaurants'],
    'Security & Protection Services': ['Investigation and security services'],
    'Semiconductors': ['Semiconductor and related device manufacturing', 'Semiconductor machinery manufacturing'],
    'Space Systems': ['Guided missile and space vehicle manufacturing', 'Propulsion units and parts for space vehicles and guided missiles',
                      'Satellite, telecommunications resellers, and all other telecommunications'],
    'Specialty Industrial Machinery': ['Other industrial machinery manufacturing', 'Other general purpose machinery manufacturing',
                                       'Fluid power process machinery', 'Packaging machinery manufacturing',
                                       'Valve and fittings other than plumbing'],
    'Steel': ['Iron and steel mills and ferroalloy manufacturing', 'Steel product manufacturing from purchased steel'],
    'Telecom Services': ['Wired telecommunications carriers', 'Wireless telecommunications carriers (except satellite)'],
    'Tobacco': ['Tobacco manufacturing'],
    'Tools & Accessories': ['Cutlery and handtool manufacturing', 'Power-driven handtool manufacturing'],
    'Utilities - Independent Power Producers': GENERATION,
    'Utilities - Regulated Electric': ['Electric power distribution', 'Electric bulk power transmission and control'],
    'Utilities - Regulated Water': ['Water, sewage and other systems'],
    'Waste Management': ['Waste management and remediation services'],
    'Conglomerates': None,
    'Investment Companies': None,
}


def load_detail():
    g = sheet_grid(os.path.join(HERE, 'data', 'UGrossOutput.xlsx'), 'UGO305-A')
    header = None
    rows = {}
    for r in g:
        v = [r[k] for k in sorted(r)]
        if v and v[0] == 'Line':
            header = v
            continue
        if header and len(v) == len(header) + 1:
            name = v[1].strip()
            vals = []
            for x in v[2:]:
                try:
                    vals.append(float(x))
                except ValueError:
                    vals.append(None)
            rows[name] = vals
    assert header[1:] == [str(y) for y in YEARS], header[:4]
    return rows


def gap_series():
    def annual(path, col):
        acc = defaultdict(list)
        with open(path) as f:
            for row in csv.DictReader(f):
                acc[int(row['observation_date'][:4])].append(float(row[col]))
        return {y: sum(v) / len(v) for y, v in acc.items() if len(v) == 4}
    real = annual(os.path.join(HERE, '..', 'us_gdpc1.csv'), 'GDPC1')
    pot = annual(os.path.join(HERE, '..', 'us_gdppot.csv'), 'GDPPOT')
    return [math.log(real[y] / pot[y]) if y in real and y in pot else 0.0 for y in YEARS]


def main():
    rows = load_detail()
    total = [sum(rows[n][i] for n in rows if rows[n][i] is not None) for i in range(len(YEARS))]
    out = {'years': YEARS, 'gap': gap_series(), 'industries': {}, 'missing_lines': []}
    for ind, lines in AERIE.items():
        if lines is None:
            continue
        miss = [ln for ln in lines if ln not in rows]
        if miss:
            out['missing_lines'] += miss
            continue
        s = []
        for i in range(len(YEARS)):
            vals = [rows[ln][i] for ln in lines]
            s.append(math.log(sum(vals) / total[i]) if all(v is not None and v > 0 for v in vals) else None)
        out['industries'][ind] = s
    with open(os.path.join(HERE, 'data', 'us_shares.json'), 'w') as f:
        json.dump(out, f)
    print('industries', len(out['industries']), 'missing lines', out['missing_lines'])
    print('gap', [round(x, 3) for x in out['gap']])


if __name__ == '__main__':
    main()
