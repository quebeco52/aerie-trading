#!/usr/bin/env php
<?php

// PreToolUse for the harness-runner agent only: it measures, it does not change the game. Write and Edit are allowed
// under var/harness/ and the session scratchpad; anything else is refused with the reason, so the agent reports the
// change it would make instead. Bash writes are not covered; the agent's instructions forbid them.

declare(strict_types=1);

$input = json_decode((string) stream_get_contents(STDIN), true);
if (!is_array($input)) {
    exit(0);
}
$path = (string) (($input['tool_input'] ?? [])['file_path'] ?? '');
if ($path === '') {
    exit(0);
}

$project = rtrim((string) (getenv('CLAUDE_PROJECT_DIR') ?: ($input['cwd'] ?? '')), '/');
$allowed = array_filter([
    $project !== '' ? $project . '/var/harness/' : '',
    rtrim((string) ($input['scratchpad_dir'] ?? ''), '/') . '/',
    rtrim((string) getenv('TMPDIR'), '/') . '/',
], static fn (string $prefix): bool => $prefix !== '/');

foreach ($allowed as $prefix) {
    if (str_starts_with($path, $prefix)) {
        exit(0);
    }
}

fwrite(STDERR, "The harness runner writes only under var/harness/ and the scratchpad. Leave {$path} as it is and put the change you would make in your report.\n");
exit(2);
