# Catastrophe cover measurement

Question: what the insurers' catastrophe excess-of-loss cover pays on the engine's loss process, so its premium
(InsuranceBusinessModel::CATASTROPHE_COVER_EXPECTED_RECOVERY x (1 + loading)) and limit are measured, not assumed.

- `php var/harness/cat/cover_mc.php [seeds=8] [years=3000]`: macro catastrophe process alone (no board), quarterly
  incurred flow against its seasonal expectation, plus the large-loss layer on an i.i.d. claim draw. ~2 s.
- 2026-10-20, 8 x 3000 years (96k quarters): one-in-200-year gross excess 3.23 (limit 2.9 after the 0.375
  retention), expected recovery 0.0225/q (se 0.0006), attaches 0.051/q (one year in five).
- Rerun after any change to the catastrophe process, DISTRICT_CATASTROPHE_LOAD, the retention or the layer.
