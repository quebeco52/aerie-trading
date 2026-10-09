"""Rank loopfit.sh points: python3 var/harness/loopfit_rank.py [dir]

J = rule (rho, phi_y against the like-for-like US rule, policy_fit.json 'rule') + J_acf (CBO 1949-2019 ACF
4/8/12/16q, Bartlett se) + sd (CBO 1985-2019 1.63, se 0.26, the era DEMAND_SHOCK_SIGMA is set on) + J_path
(gap under the US post-surprise rate path) + J_timing (corr(policy_{t+k}, gap_t) shape).

phi_pi is printed, not fitted. The engine's rule answers the demand gap and sees through the productivity supply gap,
which moves against inflation, so a regression on the total gap loads that omitted term onto inflation (with the
supply gap held, the target splits back into its coded weights). On the US side the estimate runs 0.8-2.7 across
detrending and sample; its 1987-2008 level comes from the disinflation trend a stationary engine does not have.
"""
import glob, json, os, re, sys

H = os.path.dirname(os.path.abspath(__file__))
D = sys.argv[1] if len(sys.argv) > 1 else os.path.join(os.environ.get('TMPDIR', '/tmp'), 'loopfit')
US = json.load(open(os.path.join(H, 'policy_fit.json')))['rule']
SD_US, SD_SE = 1.63, 0.26
num = r'([+-]?[0-9.]+)'
rows = []
for f in sorted(glob.glob(os.path.join(D, '*.an'))):
    t = open(f).read()
    try:
        tag, pi_w, gap_w = re.search(r'point (\S+) inflation ' + num + ' gap ' + num, t).groups()
        rho, phi_pi, phi_y = map(float, re.search(r'\[2\] rule: engine rho ' + num + r' \([^)]*\), inflation ' + num + ', gap ' + num, t).groups())
        j_acf = float(re.search(r'J_acf ' + num, t).group(1))
        sd = float(re.search(r'sd ' + num + r' \(US', t).group(1))
        j_path = float(re.search(r'J_path ' + num, t).group(1))
        j_timing = float(re.search(r'J_timing ' + num, t).group(1))
    except AttributeError:
        print(f'{os.path.basename(f)}: incomplete')
        continue
    j_rule = ((rho - US['rho']) / US['rho_se']) ** 2 + ((phi_y - US['phi_y']) / US['phi_y_se']) ** 2
    j_sd = ((sd - SD_US) / SD_SE) ** 2
    rows.append((j_rule + j_acf + j_sd + j_path + j_timing, tag, float(pi_w), float(gap_w), rho, phi_pi, phi_y, sd, j_rule, j_acf, j_sd, j_path, j_timing))

print(f"US rule rho {US['rho']:.3f} ({US['rho_se']:.3f}) pi {US['phi_pi']:.2f} ({US['phi_pi_se']:.2f}) y {US['phi_y']:.2f} ({US['phi_y_se']:.2f}); sd {SD_US} ({SD_SE})")
print(f"{'tag':>10s} {'pi_w':>5s} {'gap_w':>5s} | {'rho':>5s} {'phi_pi':>6s} {'phi_y':>5s} {'sd':>5s} | {'J_rule':>6s} {'J_acf':>6s} {'J_sd':>5s} {'J_path':>6s} {'J_tim':>6s} | {'J':>6s}")
for r in sorted(rows):
    print(f"{r[1]:>10s} {r[2]:5.2f} {r[3]:5.2f} | {r[4]:5.3f} {r[5]:6.2f} {r[6]:5.2f} {r[7]:5.2f} | {r[8]:6.2f} {r[9]:6.2f} {r[10]:5.2f} {r[11]:6.2f} {r[12]:6.2f} | {r[0]:6.2f}")
