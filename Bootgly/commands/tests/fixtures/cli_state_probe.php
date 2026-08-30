<?php

use Bootgly\commands\ProjectCommand;

$root = getenv('BOOTGLY_CLI_STATE_ROOT');
$base = getenv('BOOTGLY_CLI_STATE_BASE');
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

// @ Start the scratch console project exactly as `bootgly project Scratch start` would
$Command = new ProjectCommand;
$Command->start(['Scratch'], []);
