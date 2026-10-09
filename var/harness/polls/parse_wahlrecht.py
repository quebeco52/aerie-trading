"""Parse the wahlrecht.de Bundestag vote-intention tables (one HTML page per institute and
archive period, downloaded 2026-10-05 into data/wahlrecht/) into one long CSV.

Output: data/de_polls_long.csv  (institute, date, party, share_pct, n, mode, field)
        data/de_results.csv     (election date, party, share_pct) from the tables' result rows
Excluded pages: East/West sub-samples, and the raw "Stimmung" (mood) pages, which are kept
separately in data/de_polls_stimmung_long.csv for the raw-vs-projection check.
"""
import glob
import os
import re

import pandas as pd
from lxml import html

SRC = "data/wahlrecht"
PARTY_MAP = {"CDU/CSU": "UNION", "CDU": "UNION", "SPD": "SPD", "GRÜNE": "GRUENE", "FDP": "FDP",
             "PDS": "LINKE", "Linke.PDS": "LINKE", "LINKE": "LINKE", "AfD": "AFD", "PIRATEN": "PIRATEN",
             "FW": "FW", "BSW": "BSW", "REP": "RECHTE", "REP/DVU": "RECHTE", "Rechte": "RECHTE",
             "Sonstige": "SONST"}
ELECTIONS = ["1998-09-27", "2002-09-22", "2005-09-18", "2009-09-27", "2013-09-22", "2017-09-24",
             "2021-09-26", "2025-02-23"]
DATE_RE = re.compile(r"(\d{2})\.(\d{2})\.(\d{4})")
NUM_RE = re.compile(r"(\d+(?:,\d+)?)\s*%")


def institute_of(fname):
    base = os.path.basename(fname)[:-4]
    return base.split("_")[0]


def num(s):
    s = s.replace("\xa0", " ")
    if "<" in s or ">" in s:
        return None
    m = NUM_RE.search(s)
    return float(m.group(1).replace(",", ".")) if m else None


def parse_n(s):
    s = s.replace("\xa0", " ")
    mode = s.split("•")[0].strip() if "•" in s else ""
    digits = re.findall(r"\d[\d\.]*", s.split("•")[-1])
    if not digits:
        return None, mode
    return int(digits[0].replace(".", "")), mode


def parse_file(f):
    root = html.parse(f).getroot()
    tabs = root.xpath('//table[@class="wilko"]')
    if not tabs:
        return [], []
    tab = tabs[0]
    hd = ["".join(th.itertext()).strip() for th in tab.xpath("./thead/tr[1]/*")]
    polls, results = [], []
    for tr in tab.xpath("./tbody/tr"):
        tds = tr.xpath("./td")
        txt = ["".join(td.itertext()).strip() for td in tds]
        if not txt:
            continue
        first_cls = tds[0].get("class") or ""
        is_result = first_cls == "ws" or txt[0].startswith("Wahl") or "Bundestagswahl" in " ".join(txt)
        m = DATE_RE.search(txt[0])
        date = f"{m.group(3)}-{m.group(2)}-{m.group(1)}" if m else None
        if is_result and date is None:
            yr = re.search(r"(\d{4})", txt[0])
            date = next((e for e in ELECTIONS if yr and e.startswith(yr.group(1))), None)
        if date is None:
            continue
        n, mode, field = None, "", ""
        if "Befragte" in hd and hd.index("Befragte") < len(txt) and not is_result:
            n, mode = parse_n(txt[hd.index("Befragte")])
        if "Zeitraum" in hd and hd.index("Zeitraum") < len(txt) and not is_result:
            field = txt[hd.index("Zeitraum")]
        for i, h in enumerate(hd):
            if h in PARTY_MAP and i < len(txt):
                v = num(txt[i])
                if v is None:
                    continue
                row = dict(date=date, party=PARTY_MAP[h], share_pct=v)
                if is_result:
                    results.append(row)
                else:
                    row.update(n=n, mode=mode, field=field)
                    polls.append(row)
    return polls, results


def main():
    allp, alls, allr = [], [], []
    for f in sorted(glob.glob(f"{SRC}/*.htm")):
        b = os.path.basename(f)
        if "_ost" in b or "_west" in b:
            continue
        polls, results = parse_file(f)
        inst = institute_of(f)
        for r in polls:
            r["institute"] = inst
            r["page"] = b
        (alls if "stimmung" in b else allp).extend(polls)
        for r in results:
            r["page"] = b
        allr.extend(results)
    p = pd.DataFrame(allp).drop_duplicates(["institute", "date", "party", "share_pct"])
    s = pd.DataFrame(alls).drop_duplicates(["institute", "date", "party", "share_pct"])
    r = pd.DataFrame(allr)
    r = r[r.date.isin(ELECTIONS)]
    res = r.groupby(["date", "party"]).share_pct.agg(["median", "min", "max", "size"]).reset_index()
    p.to_csv("data/de_polls_long.csv", index=False)
    s.to_csv("data/de_polls_stimmung_long.csv", index=False)
    res.to_csv("data/de_results.csv", index=False)
    print(f"polls: {p.groupby(['institute', 'date']).ngroups} poll releases, rows {len(p)}")
    print(p.groupby("institute").agg(first=("date", "min"), last=("date", "max"),
                                     releases=("date", "nunique"), n_median=("n", "median")).to_string())
    print(f"raw Stimmung pages: {s.groupby(['institute', 'date']).ngroups} releases")
    print("election results from table result rows (median across pages; min/max shown to flag mismatches):")
    print(res.pivot(index="date", columns="party", values="median").round(1).to_string())
    bad = res[(res["max"] - res["min"]) > 0.15]
    print("result mismatches across pages (>0.15 pt):", "none" if bad.empty else "\n" + bad.to_string())


if __name__ == "__main__":
    main()
