@../.agents/AGENTS.md

## Claude Code here
- The sandbox has no Docker, so `make` fails. Use `bin/verify` or `php vendor/bin/phpunit <file>`. Controller
  tests need MySQL at the `aerie-database` host; skip them.
- The live dev database is readable: start `socat TCP-LISTEN:13306,bind=127.0.0.1,fork
  PROXY:localhost:127.0.0.1:3306,proxyport=3128,proxyauth=<user:pass from $HTTP_PROXY>` in the background, connect
  PDO to `127.0.0.1:13306/symfony_db` with the credentials in `.env.dev`'s DATABASE_URL, then kill the relay. Needs
  `sandbox.network.allowedDomains: ["127.0.0.1:3306"]` in `.claude/settings.local.json`. The `symfony` DB user can write;
  run SELECTs only.
- For macro state over time, ask the user to run `! make macro-dump [YEARS=20]`, then read `var/macro-gap-history.jsonl`.
- Multi-seed runs and sweeps go to `harness-runner`; model review and calibration to `financial-model-architect`.
- Hooks block writes under migrations/ and run PHPStan on changed files at Stop. Fix what they report.
- At most 8 PHP processes on the whole machine, across every session and worktree; one was killed at 16 and three
  parallel sessions once ran 25. Launch every harness and long PHP run as `bin/php-slot php ...`: it waits for one of
  8 shared slots. `bin/php-slot --status` shows how many are taken.