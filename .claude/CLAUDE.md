@../.agents/AGENTS.md

## Claude Code here
- The sandbox has no Docker, database or network to the running app, so `make` fails. Use `bin/verify` or
  `php vendor/bin/phpunit <file>`. Controller tests need MySQL; skip them.
- For live state, ask the user to run `! make macro-dump [YEARS=20]`, then read `var/macro-gap-history.jsonl`.
- Multi-seed runs and sweeps go to `harness-runner`; model review and calibration to `financial-model-architect`.
- Hooks block writes under migrations/ and run PHPStan on changed files at Stop. Fix what they report.
- At most 8 PHP processes at once; the session has been killed at 16.