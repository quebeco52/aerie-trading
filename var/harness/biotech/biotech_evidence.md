# biotech evidence

## 2026-10-06 IBIS descriptive profile, main at e5d4e37

Question: how IBIS (the only BiotechBusinessModel firm) behaves over 30 sim-years on current main: event rates,
franchise path, utilization, protected-share reset at erosion-window close, margins vs ceiling, revenue growth vs
nominal GDP, revenue surprise and excess return by event type, reimbursement shift, reinvestment ratio.

Harness: `BiotechHarnessTest.php` (whole-ticker replay, live macro with board cap fed back), see RUN.md.
Seeds 1-16, 30 years, 360 ticks/year, 120 reports per seed, IBIS never failed. Table: `ibis_profile.out`.

Key numbers (mean (se across seeds), n=16):
- events/yr: approval 0.165 (0.015), setback 0.148 (0.016), cliff onset 0.098 (0.002). An approval in a cliff-onset
  quarter carries the cliff label, so approvals are slightly undercounted.
- franchise y5 1.094 (0.027), y10 1.056 (0.065), y20 1.022 (0.060), y30 0.970 (0.062); max seen 1.49.
- u (seasonal factor is 1 for IBIS) mean 0.979, >1 in 46% of quarters, overtime 0.6(u-1)^1.5 = 0.020 there; clamp 0.05%;
  below the 0.95 NRV trigger in 40%.
- protected share: closing quarter 0.6348 in all 36 closings (0.88 - 0.35 x eroded(12)), next quarter 0.8800 in all 36:
  snaps back to the ModelParam value.
- EBIT/rev 0.355 / 0.348 / 0.352 in y1-5 / 11-15 / 26-30; stock operatingMargin 0.360-0.374, always under the ceiling
  (0.464 falling to 0.443), gap -0.078.
- revenue CAGR 7.03% vs nominal GDP 4.37%: +2.65 pp/yr (se 0.21).
- revenue surprise vs model expectation: approval +3.1%, setback -8.4%, cliff +0.4%, none +0.4%; vs consensus +3.1,
  -2.7, +1.7, +1.8%; revenue beats consensus in 65% of quarters.
- excess log return vs board ex-IBIS, 90 ticks from report: approval +4.1% (2.2), setback -8.6% (2.8), cliff +2.3%
  (2.3), none -1.0% (0.5). Report tick alone: +0.2, -1.4, -0.4, +0.1%.
- reimbursement shift mean +0.0015, p10/p90 -0.013/+0.016; corr with no-event surprise 0.18 (pooled 1723).
- reinvestment ratio mean 1.38, p10/p90 0.98/2.01, <1 in 17%; cumulative op-margin change from the decay method +3.1 pp
  (se 0.2) over 30 years.

Caveats: live macro per seed (no replay); excess returns are price-only (no dividends), board cap-weighted ex-IBIS;
event-window returns over 90 ticks overlap other news; surprise "expected" is ctx->expectedRevenue, consensus is
ctx->analystExpectedRevenue.
