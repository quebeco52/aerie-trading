<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/BoardRun.php';

/**
 * The plain whole-board run: BoardRun with no topic hooks. Env and outputs: var/harness/lib/README.md; paired replays:
 * var/harness/run.sh.
 */
final class FullMarketHarnessTest extends BoardRun {}
