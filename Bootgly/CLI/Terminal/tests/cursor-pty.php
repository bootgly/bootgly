<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/*
 * Reads `Cursor->position` with STDIN/STDOUT on a pty and reports the result, the
 * elapsed time and the TTY-ness of both streams as one JSON line into argv[2]. It runs
 * in its own PHP process because the property under test talks to the process's own
 * controlling terminal — the spec is the pty master driving it.
 *
 * Nothing here is a test; the assertions live in `2.2-cursor-position-pty.Test.php`.
 */

// ! Its own process, so it boots the framework itself
require __DIR__ . '/../../../../autoboot.php';

use Bootgly\CLI\Terminal\Output;


$report = (string) ($_SERVER['argv'][2] ?? '/dev/null');

$Output = new Output();
$since = microtime(true);
$position = $Output->Cursor->position;
$elapsed = round(microtime(true) - $since, 3);

file_put_contents($report, json_encode([
   'position' => $position,
   'elapsed' => $elapsed,
   'stdin_tty' => stream_isatty(STDIN),
   'stdout_tty' => stream_isatty(STDOUT),
]) . "\n");
