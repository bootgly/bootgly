<?php

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\commands\ProjectCommand;
use Bootgly\commands\ProjectsCommand;
use Bootgly\commands\ScheduleCommand;

$root = getenv('BOOTGLY_SCHEDULE_ROOT');
$base = getenv('BOOTGLY_SCHEDULE_BASE');
if ($root === false || $base === false) {
   exit(2);
}

// ! Point the working/consumer side at the scratch base BEFORE autoboot
define('BOOTGLY_WORKING_BASE', $base);
define('BOOTGLY_WORKING_DIR', "$base/");
define('BOOTGLY_STORAGE_BASE', "$base/storage");

// This is an embedded fixture, not a Bootgly CLI command/script — the CLI skips
// command registration here, so register the delegate the way the entry does.
$_SERVER['SCRIPT_FILENAME'] = '';
require rtrim($root, '/') . '/autoboot.php';

CLI->Commands->register(Command: new ScheduleCommand, Script: CLI);

$Command = new ProjectCommand;
$Projects = new ProjectsCommand;

// @ A from-scratch create scaffolds a commented schedule.php in every project
if (getenv('BOOTGLY_SCHEDULE_CREATE') === '1') {
   $Projects->create(['Fresh'], [
      'from' => 'scratch', 'interfaces' => 'CLI', 'yes' => true, 'no-git' => true,
   ]);
   echo 'scaffold:' . (is_file("$base/projects/Fresh/schedule.php") ? 'yes' : 'no') . ';';
   echo 'token:' . (str_contains((string) file_get_contents("$base/projects/Fresh/schedule.php"), 'project Fresh schedule run') ? 'filled' : 'raw') . ';';

   // @ The scaffolded file loads with zero jobs — list hints instead of silence
   $Command->schedule(['Fresh', 'list'], []);
   exit(0);
}

// @ List the scratch project's jobs exactly as `bootgly project Sched schedule list` would
$Command->schedule(['Sched', 'list'], []);

// @ The mount left the project environment in place — no entry closure ran
echo 'mounted:' . (defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'no') . ';';

// @ `list` mounts but never claims an instance — no record stamp (BG-24)
echo 'stamp:' . (isset(Record::$qualifier) ? Record::$qualifier : 'undefined') . ';';

// @ The `run` branch enrolls the worker (PID-qualified) — and stamps that PID
(new ReflectionMethod(ProjectCommand::class, 'enroll'))->invoke($Command, 'Sched');
$stamp = isset(Record::$qualifier) ? Record::$qualifier : null;
echo 'enrolled:' . ($stamp === (string) posix_getpid() ? 'pid' : 'no') . ';';
