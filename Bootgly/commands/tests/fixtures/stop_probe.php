<?php

use const Bootgly\CLI;
use Bootgly\CLI\Terminal\Output;
use Bootgly\commands\ProjectCommand;

$root = getenv('BOOTGLY_STOP_PROBE_ROOT');
$base = getenv('BOOTGLY_STOP_PROBE_BASE');
$verb = getenv('BOOTGLY_STOP_PROBE_VERB') ?: 'stop';
$qualifier = getenv('BOOTGLY_STOP_PROBE_QUALIFIER') ?: '';
if ($root === false || $base === false) {
   exit(2);
}

// ! Point the working/consumer side at the scratch base BEFORE autoboot
define('BOOTGLY_WORKING_BASE', $base);
define('BOOTGLY_WORKING_DIR', "$base/");
define('BOOTGLY_STORAGE_BASE', "$base/storage");

// This is an embedded fixture, not a Bootgly CLI command/script.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

// @ Run the verb exactly as `bootgly project Scratch <verb> [<qualifier>]` would,
//   capturing everything it renders so the caller can assert on the text
$Host = new Output('php://memory');
$Terminal = CLI->Terminal;
$Terminal->Output = $Host;

$arguments = $qualifier !== '' ? [$verb, 'Scratch', $qualifier] : [$verb, 'Scratch'];
$result = (new ProjectCommand)->run($arguments, []);

rewind($Host->stream);
$rendered = (string) stream_get_contents($Host->stream);

// : Verdict on the first line, the rendered text (ANSI stripped) after it
echo 'result:' . ($result ? 'true' : 'false') . "\n";
echo preg_replace('/\e\[[0-9;]*m/', '', $rendered);
