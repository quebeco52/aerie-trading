# Net debt vs net financial worth (step 5 of the public-accounts review)

Question: what the fiscal reaction (Bohn surplus, consolidation cut, purchases target), the sovereign spread and the
reimbursement update do when they read net financial worth (gross debt less the whole fund and the rescue holdings)
instead of GFSM net debt (gross less the fund's bonds). The term premium's duration supply stays on GFSM net debt.

- Arms: `net` = main working tree (HEAD, src/ clean); `worth` = HEAD worktree with the readers patched
  (`bash var/harness/net_worth/make_worth_tree.sh <scratch>/worth`; prints the worthDebt count, 6, and the diff line count).
  `run.sh` hard-codes the scratch path (`WORTH_TREE=` overrides).
- Run: `bin/harness-batch var/harness/net_worth/run.sh 1-16 net worth` (FullMarketHarnessTest live, POLITICS=1,
  FISCAL=1, 60y, 360 tpy). Analysis: `python3 var/harness/net_worth/paired.py var/harness/net_worth/runs`.
- Targets: mean gross debt/GDP, mean spread (bp), mean net-tax rate.
- Remove the worktree afterwards (`git worktree remove --force <scratch>/worth`).
