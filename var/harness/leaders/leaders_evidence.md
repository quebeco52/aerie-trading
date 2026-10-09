# Party leaders: exit hazard, age, tenure, timing, PM succession — evidence

Compiled 2026-10-04 for the Diet party-leader feature. Research only; nothing under src/, tests/ or templates/ was touched.

Flags: **[P]** = read by me from the primary paper, or computed by me from the primary data with the scripts in this
directory ("[P, fit]"). **[S]** = secondary source or general knowledge, not checked against data here.

Files in `var/harness/leaders/`:

| file | what |
|---|---|
| `fit_leader_exit.py` → `fit_leader_exit.out` | main fit: COSPAL x ParlGov, 8 Western European countries |
| `crosscheck.py` → `crosscheck.out` | Horiuchi et al. and O'Brien replication data; COSPAL/Horiuchi age agreement |
| `glm.py` | pure-Python logit/cloglog (Newton–Raphson, model-based SEs); numpy/scipy/statsmodels are **not** installed |
| `dta114.py` | pure-Python Stata .dta (format 113–115) reader |
| `data/` | downloaded datasets, paper PDFs/text, derived CSVs (described in §6) |

---

## 1. Published models of leader exit (coefficients as printed)

### 1a. Ennser-Jedenastik & Schumacher (2015), chapter in Cross & Pilet (eds.), OUP — 14 countries, 1965–2012 [P]
COSPAL data: Australia, Austria, Belgium, Canada, Denmark, Germany, Hungary, Israel, Italy, Norway, Portugal, Romania,
Spain, UK. Cox proportional hazards, country dummies, **leader-month** spells. N = 30,727 leader-months, 525 leaders,
365 failures. Death, illness, term limits and founding a new party are censored. Figures are **hazard ratios** (t in brackets).

| variable | Model II | Model IV |
|---|---|---|
| Electoral performance = vote-share change at the most recent election, **per percentage point**; coded 0 before the leader's first election | 0.940 (−6.50) | 0.946 (−5.55) |
| Party in government | 1.142 (0.96) | 1.162 (1.07) |
| Party leader is prime minister | **0.353** (−4.11) | **0.370** (−3.88) |
| Party lost office in the last 12 months | **2.529** (4.95) | **2.356** (4.51) |
| Party entered government in the last 12 months | 0.701 (−1.10) | 0.745 (−0.91) |
| Removal by party members (ref. party conference) | – | 1.834 (3.17) |
| Removal by leader / council / parliamentary group | – | 0.350 / 1.048 / 1.056 (all n.s.) |
| Female leader / prior national office | – | 0.938 / 1.148 (n.s.) |
| log-likelihood | −1848.5 | −1826.1 |

* Footnote 7: re-running Model IV with categories (ref. −1…+1 pp): **loss > 1 pp HR 2.15 (p = 0.000)**, gain > 1 pp HR 0.94
  (p = 0.686) — losses drive the effect, gains are not rewarded. [P]
* The text says PM hazards are "63 times lower"; the HR of 0.37 means **63% lower** (typo in the manuscript). [P]
* Baseline: Cox, so none is printed. Crude rate from the counts = 365 / 30,727 = 1.19% per leader-month ≈ 0.143/yr. [P, derived]
* "Extended mean" tenure just under 7 years; Belgium, Hungary, Australia < 5 y; **Denmark 8.4 y**, Italy 9.1 y, Spain 10.8 y;
  after 5 years fewer than 50% of leaders are still in office. By removal body: members 4.3 y, council 7.7, parliamentary
  group 6.7, conference 7.1, self-appointed founders 23.7 y. [P]

### 1b. Ennser-Jedenastik & Schumacher (2021), EJPR 60(1):114–130 — same 14 countries, 1965–2012 [P]
Cox with country shared frailty, leader-month spells, leader-clustered SEs. N = 30,657 spells, 518 leaders, 361 failures,
logL −1777.3. **Raw coefficients** (SE). Windows are "within the past 12 months under the incumbent leader".

| variable (Model I) | coef (SE) | HR |
|---|---|---|
| No election in past 12 m (ref. vote loss ≥ 1 pp) | −0.696 (0.161) | 0.50 |
| Vote change between −1 and +1 pp | −0.261 (0.180) | 0.77 |
| Vote gain ≥ 1 pp (median gain in that group +3.4 pp) | −0.886 (0.241) | 0.41 |
| Party leaves government (ref. stays in opposition) | 0.471 (0.195) | 1.60 |
| Party stays in government | −0.181 (0.144) | 0.83 (p = 0.21) |
| Party enters government | −0.733 (0.320) | 0.48 |
| Grace period (before the leader's first election) | −0.780 (0.188) | 0.46 |
| Leader age ≤ 45 (ref. 46–59) | −0.259 (0.155) | 0.77 (p < 0.1) |
| Leader age ≥ 60 | **0.424 (0.134)** | **1.53** |
| Seat share > 50% (ref. < 10%); 10–25%; 25–50% | −1.108 (0.395); −0.160; 0.028 | |
| Removal by members (ref. delegates) | 0.567 (0.172) | 1.76 |
| Female; political experience | −0.040; 0.168 (n.s.) | |

Their Table 2 gives the **raw monthly probability of a leader change** (failures in brackets) — directly usable [P]:

| office status (past 12 m) \ election result (past 12 m) | no election | vote loss ≥ 1 pp | −1…+1 pp | gain ≥ 1 pp | total |
|---|---|---|---|---|---|
| exits government | 2.4% (9) | 4.6% (18) | 3.7% (11) | 0.9% (1) | 3.3% (39) |
| stays in opposition | 0.9% (124) | 3.1% (36) | 1.5% (43) | 1.2% (19) | 1.1% (222) |
| stays in government | 0.9% (62) | 3.0% (19) | 0.6% (5) | 0.6% (3) | 1.0% (89) |
| enters government | 0.9% (4) | 0.5% (1) | 0.9% (3) | 0.5% (3) | 0.7% (11) |
| total | 0.9% (199) | 3.1% (74) | 1.5% (62) | 0.9% (26) | 1.2% (361) |

Table 1 shares of leader-months: no election in past 12 m 0.691, little change 0.139, gain 0.091 (so loss 0.079);
leaves gov 0.038, stays in gov 0.280, enters 0.052; grace period 0.367; age ≤ 45 0.247, ≥ 60 0.181. Median tenure
"just under 4.5 years". The PM-instead-of-government variant is in their online appendix (Table A3), which I could not read. [P]

### 1c. Horiuchi, Laing & 't Hart (2015), Party Politics 21(3):357–366 — 23 democracies, post-1945 to 1 Oct 2009 [P]
Includes Austria, Denmark, Germany, Greece, Ireland, Luxembourg, Malta, Netherlands, Norway, Portugal, Spain, Sweden, UK
(+ Australia, Canada, NZ, Israel, Japan, India, Caribbean). Cox stratified by 66 parties, robust clustered SEs, **hazard
ratios**, N = 448 leaders (63 censored), time in years.

| | Model 2 | Model 4 |
|---|---|---|
| Predecessor's tenure (per year) | 1.024 | – |
| Predecessor medium / long term (ref. short; mean 1.6 / 5.4 / 14.7 y) | – | 0.989 / **1.579** |
| Predecessor had been head of government (not at handover) | 2.226 | 2.136 |
| Predecessor was the sitting head of government | 2.176 | 2.023 |
| **Age at succession, per year** (centred) | **1.046** (z 4.96) | 1.044 |
| Calendar year of succession, per year | 1.026 (z 6.03) | 1.025 |
| Female | 0.725 (n.s.) | 0.673 (n.s.) |

Descriptives: arithmetic mean tenure 5.9 y; 110/448 (24.6%) gone before 2 years, 177 (39.5%) before 3 years, **95 (21.2%)
lasted 10 years or more**; **mean age at succession 50.5**; older-than-average leaders 4.6 y (n 206) vs younger 7.0 y (n 242). [P]

### 1d. Others
* **Andrews & Jackman (2008)**, BJPS 38(4):657–675: Australia, Britain, Canada, Germany, Ireland, NZ, 1945–2000; seat share and
  presence in government cut the removal hazard; a major party losing office raises it "dramatically". **Only the abstract
  could be read** (Cambridge returned 403; no open copy) — no coefficients pinned. [P, abstract]
* **Ennser-Jedenastik & Müller (2015)**, Party Politics 21(6) (online 2013): all Austrian party leaders 1945–2011;
  performance plus intra-party support and selection rules matter. **Abstract only**, coefficients not read. [P, abstract]
* **Bynander & 't Hart (2007)**, AJPS 42(1):47–72: two major parties of UK, Australia, Sweden, Netherlands, 1945–~2005:
  63.1% of leaders lasted > 48 months; mean tenure 75 months (SD 61); exits 34% fully voluntary, 31% voluntary after urging,
  23% removed by party decision, 11% health or death; main trigger a bad election 25%, internal rivalry 23%; 67% of forced
  resignations were in the UK or Australia. [P]
* **O'Brien (2015)**, AJPS "Rising to the top": 71 parties, 11 democracies (incl. Denmark, Sweden, Finland, Austria, Germany,
  Ireland, UK), 1965–2013, annual cloglog models. Paper not read; replication data downloaded and used in §3. Her seat-change
  variable is blank for many rows (e.g. Swedish SAP), so I did not refit her model.

---

## 2. Own fit — COSPAL x ParlGov, 8 Western European countries [P, fit]

**Data.** COSPAL v2 (Cross, Pilet & Pruysers 2019, CC0), party-year panel with every leadership change, its date, the new
leader's age and the reason the predecessor left. Countries used: Austria, Belgium, Denmark, Germany, Norway, Portugal,
Spain, UK (COSPAL's Western European set; Sweden, Finland and the Netherlands are not in COSPAL). Parties mapped to ParlGov
ids (via CMP codes, hand-checked); party-years with a collective leadership dropped (Ecolo, German Greens, Enhedslisten,
early PDS, AfD). Vote change Δv = party vote share at election minus at the previous election (ParlGov, percentage points).
Office status from ParlGov cabinets: "after" = the first non-caretaker cabinet formed from that election. Only parties with
seats. Exit = COSPAL change with a named successor. Elections 1957–2016.

**(A) Probability the leader leaves within 12 months after an election** (party × election, n = 557, 118 exits = 21.2%;
6-month window 71 = 12.7%). Δv: mean −0.1, SD 4.4, p10 −4.7, p90 +4.5 pp.

Raw shares (exits/n):

| status after election | loss ≥ 1 pp | −1…+1 | gain ≥ 1 pp | all |
|---|---|---|---|---|
| holds PM | 4/34 = 12% | 3/16 = 19% | 1/49 = 2% | 8/99 = 8% |
| junior in government | 15/36 = 42% | 7/36 = 19% | 5/29 = 17% | 27/101 = 27% |
| opposition (no change) | 31/93 = 33% | 18/105 = 17% | 12/100 = 12% | 61/298 = 20% |
| lost office at this election | 22/40 = 55% | 0/12 | 0/7 | 22/59 = 37% |
| all | 72/203 = 35% | 28/169 = 17% | 18/185 = 10% | 118/557 = 21% |

Logit coefficients (SE), Δv per percentage point:

| model | sample | const | Δv | Δv<0 part | Δv>0 part | PM | junior gov | lost office |
|---|---|---|---|---|---|---|---|---|
| M1 | WE8, n 557 | −1.395 (0.149) | −0.172 (0.032) | | | −1.142 (0.412) | 0.240 (0.275) | 0.122 (0.350) |
| M2 | WE8 | −1.475 (0.177) | | −0.196 (0.043) | −0.115 (0.071) | −1.198 (0.419) | 0.246 (0.276) | 0.074 (0.358) |
| M5 | WE8 | −1.325 (0.121) | −0.175 (0.030) | | | −1.219 (0.400) | | |
| M1 | excl. Belgium, n 467 | −1.491 (0.161) | −0.199 (0.038) | | | −2.520 (0.755) | −0.361 (0.389) | −0.145 (0.417) |
| **M5** | **excl. Belgium** | **−1.558 (0.146)** | **−0.193 (0.034)** | | | **−2.430 (0.744)** | | |
| **M6** | **excl. Belgium** | **−1.773 (0.191)** | | **−0.2445 (0.046)** | **−0.053 (0.074)** | **−2.576 (0.756)** | | |
| M1 | Denmark + Norway, n 211 | −1.726 (0.262) | −0.141 (0.062) | | | −1.757 (1.062) | −0.461 (0.591) | −1.248 (0.825) |

Other checks: excluding force-majeure exits (death/illness) changes nothing (M1 Δv −0.176, PM −1.097); adding "first
election as leader" +0.314 (0.222, n.s.); adding age per decade (−50) +0.206 (0.132, n.s.; leaders with known age, n 484);
6-month window: const −2.024, Δv −0.145, PM −1.526.

Why exclude Belgium for the PM term: Belgian party presidents are never the PM, so "the PM's party" is not "the leader is
PM" there (Belgian PM-party leaders: 8 exits in 14 leader-years of post-election exposure). Outside Belgium only 2 of 86 PM-party leaders left
within 12 months of an election. "Lost office" and "junior partner" add nothing once Δv is in: every lost-office exit in
the sample came with a vote loss ≥ 1 pp (22/40 vs 0/19). Note EJS find a separate lost-office effect (HR 1.6–2.5) with
continuous time and more countries; mine is a 12-month window on WE8.

**(B) Exit hazard per leader-year, parliamentary parties** (all exits; 1,980 leader-years, 310 exits, 0.157/yr overall):

| window | PM | junior gov | opposition | all |
|---|---|---|---|---|
| first 12 m after election, WE8 | 0.117 | 0.281 | 0.224 | 0.215 |
| first 12 m, excl. Belgium | 0.045 | 0.189 | 0.210 | |
| **mid-term (12 m after election → next election), WE8** | 0.127 | 0.169 | 0.121 | **0.131** |
| **mid-term, excl. Belgium** | **0.122** | **0.199** | **0.121** | |
| mid-term, leader has already fought the last election | 0.161 | 0.204 | 0.142 | 0.156 |
| mid-term, leader chosen since the last election (grace) | 0.032 | 0.125 | 0.090 | 0.090 |

Excluding force majeure the mid-term rate is 0.123/yr. Per country mid-term opposition rates: DNK 0.09, NOR 0.15, GBR 0.10,
DEU 0.12, AUT 0.10, ESP 0.13, PRT 0.15, BEL 0.12 (see the .out file).

Life tables from COSPAL WE8 spells (n 363 spells starting in the panel):
* by tenure: 0–1 y 0.067/yr, 1–2 y 0.174, 2–4 y 0.209, 4–6 y 0.157, 6–10 y 0.136, 10+ y 0.247;
* by current age: < 45 0.091/yr, 45–55 0.156, 55–60 0.197, 60–65 0.215, 65+ 0.342.

**Independent annual rates, O'Brien (2015) data** (party-years in her analysis sample, all exits): Denmark gov 0.093 / opp
0.146; **Sweden gov 0.069 / opp 0.158**; Finland gov 0.128 / opp 0.188; Nordic-3 all 0.142/yr; 7 European countries all
0.148/yr. [P, fit]

---

## 3. Age at first selection and tenure

| source | sample | age at selection | tenure |
|---|---|---|---|
| COSPAL WE8 [P, fit] | 354 leaders with age (363 spells), 1965–2018 | mean **47.0**, SD **9.2**, min 23, p10 36, p25 40, median 46, p75 53, p90 60, max 74 | KM median **4.1 y**; S(2) 0.79, S(5) 0.44, **S(10) 0.22**, S(15) 0.06; restricted mean 6.1 y |
| Horiuchi WE-13 [P, fit] | 281 leaders (each party's first leader dropped, as in the paper), to 2009 | mean 48.4, SD 8.1, min 29, p10 38, median 48, p90 59, max 75 | KM median 5.9 y; S(5) 0.53, **S(10) 0.32**; restricted mean 7.2 y |
| Horiuchi Nordic (DNK NOR SWE) [P, fit] | 90 leaders | mean **47.3**, SD **7.1**, min 35, p10 39, median 47, p90 56 | KM median **6.3 y**; S(5) 0.60, **S(10) 0.39**; restricted mean 7.5 y |
| O'Brien Nordic-3 (DNK SWE FIN) [P, fit] | new-leader rows | mean 45.0 (n 174); DNK 46.4, SWE 45.2, FIN 43.8 | – |
| Horiuchi et al. paper [P] | 448 leaders, 23 countries | mean 50.5 | mean 5.9 y; 21.2% ≥ 10 y; 24.6% < 2 y |
| EJS 2015 / 2021 [P] | 14 countries | – | extended mean ~7 y; median just under 4.5 y |
| Bynander & 't Hart [P] | UK, AUS, SWE, NLD major parties | – | mean 75 months (SD 61); 63.1% > 48 months |

Data quality: the same leader's age agrees within 1 year between COSPAL and Horiuchi in 112 of 119 matches; 5 differ by > 2 y
(e.g. Horiuchi gives Trygve Bratteli 75 in 1965, COSPAL 55 — COSPAL is right [S: born 1910]). COSPAL WE8 includes small and
new parties (youngest leaders 23–30: Schmidt-Nielsen, Rivera, Kurz, Van Grieken, Sjursen), which is why its SD is wider.

---

## 4. Timing: exits right after an election vs mid-term

* COSPAL WE8 [P, fit]: **42.8%** of non-force-majeure exits (n 283 dated) come **within 12 months** after the last election,
  against 32.3% of leader-time spent in that window (rate ratio ≈ 1.6); **25.8% within 6 months** (17.6% of time).
  Of the exits within 12 months, 60% (71/118) are in the first 6 months.
* EJS 2021 Table 1–2 [P, derived]: 162 of 361 failures (**44.9%**) fall in leader-months with an election in the past 12 months,
  which are 30.9% of leader-months — monthly rate 1.71% vs 0.94% (ratio 1.8).
* COSPAL's own coding of why the predecessor left [P, fit], changes with a coded reason: WE8 n 360 — **post-election
  resignation (≤ 1 year after the election) 38.1%**, voluntary 35.3%, resigned under pressure 14.7%, force majeure 5.3%,
  formally removed 4.4%, term limit 2.2%. All 14 countries (n 497): 36.0 / 31.8 / 14.7 / 6.0 / 8.5 / 3.0%. ("Post-election"
  covers resignations only, so it understates post-election exits.)

---

## 5. PM succession between elections

**ParlGov cabinets, 1945–2023** [P, fit] — a PM replaced by a PM of the **same party with no election in between**:

| country | elections | intra-party PM changes | per decade | cases |
|---|---|---|---|---|
| Sweden | 23 | 5 | 0.64 | 1946 Hansson→Erlander, 1969 Erlander→Palme, 1986 Palme→Carlsson, 1996 Carlsson→Persson, **2021 Löfven→Andersson** |
| Denmark | 29 | 5 | 0.64 | 1955 Hedtoft→Hansen, 1960 Hansen→Kampmann, 1962 Kampmann→Krag, 1972 Krag→Jørgensen, 2009 Fogh→Løkke Rasmussen |
| Norway | 20 | 5 | 0.64 | 1951 Gerhardsen→Torp, 1955 Torp→Gerhardsen, 1976 Bratteli→Nordli, 1981 Nordli→Brundtland, 1996 Brundtland→Jagland |
| Finland | 22 | 9 | 1.14 | … 2010 Vanhanen→Kiviniemi, 2014 Katainen→Stubb, 2019 Rinne→Marin |
| Iceland | 24 | 3 | 0.39 | |
| UK | 21 | 10 | 1.27 | 1955, 1957, 1963, 1976, 1990, 2007, 2016, 2019, 2022 ×2 |
| Ireland | 21 | 6 | 0.79 | |
| Austria | 23 | 7 | 0.89 | |
| Germany | 20 | 3 | 0.40 | 1963, 1966, 1974 |
| Netherlands | 23 | 1 | 0.13 | |
| Belgium | 23 | 10 | 1.27 | |
| Luxembourg / Portugal / Spain | 17 / 17 / 15 | 3 / 2 / 1 | 0.38 / 0.41 / 0.22 | |
| Australia / NZ / Canada | 30 / 26 / 25 | 8 / 8 / 5 | 1.02 / 1.04 / 0.64 | |
| **all 17** | | **91** | **0.72** (0.072 per year of premiership) | |

Sweden + Denmark + Norway: 15 in ~234 years of premiership ≈ **0.064 per year**.

**Does the new party leader become PM without an election?**
* Horiuchi et al.'s data flag 52 Western European leaders who took over the party of a sitting head of government; they
  include every Swedish case to 2009 (Erlander, Palme, Carlsson, Persson), every Danish one (H.C. Hansen, Kampmann, Krag,
  Jørgensen, Løkke Rasmussen), every British one (Eden, Macmillan, Home, Callaghan, Major, Brown) and the Irish Fianna Fáil
  ones — in each of these the new party leader became PM at once, mid-term. [P, data]
* Sweden 2021: Andersson was elected Social Democrat leader on 4 Nov 2021 and became PM on 30 Nov 2021 (Riksdag vote, no
  election). [S] The ParlGov cabinet list confirms the PM change with no election between (2018 election, "Andersson" cabinet 2021-11-30). [P]
* Exceptions, where the party chair and the PM are different people [P, fit — COSPAL x ParlGov, 42 leader changes in
  PM parties, WE8]: in 14 the PM was involved (3 new leaders became PM before the next election, 11 PMs took the party post
  after becoming PM — e.g. Austria's SPÖ 1983/1988/1997/2016, Denmark 1973/2009, Norway 1981, Germany 1999); in 28 the new
  leader was not the PM — Belgium 12 (party presidents are never PM), Norway 7 (Labour split the roles 1975–81 and 1992–96;
  Conservative chairs under PM Willoch), Portugal 5, Germany 2 (SPD under Schröder), Austria 1, Denmark 1. Also Finland 2019 (Marin PM while Rinne still SDP chair) and Germany 1974 (Schmidt
  Chancellor, Brandt kept the SPD chair). [S]

**Conclusion:** in the Swedish/Danish/Westminster pattern the Diet copies, the leader of the PM's party *is* the PM, and
a mid-term leadership change hands the premiership to the new leader without an election — every time in Sweden
(5/5) and Denmark (5/5) since 1945.

---

## 6. Datasets saved (`var/harness/leaders/data/`)

| file | source | columns / notes |
|---|---|---|
| `cospal/cospal_aggregated_2019.tab` (+ `.xlsx` original, codebook `.docx` and `.txt`) | COSPAL v2, Harvard Dataverse doi:10.7910/DVN/UNPG85, **CC0** | party-year panel, 14 countries, 1955–2018, 3,508 rows, 80 columns: `Country`, `PartyName`, `partycode` (CMP), `Year`, `partyingovernment?`, `votesharechange`, `seatsharechange`, `changeofleader`, `Namenewleader`, `age` (at first selection), `gender`, `Dateofleadershipchange` (YYYYMMDD as float), `reasonforendofleadership` (1 force majeure, 2 removed, 3 resigned under pressure, 4 term limit, 5 voluntary, 6 post-election resignation ≤ 1 y), `datelastgeneralelections`, selectorate rules. 98 = n/a, 99 = missing |
| `horiuchi2013_leaders.csv` (+ `horiuchi2013_repl/` original .dta, .do, .log) | Horiuchi, Laing & 't Hart replication, doi:10.7910/DVN/BOF2WR | 514 leader spells, 23 countries: `country, party_id, party_seq, party_name, name, female, in_date, in_year, out_date` (blank = in office on 2009-10-01), `phg` (predecessor had been head of government), `igt` (succession while the party held government), `age` (at taking office) |
| `obrien2015/partyleaders.tab` (+ R code) | O'Brien replication, doi:10.7910/DVN/27631, CC0 | party-years 1959–2013, 11 countries (incl. DNK, SWE, FIN, AUT, DEU, IRL, GBR): `Country, Party, Leader, Year, gov, majorparty, seatdiff, seatshare, Female, leaderage, LeaderDeath` (= leader's tenure ends), `LeaderYear` (tenure), `NewLeader`, `LeaderInclude.*` sample flags |
| `wikidata_party_leaders.csv` | Wikidata SPARQL (query.wikidata.org works once the host is allowed) | `country, party, partyLabel, leader, leaderLabel, start, end, birth, src` (P488 chairperson qualifiers P580/P582, or P39 of the party's P2388 office). 2,145 rows, 16 countries. **Coverage is patchy** (e.g. 11 spells for the Swedish SAP, 4 for the Danish Social Democrats), so it was not used for fitting |
| `cospal_we_party_elections.csv` | derived (fit_leader_exit.py) | the logit sample: `country, party, election, vote, dv, seats, before, after` (pm/gov/opp), `exit12, exit6, exit12_nofm, fm_only, first_election, tenure, age, days_to_next_election, exit_names` |
| `cospal_we_spells.csv` | derived | `country, party, leader, start, end, years, exit_observed, age_at_selection, start_date_missing` |
| `ejs2015.pdf/.txt`, `ejs2020_submitted.pdf`/`ejs2020.txt`, `horiuchi2013.pdf/.txt`, `bynander2007.pdf/.txt` | open repository copies | the papers read above |

ParlGov (`var/harness/politics/parlgov/`) was used as-is for election dates, vote shares, seats and cabinets.

---

## 7. Recommended model

Use the **two-part model fitted here on COSPAL Western Europe excluding Belgium (M6 + mid-term hazard)**. It is the only
estimate with an absolute level (Cox papers give relative hazards only), it uses exactly the Diet's quantities (vote
change in pp, PM status) and its effects line up with the published Cox estimates: losses matter and gains barely do
(EJS 2015 footnote 7: loss HR 2.15, gain 0.94 n.s.), and the PM premium (EJS 2015: HR 0.35–0.37; here OR 0.08 once
Belgium's non-PM party presidents are removed). Its cell rates also reproduce EJS 2021 Table 2 (vote loss 3.1%/month →
31% in 12 months vs 35% here; no election 0.9%/month → 10%/yr vs 12–13%/yr here).

1. **At each general election, for every party:**
   `P(leader leaves within 12 months) = logistic(−1.773 − 0.2445·min(Δv,0) − 0.053·max(Δv,0) − 2.576·PM)`
   with Δv = change in national vote share in percentage points, PM = the party leads the cabinet formed after the
   election. Examples: opposition, Δv = 0 → 14.5%; Δv = −5 → 37%; PM, Δv = 0 → 1.3%. If a symmetric slope is preferred,
   M5: `−1.558 − 0.193·Δv − 2.430·PM`. Place the exit inside the window with 53–60% in the first 6 months (44/83 excl. Belgium, 71/118 all WE8).
   Junior-partner and lost-office terms are not identified once Δv is in; leave them out (or, if wanted, use EJS 2021's
   leaves-government HR 1.60 on the post-election probability, accepting that it partly double-counts Δv).
2. **Between elections** (from 12 months after the election to the next one): constant hazard **0.13 per year**
   (opposition 0.121, PM 0.122, junior partner 0.199 — the junior-partner figure rests on 29 exits). For a leader chosen since
   the last election (has not yet fought one) multiply by **0.46** (EJS 2021 grace-period coefficient −0.780; this fit gives
   0.090/0.156 = 0.58).
3. **Age:** multiply the hazard by **1.53 when the leader is 60+** and 0.77 when ≤ 45 (EJS 2021, controls for performance and
   office). Do not also add Horiuchi's per-year age HR (1.046) — it is the same effect.
4. **New leaders' age at selection:** normal with **mean 47, SD 9**, truncated to about 28–74 (COSPAL WE8: mean 47.0, SD 9.2,
   p10 36, p90 60; Nordic leaders in Horiuchi's data: 47.3, SD 7.1).
5. **PM succession:** when the PM's party changes leader between elections, the new leader becomes PM with no election
   (Sweden 5/5, Denmark 5/5 since 1945). The model above implies it; as a check, real intra-party mid-term PM changes run at
   **≈ 0.064 per year of a party's premiership** in Sweden/Denmark/Norway (0.072 across 17 parliamentary democracies).

Checks the simulator should meet (WE8 excl. Belgium, mean Δv spread SD 4.4 pp): average post-election exit 17.8% of party
leaders; with the 4-year Diet calendar the implied annual hazard ≈ 0.147 → median tenure ≈ 4.7 y, mean ≈ 6.8 y, ≈ 23%
lasting > 10 years — against observed KM medians 4.1 y (COSPAL WE8), 5.9 y (Horiuchi WE), 6.3 y (Horiuchi Nordic) and
S(10) 0.22–0.39. Real data have early elections (mean gap 3.4 y), so the post-election shock comes slightly more often
than on the Diet's fixed calendar.

**What could not be pinned:** Andrews & Jackman's and Ennser-Jedenastik & Müller's coefficient tables (paywalled); EJS
2021's appendix Table A3 (PM version); a Sweden/Finland vote-change slope (not in COSPAL; O'Brien's seat-change field is
largely blank). No estimate is specific to Nordic minority-cabinet support parties; the Denmark+Norway-only fit
(n 211, Δv −0.141, PM −1.76 n.s.) is consistent with the pooled one but imprecise.

---

## Sources

* Ennser-Jedenastik, L. & Schumacher, G. (2015). Why Some Leaders Die Hard (and Others Don't): Party Goals, Party Institutions,
  and How They Interact. In W. Cross & J.-B. Pilet (eds.), *The Politics of Party Leadership*, OUP, 107–127.
  doi:10.1093/acprof:oso/9780198748984.003.0007 — submitted manuscript: https://pure.uva.nl/ws/files/2804226/175765_why_some_leaders_die_hard.pdf
* Ennser-Jedenastik, L. & Schumacher, G. (2021). What parties want from their leaders: How office achievement trumps electoral
  performance as a driver of party leader survival. *European Journal of Political Research* 60(1), 114–130.
  doi:10.1111/1475-6765.12391 — https://pure.uva.nl/ws/files/88493961/European_J_Political_Res_2020_ENNSER_JEDENASTIK_What_parties_want_from_their_leaders_How_office_achievement_trumps.pdf
* Horiuchi, Y., Laing, M. & 't Hart, P. (2015). Hard acts to follow: Predecessor effects on party leader survival. *Party Politics*
  21(3), 357–366. doi:10.1177/1354068812472577 — https://dspace.library.uu.nl/handle/1874/395569 ;
  replication data doi:10.7910/DVN/BOF2WR (https://dataverse.harvard.edu/dataset.xhtml?persistentId=doi:10.7910/DVN/BOF2WR)
* Bynander, F. & 't Hart, P. (2007). The Politics of Party Leader Survival and Succession: Australia in Comparative Perspective.
  *Australian Journal of Political Science* 42(1), 47–72. doi:10.1080/10361140601158542 — https://dspace.library.uu.nl/handle/1874/395723
* Andrews, J. T. & Jackman, R. W. (2008). If Winning Isn't Everything, Why Do They Keep Score? *British Journal of Political
  Science* 38(4), 657–675. doi:10.1017/S000712340800032X (abstract only)
* Ennser-Jedenastik, L. & Müller, W. C. (2015). Intra-party democracy, political performance and the survival of party leaders:
  Austria 1945–2011. *Party Politics* 21(6). doi:10.1177/1354068813509517 (abstract only)
* O'Brien, D. Z. (2015). Rising to the Top: Gender, Political Performance, and Party Leadership in Parliamentary Democracies.
  *American Journal of Political Science* 59(4), 1022–1039. Replication data doi:10.7910/DVN/27631
* Cross, W., Pilet, J.-B. & Pruysers, S. (2019). Comparative Study of Party Leaders (COSPAL) v2. Harvard Dataverse,
  doi:10.7910/DVN/UNPG85 (CC0). Book: Pilet & Cross (eds.) (2014), *The Selection of Political Party Leaders in Contemporary
  Parliamentary Democracies*, Routledge.
* Döring, H. & Manow, P. ParlGov (Parliaments and governments database), local copy in `var/harness/politics/parlgov/`.
* Wikidata Query Service, https://query.wikidata.org/sparql (queried 2026-10-04).
