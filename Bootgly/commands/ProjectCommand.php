<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const ARRAY_FILTER_USE_KEY;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_WORKING_DIR;
use const INI_SCANNER_RAW;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_BINARY;
use const PHP_EOL;
use const PHP_OS_FAMILY;
use const SIGCONT;
use const SIGKILL;
use const SIGSTOP;
use const SIGTERM;
use const SIGUSR2;
use function array_filter;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function basename;
use function chmod;
use function count;
use function escapeshellarg;
use function file_get_contents;
use function file_put_contents;
use function function_exists;
use function getenv;
use function glob;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_link;
use function is_numeric;
use function is_string;
use function json_encode;
use function lstat;
use function microtime;
use function mkdir;
use function parse_ini_file;
use function posix_get_last_error;
use function posix_geteuid;
use function posix_getpid;
use function posix_getuid;
use function posix_kill;
use function posix_strerror;
use function putenv;
use function realpath;
use function register_shutdown_function;
use function rtrim;
use function shell_exec;
use function str_contains;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function time;
use function trim;
use function usleep;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Process\Inits;
use Bootgly\ACI\Process\Service;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\States;
use Bootgly\ACI\Process\User;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Migrations;
use Bootgly\ADI\Databases\SQL\Schema\Runner as MigrationRunner;
use Bootgly\ADI\Databases\SQL\Seed\Runner as SeedRunner;
use Bootgly\ADI\Databases\SQL\Seed\Seeders;
use Bootgly\API\Environment\Build;
use Bootgly\API\Environment\Configs\DatabaseConfig;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Configs;
use Bootgly\API\Projects\Project;
use Bootgly\CLI\Command;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Alert;


/**
 * CLI command for managing Bootgly projects.
 *
 * Provides subcommands to list, set, start, and inspect projects
 * registered in the projects/ directory (consumer or framework).
 */
class ProjectCommand extends Command
{
   // * Config
   public bool $separate = false;
   public int $group = 2;

   // * Data
   // # Command
   public string $name = 'project';
   public string $description = 'Manage one project of this kit by name';
   /** @phpstan-ignore property.phpDocType */
   /** @var array<string,array<string,array<string,string>|string>> */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'boot' => [
         'description' => 'Boot a project — initialize its own resources (projects create runs this as a hook)',
         'arguments'   => [
            '<name>' => 'Project path to boot (e.g. App or Blog)'
         ]
      ],
      'start' => [
         'description' => 'Start a project by name',
         'arguments'   => [
            '<name>' => 'Project name to start'
         ]
      ],
      'stop' => [
         'description' => 'Stop a running project (all instances, or one by port)',
         'arguments'   => [
            '<name>' => 'Project name to stop',
            '[port]' => 'Stop only one instance — bound port (servers) or master PID (console/TUI)'
         ]
      ],
      'show' => [
         'description' => 'Show status of a running project (all instances)',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
      'reload' => [
         'description' => 'Hot-reload a running project (all instances, or one by port)',
         'arguments'   => [
            '<name>' => 'Project name to reload',
            '[port]' => 'Reload only the instance bound to this port'
         ]
      ],
      'restart' => [
         'description' => 'Restart a running project by name',
         'arguments'   => [
            '<name>' => 'Project name to restart',
            '[port]' => 'Restart the instance bound to this port'
         ]
      ],
      'info' => [
         'description' => 'Show detailed info about a project',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
      'migrate' => [
         'description' => 'Run project database migrations',
         'arguments'   => [
            '<name>'   => 'Project name',
            '<action>' => 'status, up, down, sync, or create',
            '[value]'  => 'Migration name for create or step count for down'
         ]
      ],
      'seed' => [
         'description' => 'Run project database seeders',
         'arguments'   => [
            '<name>'   => 'Project name',
            '<action>' => 'list, run, or create',
            '[value]'  => 'Seeder name for create or run'
         ]
      ],
      'logs' => [
         'description' => 'View and follow a project\'s logs (backlog + live tap)',
         'arguments'   => [
            '<name>' => 'Project name (never a port — use --instance to pick one)'
         ]
      ],
      'schedule' => [
         'description' => 'Run a project\'s cron-style scheduled jobs',
         'arguments'   => [
            '<name>'   => 'Project name',
            '<action>' => 'run (minute-aligned worker loop) or list'
         ]
      ],
      'startup' => [
         'description' => 'Install the OS service that boots the project at startup (systemd)',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
      'unstartup' => [
         'description' => 'Remove the OS service installed by startup',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
      'status' => [
         'description' => 'Show the OS service of the project — installed, enabled, active',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
   ];
   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      'Preview seed run without executing SQL' => ['--dry-run'],
      'Keep following new records — logs (unrelated to `start -f`)' => ['-f', '--follow'],
      'Log filters and output shape (logs)' => ['--instance=<id>', '--channel=<channel>', '--level=<level>', '--since=<time>', '--json'],
      'Account the OS service runs as — the current one by default (startup)' => ['--user=<name>'],
      'Enable and start the OS service right away (startup)' => ['--now'],
   ];


   /**
    * Dispatch the appropriate subcommand based on arguments.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function run (array $arguments = [], array $options = []): bool
   {
      // @ Normalize argument order
      // Supports both: `project <subcommand> <name>` and `project <name> <subcommand>`
      $subcommands = array_keys($this->arguments);
      if (
         isSet($arguments[0], $arguments[1])
         && in_array($arguments[0], $subcommands) === false
      ) {
         if (in_array($arguments[1], $subcommands)) {
            // Swap: project <name> <subcommand> → project <subcommand> <name>
            [$arguments[0], $arguments[1]] = [$arguments[1], $arguments[0]];
         }
         else {
            // project <name> <invalid> → report the invalid subcommand, not the project name
            [$arguments[0], $arguments[1]] = [$arguments[1], $arguments[0]];
         }
      }

      return match ($arguments[0] ?? null) {
         'boot'    => $this->boot(
            array_slice($arguments, 1),
            $options
         ),
         'start'   => $this->start(
            array_slice($arguments, 1),
            $options
         ),
         'stop'    => $this->stop(array_slice($arguments, 1)),
         'show'    => $this->show(array_slice($arguments, 1)),
         'reload'  => $this->reload(array_slice($arguments, 1)),
         'restart' => $this->restart(
            array_slice($arguments, 1),
            $options
         ),
         'info'    => $this->info(array_slice($arguments, 1)),
         'migrate' => $this->migrate(
            array_slice($arguments, 1),
            $options
         ),
         'seed'    => $this->seed(
            array_slice($arguments, 1),
            $options
         ),
         'logs'    => $this->logs(
            array_slice($arguments, 1),
            $options
         ),
         'schedule' => $this->schedule(
            array_slice($arguments, 1),
            $options
         ),

         'startup' => $this->install(
            array_slice($arguments, 1),
            $options
         ),
         'unstartup' => $this->uninstall(
            array_slice($arguments, 1),
            $options
         ),
         'status'  => $this->inspect(
            array_slice($arguments, 1),
            $options
         ),
         default   => $this->help($arguments)
      };
   }

   // # Subcommands
   /**
    * Boot a project — initialize the resources a project of the user's own
    * carries. Today that is version control (a git repository of its own,
    * with the current tree as the initial commit); more responsibilities
    * will land here as the project lifecycle grows.
    *
    * `projects create` runs the same hook for every project the user creates from
    * scratch. The shipped platform examples arrive UNBOOTED on purpose — they
    * are guides, not the user's work — and this subcommand is how the user
    * adopts one.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function boot (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Refuse before anything is written, as create() does.
      if ($this->admit([], $options) === false) {
         return false;
      }

      // ! Target
      $path = $arguments[0] ?? '';
      if ($path === '' || Projects::check($path) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Usage: @#cyan:bootgly project <Name> boot@;';
         $Alert->render();

         return false;
      }
      // ? Only ever a project of this kit
      if (is_dir(Projects::CONSUMER_DIR . $path) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:projects/{$path}@; was not found.";
         $Alert->render();

         return false;
      }
      // ? A directory is not a project — booting one would version a folder
      //   nothing can start, and the mistake is invisible (projects/ is ignored)
      if ((glob(Projects::CONSUMER_DIR . "{$path}/*.Project.php") ?: []) === []) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Directory @#cyan:projects/{$path}@; carries no project signature "
            . '(@#cyan:<Name>.Project.php@;).';
         $Alert->render();

         return false;
      }

      // @
      $this->track(Projects::CONSUMER_DIR, $path, $options);

      // :
      return true;
   }

   /**
    * Start a project by loading and booting its project file.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function start (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // @ Determine project name
      $projectName = $arguments[0] ?? null;

      // ? Require project name
      if ($projectName === null || $projectName === '') {
         return $this->help(['start']);
      }

      // @ Resolve project directory
      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return false;
      }

      // ? No preventive by-name guard here: the port is only known after the
      //   project boot closure runs — the server takes a non-blocking lock on
      //   the port-qualified state files and aborts on a same-port duplicate.

      // @ Slice out the project name from arguments for boot
      $bootArguments = $projectName === $arguments[0] // @phpstan-ignore identical.alwaysTrue
         ? array_slice($arguments, 1)
         : $arguments;

      // @ Load and boot the project file
      $projectFile = $projectDir . basename($projectName) . '.Project.php';
      if (is_file($projectFile) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No project file found for @#cyan:{$projectName}@;.";
         $Alert->render();

         return false;
      }

      // ! Per-project Composer autoload — before the signature, so its
      //   top-level third-party references resolve
      Projects::load($projectDir);

      $Project = require $projectFile;
      if ($Project instanceof Project === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid project file for @#cyan:{$projectName}@;.";
         $Alert->render();

         return false;
      }

      // @ Console-interface projects get a registry identity (instance = master PID)
      //   so show/stop/logs can address them. WPI projects register their own
      //   port-qualified State inside the server; TUI apps adopt this entry (Input).
      $interfaces = Projects::read()[$projectName]['interfaces'] ?? [];
      if ($interfaces === ['CLI']) {
         $this->enroll($projectName);
      }

      $Project->boot($bootArguments, $options);

      return true;
   }

   /**
    * Register this console process in the instance registry (PID-qualified),
    * tombstoning it on exit — the identity `show`/`stop`/`logs` address — and
    * stamp that PID as the instance of every record this process writes.
    */
   private function enroll (string $projectName): void
   {
      try {
         $ownerPID = posix_getpid();
         $State = new State(Projects::encode($projectName), (string) $ownerPID);
         if ($State->lock(LOCK_EX | LOCK_NB) === true) {
            $State->save([
               'master'  => $ownerPID,
               'workers' => [$ownerPID],
               'type'    => 'CLI',
               'started' => time(),
               'project' => $projectName
            ]);
            // @ Every record from here on carries this instance's registry qualifier
            Record::$qualifier = (string) $ownerPID;
            register_shutdown_function(static function () use ($State, $ownerPID): void {
               // ? Only the registering process cleans — forks inherit this hook
               if (posix_getpid() === $ownerPID) {
                  $State->clean();
               }
            });
         }
      }
      catch (Throwable) {
         // ? Registry identity is best-effort — an unsafe storage dir never blocks a boot
      }
   }

   /**
    * Stop a running project.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function stop (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['stop']);
      }

      // ? Validate project exists
      if ($this->resolve($projectName) === null) {
         return false;
      }

      // @ Collect all instances to stop
      $instances = $this->scan($projectName);

      // ? Filter by port when given (instance qualifier = port)
      $port = $arguments[1] ?? null;
      if ($port !== null && $port !== '') {
         $instances = array_filter(
            $instances,
            fn (string $instance): bool => $instance === $port,
            ARRAY_FILTER_USE_KEY
         );
      }

      if (count($instances) === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $port !== null && $port !== ''
            ? "Project @#cyan:{$projectName}@; is not running on port @#cyan:{$port}@;.@.;"
            : "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();
         $this->hint($projectName, 'stop');
         return false;
      }

      $stopped = 0;
      foreach ($instances as $instance => $PIDs) {
         $masterPID = $PIDs['master'];
         if ($this->authenticate($projectName, $instance, $masterPID) === false) {
            continue;
         }

         // @ Send SIGTERM to master
         if (posix_kill($masterPID, SIGTERM) === false) {
            $error = posix_get_last_error();
            // ? EPERM: the daemon lineage belongs to another user (root boot)
            $hint = $error === 1
               ? ' The daemon runs as another user (started as root?) — retry with sudo.'
               : '';
            $reason = posix_strerror($error);
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Verified project instance @#cyan:{$projectName}@; could not be signaled (PID {$masterPID}: {$reason}).{$hint}@.;";
            $Alert->render();
            return false;
         }

         // @ Wait for graceful shutdown
         $elapsed = 0.0;
         while (
            $elapsed < 5.0
            && $this->authenticate($projectName, $instance, $masterPID)
         ) {
            usleep(100000); // 100ms
            $elapsed += 0.1;
         }

         // ! Freeze a non-responsive authenticated master before terminating
         //   its authenticated children. It cannot refork a worker between the
         //   worker signal and the final master kill.
         if ($this->authenticate($projectName, $instance, $masterPID)) {
            if (posix_kill($masterPID, SIGSTOP) === false) {
               continue;
            }

            $current = $this->locate($projectName, $instance !== '' ? $instance : null);
            $workers = $current !== null && $current['master'] === $masterPID
               ? $current['workers']
               : $PIDs['workers'];

            foreach ($workers as $workerPID) {
               if ($this->authenticate($projectName, $instance, $workerPID, $masterPID)) {
                  posix_kill($workerPID, SIGTERM);
               }
            }
            usleep(100000);
            foreach ($workers as $workerPID) {
               if ($this->authenticate($projectName, $instance, $workerPID, $masterPID)) {
                  posix_kill($workerPID, SIGKILL);
               }
            }

            // @ Force-kill only the same kernel-bound master identity. A reused
            //   numeric PID does not hold the qualified lock and is never hit.
            if (
               $this->authenticate($projectName, $instance, $masterPID)
               && posix_kill($masterPID, SIGKILL) === false
            ) {
               // ? Do not strand a verified service in SIGSTOP if SIGKILL was
               //   denied or failed for an external reason.
               posix_kill($masterPID, SIGCONT);
               continue;
            }
         }

         // @ The ACME helper never joins the worker pool — a SIGKILLed master
         //   orphans it still holding the HTTP-01 port. Signal it only when
         //   its process title proves it is an ACME helper.
         $lease = $PIDs['AutoTLS'] ?? null;
         $helper = is_array($lease) && is_int($lease['helper'] ?? null) ? $lease['helper'] : 0;
         if ($helper > 1) {
            $cmdline = @file_get_contents("/proc/{$helper}/cmdline");
            if (is_string($cmdline) && str_contains($cmdline, ': ACME helper')) {
               posix_kill($helper, SIGTERM);
            }
            else {
               $helper = 0;
            }
         }

         // ! Success must mean TERMINATED: verify the whole lineage (master,
         //   workers and helper) actually exited instead of trusting signal
         //   dispatch — survivors keep ports bound and break the next start.
         $survivors = array_merge([$masterPID], $PIDs['workers'], $helper > 1 ? [$helper] : []);
         $deadline = microtime(true) + 3.0;
         // @@
         while (microtime(true) < $deadline) {
            $survivors = array_values(array_filter(
               $survivors,
               static function (int $PID): bool {
                  if (posix_kill($PID, 0)) {
                     return true;
                  }
                  // ? EPERM still proves liveness (foreign-owned process)
                  return posix_get_last_error() === 1;
               }
            ));
            if ($survivors === []) {
               break;
            }
            usleep(100000);
         }
         if ($survivors !== []) {
            $list = implode(', ', $survivors);
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Project @#cyan:{$projectName}@; did not fully terminate — surviving PID(s): @#cyan:{$list}@;.@.;";
            $Alert->render();
            return false;
         }

         // @ Tombstone PID/command state. The lock inode is preserved so a
         //   concurrent restart cannot split flock exclusivity across inodes.
         //   Success requires the complete process lineage to release it.
         $cleaned = false;
         $elapsed = 0.0;
         while ($elapsed < 2.0) {
            if ($this->scrub($projectName, $instance, $masterPID)) {
               $cleaned = true;
               break;
            }
            usleep(100000);
            $elapsed += 0.1;
         }
         if ($cleaned === false) {
            continue;
         }

         $stopped++;
      }

      if ($stopped === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();
         $this->hint($projectName, 'stop');
         return false;
      }

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Project @#cyan:{$projectName}@; stopped.@.;";
      $Alert->render();

      return true;
   }

   /**
    * Show status of a running project.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function show (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['show']);
      }

      // ? Validate project exists
      if ($this->resolve($projectName) === null) {
         return false;
      }

      $instances = $this->scan($projectName);
      if (count($instances) === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No running instance found for project @#cyan:{$projectName}@;.@.;";
         $Alert->render();
         $this->hint($projectName, 'show');

         return false;
      }

      foreach ($instances as $instance => $PIDs) {
         // @ Check master
         $masterAlive = $this->authenticate(
            $projectName,
            $instance,
            $PIDs['master'],
         );
         // ? The state document's `status` is discovery data — trust it only
         //   over an authenticated (live) master. A paused server is alive and
         //   holds its lock, so without this it would print as plain running.
         $status = match (true) {
            $masterAlive === false => '@#red:stopped@;',
            ($PIDs['status'] ?? '') === 'Paused' => '@#yellow:paused@;',
            default => '@#green:running@;'
         };

         // @ locate() already retained only workers authenticated as direct
         //   children holding this exact qualified instance lock.
         $workers = $PIDs['workers'];
         $aliveWorkers = count($workers);
         $totalWorkers = count($workers);

         // @ Calculate uptime
         $uptime = '';
         if ($masterAlive) {
            $seconds = time() - $PIDs['started'];
            $hours = intdiv($seconds, 3600);
            $minutes = intdiv($seconds % 3600, 60);
            $secs = $seconds % 60;
            $uptime = "{$hours}h {$minutes}m {$secs}s";
         }

         // @ Build Fieldset content
         $displayName = $instance !== '' ? $projectName . '.' . $instance : $projectName;

         $content = '';
         $content .= '@#Green:' . str_pad('Project', 14) . ' @; ' . $displayName . PHP_EOL;
         $content .= '@#Green:' . str_pad('Type', 14) . ' @; ' . $PIDs['type'] . PHP_EOL;
         $content .= '@#Green:' . str_pad('Status', 14) . ' @; ' . $status . PHP_EOL;
         $content .= '@#Green:' . str_pad('Master PID', 14) . ' @; ' . $PIDs['master'] . PHP_EOL;

         if ($PIDs['type'] === 'WPI' || $PIDs['type'] === 'WPI-Client') {
            $content .= '@#Green:' . str_pad('Workers', 14) . ' @; ' . $aliveWorkers . '/' . $totalWorkers . PHP_EOL;
         }

         if (
            $PIDs['type'] === 'WPI'
            && is_string($PIDs['host'] ?? null)
            && is_int($PIDs['port'] ?? null)
         ) {
            $content .= '@#Green:' . str_pad('Address', 14) . ' @; ' . $PIDs['host'] . ':' . $PIDs['port'] . PHP_EOL;
         }

         if ($uptime !== '') {
            $content .= '@#Green:' . str_pad('Uptime', 14) . ' @; ' . $uptime;
         }

         $content = rtrim($content);

         $Output->write(PHP_EOL);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project Status @;';
         $Fieldset->content = $content;
         $Fieldset->render();
      }

      return true;
   }

   /**
    * Hot-reload a running project.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function reload (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['reload']);
      }

      // ? Validate project exists
      if ($this->resolve($projectName) === null) {
         return false;
      }

      // @ Collect running instances (optionally filtered by port)
      $instances = $this->scan($projectName);

      // ? Filter by port when given (instance qualifier = port)
      $port = $arguments[1] ?? null;
      if ($port !== null && $port !== '') {
         $instances = array_filter(
            $instances,
            fn (string $instance): bool => $instance === $port,
            ARRAY_FILTER_USE_KEY
         );
      }

      $reloaded = 0;
      foreach ($instances as $instance => $PIDs) {
         if (
            $this->authenticate($projectName, $instance, $PIDs['master']) === false
            || posix_kill($PIDs['master'], SIGUSR2) === false
         ) {
            continue;
         }

         $reloaded++;
      }

      if ($reloaded === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $port !== null && $port !== ''
            ? "Project @#cyan:{$projectName}@; is not running on port @#cyan:{$port}@;.@.;"
            : "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();

         return false;
      }

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Reload signal sent to @#cyan:{$reloaded}@; instance(s) of project @#cyan:{$projectName}@;.@.;";
      $Alert->render();

      return true;
   }

   /**
    * Restart a running project (stop then start).
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function restart (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['restart']);
      }

      // ? Validate project exists
      if ($this->resolve($projectName) === null) {
         return false;
      }

      // @ Collect live instances
      $port = $arguments[1] ?? null;
      if ($port === '') {
         $port = null;
      }
      $live = [];
      foreach ($this->scan($projectName) as $instance => $PIDs) {
         if ($this->authenticate($projectName, $instance, $PIDs['master'])) {
            $live[$instance] = $PIDs;
         }
      }

      // ? Ambiguous target: multiple instances and no port
      if ($port === null && count($live) > 1) {
         $ports = implode(', ', array_map(
            fn (array $PIDs): string => (string) ($PIDs['port'] ?? ''),
            $live
         ));
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; has multiple running instances (ports: {$ports}). Use `project restart {$projectName} <port>`.@.;";
         $Alert->render();

         return false;
      }

      // ! Resolve the target instance to stop and the port to re-bind
      $stopKey = null;
      if ($port !== null) {
         $stopKey = isSet($live[$port]) ? $port : null;
      }
      else if (count($live) === 1) {
         $stopKey = (string) array_key_first($live);
         $port = (string) ($live[$stopKey]['port'] ?? $stopKey);
      }

      // @ Stop the running target instance
      if ($stopKey !== null) {
         $Output->render('@.;@#yellow:Stopping project...@;@.;');
         $this->stop($stopKey === '' ? [$projectName] : [$projectName, $stopKey]);
      }

      // @ Preserve the instance port on the new start
      if ($port !== null) {
         putenv("PORT={$port}");
      }

      // @ Start
      return $this->start([$projectName], $options);
   }

   /**
    * Run project database migrations.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function migrate (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['migrate']);
      }

      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return false;
      }

      $action = $arguments[1] ?? 'status';
      $migrationsPath = $projectDir . 'database/migrations';

      if ($action === 'create') {
         $name = $arguments[2] ?? null;
         if ($name === null || $name === '') {
            return $this->help(['migrate']);
         }

         $Migrations = new Migrations($migrationsPath);
         $file = $Migrations->create($name);

         $Alert = new Alert($Output);
         $Alert->Type::Success->set();
         $Alert->message = "Migration created: @#cyan:{$file}@;@.;";
         $Alert->render();

         return true;
      }

      $Project = $this->open($projectName);
      if ($Project === null) {
         return false;
      }

      $Database = $this->configure($Project);
      if ($Database === null) {
         return false;
      }

      $lockFile = BOOTGLY_STORAGE_DIR . 'locks/migrations/' . Projects::encode($projectName) . '.lock';
      $Runner = new MigrationRunner($Database, $migrationsPath, $lockFile);

      try {
         if ($action === 'status') {
            $Status = $Runner->report();

            $content = '';
            $content .= '@#Green:' . str_pad('Applied', 12) . ' @; ' . count($Status['applied']) . PHP_EOL;
            $content .= '@#Green:' . str_pad('Local only', 12) . ' @; ' . count($Status['pending']) . PHP_EOL;
            $content .= '@#Green:' . str_pad('DB only', 12) . ' @; ' . count($Status['missing']);

            if ($Status['pending'] !== []) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Next', 12) . ' @; ' . $Status['pending'][0];
            }

            if ($Status['missing'] !== []) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Remove', 12) . ' @; ' . $Status['missing'][0];
            }

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Migration Status @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            return true;
         }

         if ($action === 'sync') {
            $Status = $Runner->report();

            $content = '';
            $content .= '@#Green:' . str_pad('Applied', 12) . ' @; ' . count($Status['applied']) . PHP_EOL;
            $content .= '@#Green:' . str_pad('Add', 12) . ' @; ' . count($Status['pending']) . PHP_EOL;
            $content .= '@#Green:' . str_pad('Delete', 12) . ' @; ' . count($Status['missing']);

            if ($Status['pending'] !== []) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Add first', 12) . ' @; ' . $Status['pending'][0];
            }

            if ($Status['missing'] !== []) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Delete first', 12) . ' @; ' . $Status['missing'][0];
            }

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Migration Sync Check @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            if ($Status['pending'] === [] && $Status['missing'] === []) {
               $Alert = new Alert($Output);
               $Alert->Type::Success->set();
               $Alert->message = 'Migration history is already synchronized.@.;';
               $Alert->render();

               return true;
            }

            if ($this->confirm("Apply migration sync to {$Runner->Repository->table}? [y/N]") === false) {
               $Alert = new Alert($Output);
               $Alert->Type::Attention->set();
               $Alert->message = 'Migration sync cancelled.@.;';
               $Alert->render();

               return true;
            }

            $Sync = $Runner->sync();

            $content = '';
            $content .= '@#Green:' . str_pad('Added', 12) . ' @; ' . count($Sync['added']) . PHP_EOL;
            $content .= '@#Green:' . str_pad('Deleted', 12) . ' @; ' . count($Sync['deleted']);

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Migration Sync Applied @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            return true;
         }

         if ($action === 'up') {
            $limit = isset($arguments[2]) && is_numeric($arguments[2]) ? (int) $arguments[2] : 0;
            $applied = $Runner->up($limit);

            $Alert = new Alert($Output);
            $Alert->Type::Success->set();
            $Alert->message = 'Migrations applied: @#cyan:' . count($applied) . '@;@.;';
            $Alert->render();

            return true;
         }

         if ($action === 'down') {
            if (isset($arguments[2]) === false || is_numeric($arguments[2]) === false) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'Migration down requires a numeric step count.@.;';
               $Alert->render();

               return false;
            }

            $reverted = $Runner->down((int) $arguments[2]);

            $Alert = new Alert($Output);
            $Alert->Type::Success->set();
            $Alert->message = 'Migrations reverted: @#cyan:' . count($reverted) . '@;@.;';
            $Alert->render();

            return true;
         }
      }
      catch (Throwable $Throwable) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $Throwable->getMessage() . '@.;';
         $Alert->render();

         return false;
      }

      $Alert = new Alert($Output);
      $Alert->Type::Failure->set();
      $Alert->message = "Invalid migration action: @#cyan:{$action}@;@.;";
      $Alert->render();

      return false;
   }

   /**
    * Run project database seeders.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function seed (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['seed']);
      }

      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return false;
      }

      $action = $arguments[1] ?? 'list';
      $seedersPath = "{$projectDir}database/seeders";
      $Seeders = new Seeders($seedersPath);

      try {
         if ($action === 'create') {
            $name = $arguments[2] ?? null;
            if ($name === null || $name === '') {
               return $this->help(['seed']);
            }

            $file = $Seeders->create($name);

            $Alert = new Alert($Output);
            $Alert->Type::Success->set();
            $Alert->message = "Seeder created: @#cyan:{$file}@;@.;";
            $Alert->render();

            return true;
         }

         if ($action === 'list') {
            $files = $Seeders->discover();

            $content = '';
            $content .= '@#Green:' . str_pad('Count', 12) . ' @; ' . count($files);

            foreach (array_keys($files) as $name) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Seeder', 12) . ' @; ' . $name;
            }

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Seeder List @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            return true;
         }

         if ($action === 'run') {
            $Project = $this->open($projectName);
            if ($Project === null) {
               return false;
            }

            $Database = $this->configure($Project);
            if ($Database === null) {
               return false;
            }

            $lockFile = BOOTGLY_STORAGE_DIR . 'locks/seeders/' . Projects::encode($projectName) . '.lock';
            $Runner = new SeedRunner($Database, $seedersPath, $lockFile);
            $name = $arguments[2] ?? null;

            if (isset($options['dry-run'])) {
               $Preview = $Runner->preview($name === '' ? null : $name);

               $content = '';
               $content .= '@#Green:' . str_pad('Seeders', 12) . ' @; ' . count($Preview);

               foreach ($Preview as $seeder => $queries) {
                  $content .= PHP_EOL . '@#Green:' . str_pad('Seeder', 12) . " @; {$seeder}";

                  if ($queries === []) {
                     $content .= PHP_EOL . '@#Green:' . str_pad('SQL', 12) . ' @; (none)';
                     continue;
                  }

                  foreach ($queries as $index => $query) {
                     $number = $index + 1;
                     $content .= PHP_EOL . '@#Green:' . str_pad("SQL {$number}", 12) . " @; {$query['sql']}";

                     if ($query['parameters'] !== []) {
                        $parameters = json_encode($query['parameters']) ?: '[]';
                        $content .= PHP_EOL . '@#Green:' . str_pad('Parameters', 12) . " @; {$parameters}";
                     }
                  }
               }

               $Output->write(PHP_EOL);
               $Fieldset = new Fieldset($Output);
               $Fieldset->title = '@#Cyan: Seeder Dry Run @;';
               $Fieldset->content = $content;
               $Fieldset->render();
               $Output->write(PHP_EOL);

               $Alert = new Alert($Output);
               $Alert->Type::Attention->set();
               $Alert->message = 'Dry run only; no seeder SQL was executed.@.;';
               $Alert->render();

               return true;
            }

            $ran = $Runner->run($name === '' ? null : $name);

            $Alert = new Alert($Output);
            $Alert->Type::Success->set();
            $Alert->message = 'Seeders run: @#cyan:' . count($ran) . '@;@.;';
            $Alert->render();

            return true;
         }
      }
      catch (Throwable $Throwable) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $Throwable->getMessage() . '@.;';
         $Alert->render();

         return false;
      }

      $Alert = new Alert($Output);
      $Alert->Type::Failure->set();
      $Alert->message = "Invalid seeder action: @#cyan:{$action}@;@.;";
      $Alert->render();

      return false;
   }

   /**
    * View and follow one project's logs — the project-scoped face of `bootgly logs`.
    *
    * Addressed by NAME, never by port: a console project has no port, and a server's
    * port (or a console master PID) is the `--instance` qualifier — a record filter on
    * both lanes and, with -f, the live-tap tiebreaker when several instances are live.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function logs (array $arguments, array $options): bool
   {
      // ? Refuse flags this subcommand does not implement
      if ($this->admit(['follow', 'f', 'instance', 'channel', 'level', 'since', 'json'], $options) === false) {
         return false;
      }

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['logs']);
      }

      // ? Only registered, resolvable projects
      if ($this->resolve($projectName) === null) {
         return false;
      }

      // : One implementation — the kit `logs` command, project-scoped
      $options['project'] = $projectName;
      return CLI->Commands->find('logs')?->run([], $options) ?? false;
   }

   /**
    * Run a project's cron-style scheduled jobs — the project-scoped face of
    * `bootgly schedule`.
    *
    * Mounts the project environment (BOOTGLY_PROJECT, configs, catalogs,
    * autoload) WITHOUT running its boot entry, so the existing ScheduleCommand
    * resolves `<project>/schedule.php` — a WPI project's server never starts.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function schedule (array $arguments, array $options): bool
   {
      // ? Refuse flags this subcommand does not implement
      if ($this->admit([], $options) === false) {
         return false;
      }

      // ? Require project name and action
      $projectName = $arguments[0] ?? null;
      $action = $arguments[1] ?? null;
      if (
         $projectName === null || $projectName === ''
         || ($action !== 'run' && $action !== 'list')
      ) {
         return $this->help(['schedule']);
      }

      // @ Mount the project environment (no boot entry — no server)
      $Project = $this->open($projectName);
      if ($Project === null) {
         return false;
      }
      $Project->mount();

      // @ The worker is a long-lived console process: give it the registry
      //   identity `show`/`stop`/`logs` address (PID-qualified, like `start`)
      if ($action === 'run') {
         $this->enroll($projectName);
      }

      // : One implementation — the kit `schedule` command, project-mounted
      return CLI->Commands->find('schedule')?->run([$action], $options) ?? false;
   }

   /**
    * `startup` — install the OS service that boots the project at startup,
    * the `pm2 startup` of one project. systemd only, for now: a machine booted
    * by anything else is named and refused, with the command a hand-written
    * service must run.
    *
    * The unit runs `project <Name> start -f` — foreground, so `Type=simple`
    * holds the process and the journal keeps its output — as `--user` (the
    * current account by default) from this kit, restarted on failure; a
    * project carrying a `schedule.php` gets a second unit for its worker.
    * Root installs under /etc/systemd/system and, with `--now`, enables and
    * starts right away. Anyone else gets the units staged under
    * storage/systemd/ and the exact commands that install them.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function install (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Refuse before anything is written
      if ($this->admit(['user', 'now'], $options) === false) {
         return false;
      }

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['startup']);
      }

      // ? Validate project exists
      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return false;
      }

      // ? `--now` is a switch, never a value
      if (isSet($options['now']) === true && $options['now'] !== true) {
         $Output->render('@#red:Invalid --now value.@; It takes no value: pass @#cyan:--now@; alone.@.;');

         return false;
      }

      // ! Service account — the current one unless `--user` names another
      $user = $options['user'] ?? null;
      if ($user !== null && (is_string($user) === false || $user === '')) {
         // ? A bare `--user` is refused, not ignored
         $Output->render('@#red:Invalid --user value.@; Pass the account: --user=<name>.@.;');

         return false;
      }
      $User = new User;
      if ($user === null) {
         // ! Under sudo the invoking account owns the service, not root — an
         //   environment variable only root's caller could have set honestly
         $invoker = posix_geteuid() === 0 ? (string) getenv('SUDO_USER') : '';
         $user = $invoker !== '' && $User->info($invoker) !== false ? $invoker : $User->current;
      }
      if ($user === '' || $User->info($user) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Unknown user @#cyan:{$user}@;.";
         $Alert->render();
         $Output->render('The service must run as an existing account: @#cyan:--user=<name>@;.@.;');

         return false;
      }
      // ? Only a machine booted by systemd is managed
      if ($this->verify($projectName) === false) {
         return false;
      }

      // ? The unit runs this very interpreter, by absolute path
      if (str_starts_with(PHP_BINARY, '/') === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'This PHP build reports no absolute binary path — nothing a unit could run.';
         $Alert->render();

         return false;
      }

      // ! Units — the server, and the schedule worker when the project has one
      $Services = $this->compose($projectName, $projectDir, $user);
      if ($Services === []) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Nothing to boot at startup: @#cyan:{$projectName}@; is a console project without a schedule.php.";
         $Alert->render();

         return false;
      }
      $units = implode(' ', array_map(static fn (Service $Service): string => $Service->unit, $Services));
      if ($user === 'root') {
         foreach ($Services as $Service) {
            $Output->render($Service->reload !== ''
               ? '@#yellow:Note:@; the server runs as @#cyan:root@; — it demotes itself to the @#cyan:user@;/@#cyan:group@; of its own configure().@.;'
               : '@#yellow:Warning:@; the schedule worker runs as @#cyan:root@; and never demotes — pass @#cyan:--user=<name>@;.@.;');
         }
      }

      // ? The server unit holds the process with `start -f`: a project file
      //   that never maps `-f` to the foreground mode detaches and exits 0,
      //   which systemd reads as "done", not "failed"
      $projectFile = $projectDir . basename($projectName) . '.Project.php';
      $interfaces = Projects::read()[$projectName]['interfaces'] ?? [];
      if (
         in_array('WPI', $interfaces, true) === true
         && str_contains((string) @file_get_contents($projectFile), 'Foreground') === false
      ) {
         $Output->render("@#yellow:Warning:@; @#cyan:projects/{$projectName}@; does not seem to map @#cyan:-f@; to @#cyan:Modes::Foreground@; — under systemd the server must stay in the foreground, as the shipped project files do.@.;");
      }

      // ? A unit of the same name stamped by another project or kit is a
      //   collision, never an upgrade — refused before anything is staged or written
      foreach ($Services as $Service) {
         if ($Service->owned === true) {
            continue;
         }
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "@#cyan:{$Service->unit}@; is not this project's to write.";
         $Alert->render();
         $Output->render($this->describe($Service));

         return false;
      }

      // ! What is already running — a rewritten unit reaches it only through a
      //   restart, and an instance started by hand holds the port and the state
      //   record the unit would claim (per unit: a server state against the
      //   server unit, a console state against the worker unit)
      $now = isSet($options['now']);
      $running = [];
      $conflicts = [];
      $instances = States::scan(Projects::encode($projectName));
      foreach ($Services as $Service) {
         $running[$Service->unit] = $Service->installed && $Service->inspect()['active'] === 'active';
         $kind = $Service->reload !== '' ? 'WPI' : 'CLI';
         foreach ($instances as $qualifier => $data) {
            if ($running[$Service->unit] === false && $data['type'] === $kind) {
               $conflicts[$Service->unit] = (string) $qualifier;
            }
         }
      }

      // ? Anyone but root gets the units staged and the commands that install them
      if (posix_geteuid() !== 0) {
         // ! The staging directory is this account's alone: root will copy out
         //   of it, so a link, a foreign owner or a mode others can write is
         //   refused, never followed — the storage directory itself included
         $staging = BOOTGLY_STORAGE_DIR . 'systemd/';
         $storage = rtrim(BOOTGLY_STORAGE_DIR, '/');
         if (is_link($storage) === false && is_dir($staging) === false && is_link(rtrim($staging, '/')) === false) {
            @mkdir($staging, 0700, true);
         }
         $stat = @lstat(rtrim($staging, '/'));
         if (
            is_link($storage) === true
            || is_link(rtrim($staging, '/')) === true
            || $stat === false
            || ($stat['mode'] & 0170000) !== 0040000
            || $stat['uid'] !== posix_geteuid()
            || ($stat['mode'] & 0077) !== 0
         ) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = 'Refusing to stage into @#cyan:storage/systemd@;.';
            $Alert->render();
            $Output->render('It must be a directory of this account that nobody else can enter, under a @#cyan:storage@; that is not a link — root installs out of it.@.;');

            return false;
         }

         $files = [];
         foreach ($Services as $Service) {
            $file = $staging . $Service->unit;
            if (is_link($file) === true || @file_put_contents($file, $Service->render()) === false) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = "Could not write @#cyan:{$file}@;.";
               $Alert->render();

               return false;
            }
            // ! The staged unit is the caller's alone until root installs it
            @chmod($file, 0600);
            $files[] = escapeshellarg($file);
            $Output->render("@#green:Generated@; @#cyan:storage/systemd/{$Service->unit}@;@.;");
         }

         $Output->render('@#yellow:Warning:@; installing under @#cyan:/etc/systemd/system@; needs root — the units were staged instead.@.;');
         foreach ($conflicts as $unit => $qualifier) {
            $Output->render("@#yellow:Warning:@; @#cyan:{$projectName}@; is already running by hand (instance @#cyan:{$qualifier}@;) — stop it with @#Blue:bootgly project {$projectName} stop@; before starting @#cyan:{$unit}@;.@.;");
         }
         $Output->render(
            '@#Green:Install:@; @#Blue:sudo install -m 0644 -o root -g root ' . implode(' ', $files) . ' ' . Service::$directory
            . ' && sudo systemctl daemon-reload && sudo systemctl enable ' . ($now === true ? '--now ' : '') . "{$units}@;@.;"
         );

         return false;
      }

      // ? With `--now` a hand-started instance would be fought for its port and
      //   its state record: refused up front
      if ($now === true && $conflicts !== []) {
         $unit = array_key_first($conflicts);
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "@#cyan:{$projectName}@; is already running (instance @#cyan:{$conflicts[$unit]}@;).";
         $Alert->render();
         $Output->render("Stop the hand-started instance first — @#Blue:bootgly project {$projectName} stop@; — then let systemd own it.@.;");

         return false;
      }

      // @ Install — every unit, then one reload, even when one of them failed
      $installed = true;
      foreach ($Services as $Service) {
         if ($Service->install() === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Could not write @#cyan:{$Service->file}@;.";
            $Alert->render();
            $installed = false;

            continue;
         }
         $Output->render("@#green:Installed@; @#cyan:{$Service->file}@;@.;");
      }
      if (Service::reload() === false) {
         $Output->render('@#yellow:Note:@; @#cyan:systemctl daemon-reload@; failed — run it yourself before enabling.@.;');
      }
      // ?
      if ($installed === false) {
         $Output->render('@#yellow:Note:@; the units that were written stay under @#cyan:' . Service::$directory . '@;, not enabled — fix the cause and run @#cyan:startup@; again, or @#cyan:unstartup@; to remove them.@.;');

         return false;
      }

      // @ Enable at boot — that is what `startup` promises; `--now` also starts
      foreach ($Services as $Service) {
         if ($Service->enable(now: $now) === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Could not enable @#cyan:{$Service->unit}@; — see @#cyan:systemctl status {$Service->unit}@;.";
            $Alert->render();

            return false;
         }
         // ? A service already running keeps the previous unit until restarted
         if ($running[$Service->unit] === true) {
            if ($Service->restart() === false) {
               $Output->render("@#yellow:Note:@; could not restart @#cyan:{$Service->unit}@; — the running service still follows the previous unit.@.;");
            }
            else {
               $Output->render("@#green:Restarted@; @#cyan:{$Service->unit}@;@.;");
            }
         }
         // ?
         if ($now === false && $running[$Service->unit] === false) {
            $Output->render("@#green:Enabled@; @#cyan:{$Service->unit}@;@.;");

            continue;
         }
         // ! `start` returns once the process is forked, not once it works:
         //   a port already taken exits the master a moment later
         usleep(1500000);
         $state = $Service->inspect();
         if ($state['active'] !== 'active') {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Started, but @#cyan:{$state['active']}@;: @#cyan:{$Service->unit}@;";
            $Alert->render();
            $Output->render("See @#cyan:journalctl -u {$Service->unit}@; — a port already taken by a hand-started instance is the usual cause.@.;");

            return false;
         }
         $Output->render("@#green:Enabled and started@; @#cyan:{$Service->unit}@;@.;");
      }
      $stopped = implode(' ', array_keys(array_filter($running, static fn (bool $active): bool => $active === false)));
      if ($now === false && $stopped !== '') {
         $Output->render("@#Green:Tip:@; start now with @#Blue:systemctl start {$stopped}@;.@.;");
      }

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Project @#cyan:{$projectName}@; boots at startup.";
      $Alert->render();

      // :
      return true;
   }

   /**
    * `unstartup` — remove the OS service `startup` installed, the
    * `pm2 unstartup` of one project: disabled and stopped first, the unit files deleted, systemd
    * reloaded. Root only; anyone else gets the commands.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function uninstall (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Refuse before anything is touched
      if ($this->admit([], $options) === false) {
         return false;
      }

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['unstartup']);
      }

      // ? A unit outlives its project: a path-safe name is enough to manage it
      $projectDir = $this->find($projectName);
      if ($projectDir === null) {
         return false;
      }

      // ? Only a machine booted by systemd is managed
      if ($this->verify($projectName) === false) {
         return false;
      }

      // ! What an earlier install left, whatever the project carries today
      $skipped = false;
      $Services = array_filter(
         $this->compose($projectName, $projectDir, '', every: true),
         function (Service $Service) use ($Output, &$skipped): bool {
            // ? A unit that is not this project's to remove is named and left alone
            if ($Service->installed === true && $Service->owned === false) {
               $Output->render("@#yellow:Note:@; @#cyan:{$Service->unit}@; left alone: " . $this->describe($Service));
               $skipped = true;

               return false;
            }

            return $Service->installed;
         }
      );
      if ($Services === []) {
         if ($skipped === false) {
            $Output->render("@#yellow:Note:@; no service is installed for @#cyan:{$projectName}@;.@.;");
         }

         // :
         return $skipped === false;
      }
      $units = implode(' ', array_map(static fn (Service $Service): string => $Service->unit, $Services));
      $files = implode(' ', array_map(static fn (Service $Service): string => escapeshellarg($Service->file), $Services));

      // ? Anyone but root gets the commands
      if (posix_geteuid() !== 0) {
         $Output->render('@#yellow:Warning:@; removing from @#cyan:/etc/systemd/system@; needs root.@.;');
         $Output->render(
            "@#Green:Remove:@; @#Blue:sudo systemctl disable --now {$units} && sudo rm {$files} && sudo systemctl daemon-reload@;@.;"
         );

         return false;
      }

      // @ Disable, stop and remove
      foreach ($Services as $Service) {
         if ($Service->disable(now: true) === false) {
            $Output->render("@#yellow:Note:@; could not disable @#cyan:{$Service->unit}@; — see @#cyan:systemctl status {$Service->unit}@;.@.;");
         }
         if ($Service->uninstall() === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Could not remove @#cyan:{$Service->file}@;.";
            $Alert->render();

            return false;
         }
         $Output->render("@#green:Removed@; @#cyan:{$Service->file}@;@.;");
      }
      if (Service::reload() === false) {
         $Output->render('@#yellow:Note:@; @#cyan:systemctl daemon-reload@; failed — run it yourself.@.;');
      }

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Project @#cyan:{$projectName}@; no longer boots at startup.";
      $Alert->render();

      // :
      return true;
   }

   /**
    * `status` — show the OS service of a project as systemd sees it: installed where,
    * enabled at boot, active now. The instances themselves are `show`'s.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function inspect (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ?
      if ($this->admit([], $options) === false) {
         return false;
      }

      // ? Require project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         return $this->help(['status']);
      }

      // ? A unit outlives its project: a path-safe name is enough to manage it
      $projectDir = $this->find($projectName);
      if ($projectDir === null) {
         return false;
      }

      // ? Only a machine booted by systemd is managed
      if ($this->verify($projectName) === false) {
         return false;
      }

      // @ One Fieldset per installed unit
      $installed = false;
      $skipped = false;
      foreach ($this->compose($projectName, $projectDir, '', every: true) as $Service) {
         if ($Service->installed === false) {
            continue;
         }
         // ? A unit that is not this project's to show is named and skipped
         if ($Service->owned === false) {
            $Output->render("@.;@#yellow:Note:@; @#cyan:{$Service->unit}@; is not this project's: " . $this->describe($Service));
            $skipped = true;

            continue;
         }
         $installed = true;

         $state = $Service->inspect();

         $content = '';
         $content .= '@#Green:' . str_pad('Unit', 14) . ' @; ' . $Service->unit . PHP_EOL;
         $content .= '@#Green:' . str_pad('File', 14) . ' @; ' . $Service->file . PHP_EOL;
         $content .= '@#Green:' . str_pad('Enabled', 14) . ' @; ' . $state['enabled'] . PHP_EOL;
         $content .= '@#Green:' . str_pad('Active', 14) . ' @; ' . $state['active'];

         $Output->write(PHP_EOL);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Service Status @;';
         $Fieldset->content = $content;
         $Fieldset->render();

         // ? A systemctl that cannot answer is not a unit with nothing to say
         if ($state['enabled'] === 'unknown' && $state['active'] === 'unknown') {
            $Output->render('@.;@#yellow:Note:@; @#cyan:systemctl@; could not be queried — ask as root, or check the system bus.@.;');
         }
      }

      // ?
      if ($installed === false) {
         if ($skipped === false) {
            $Output->render("@.;@#yellow:Note:@; no service is installed for @#cyan:{$projectName}@; — install one with @#Blue:bootgly project {$projectName} startup@;.@.;");
         }

         return true;
      }

      $Output->render("@.;@#Green:Tip:@; Use @#Blue:bootgly project {$projectName} show@; for the running instances.@.;");

      // :
      return true;
   }

   // @ Helpers
   /**
    * Confirm this machine is booted by systemd — the only init `startup`
    * manages. Anything else is named, with the command a hand-written service
    * must run, so no platform is refused in silence.
    */
   private function verify (string $projectName): bool
   {
      $Output = CLI->Terminal->Output;

      // ?:
      $Init = PHP_OS_FAMILY === 'Linux' ? Inits::detect() : Inits::None;
      if ($Init === Inits::Systemd) {
         return true;
      }

      // ! Name the platform
      $release = PHP_OS_FAMILY === 'Linux' ? @parse_ini_file('/etc/os-release', false, INI_SCANNER_RAW) : false;
      $distro = is_array($release) && is_string($release['PRETTY_NAME'] ?? null)
         ? trim($release['PRETTY_NAME'], '"')
         : PHP_OS_FAMILY;
      $comm = trim((string) @file_get_contents('/proc/1/comm'));
      $booted = match (true) {
         PHP_OS_FAMILY !== 'Linux' => 'is not Linux',
         $Init === Inits::None => 'has no init running' . ($comm !== '' ? " (PID 1 is @#cyan:{$comm}@;)" : ''),
         default => "is booted by @#cyan:{$Init->value}@;"
      };

      $Output->render("@#yellow:Warning:@; @#cyan:{$distro}@; {$booted}, which @#cyan:startup@; does not manage yet — only systemd is supported.@.;");
      $Output->render('Register the service with your init by hand; from @#cyan:' . rtrim(BOOTGLY_WORKING_DIR, '/') . '@; it must run:@.;');
      $Output->render(
         '  @#Blue:' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(BOOTGLY_WORKING_DIR . 'bootgly')
         . ' project ' . escapeshellarg($projectName) . ' start -f@;@.;'
      );

      return false;
   }

   /**
    * Locate a project for the verbs that manage a unit which may outlive
    * it: the registry when the project is still there, the path alone
    * when it is not — the unit name derives from the path and nothing is
    * executed. A path that fails the safety rules is refused either way.
    */
   private function find (string $projectName): null|string
   {
      // ?:
      $registered = Projects::validate($projectName);
      if (
         $registered === true
         && (is_dir(BOOTGLY_WORKING_DIR . "projects/{$projectName}/") || is_dir(BOOTGLY_ROOT_DIR . "projects/{$projectName}/"))
      ) {
         return $this->resolve($projectName);
      }
      if (Projects::check($projectName) === false) {
         $Alert = new Alert(CLI->Terminal->Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid project path: @#cyan:{$projectName}@;.";
         $Alert->render();

         return null;
      }

      CLI->Terminal->Output->render($registered === true
         ? "@#yellow:Note:@; @#cyan:{$projectName}@; is registered but its directory is gone — managing its service by name.@.;"
         : "@#yellow:Note:@; @#cyan:{$projectName}@; is not registered — managing its service by name.@.;");

      // :
      return BOOTGLY_WORKING_DIR . "projects/{$projectName}/";
   }

   /**
    * Describe why a unit at a project's path is not that project's to touch —
    * masked or linked, unstamped, or stamped by another project or kit.
    */
   private function describe (Service $Service): string
   {
      // ?:
      if (is_link($Service->file) === true) {
         return "@#cyan:{$Service->file}@; is a link — a masked unit, or a trap. @#cyan:systemctl unmask {$Service->unit}@; or remove the link first.@.;";
      }
      [$project, $kit] = $Service->owner;
      if ($project === '' && $kit === '') {
         return "@#cyan:{$Service->file}@; carries no Bootgly stamp — it was not installed by @#cyan:startup@;. Remove it by hand first.@.;";
      }

      // :
      return "@#cyan:{$Service->file}@; is stamped for @#cyan:{$project}@; of @#cyan:{$kit}@; — rename one of the projects or remove that unit first.@.;";
   }

   /**
    * Compose the units one project needs: its server (a WPI project) and its
    * schedule worker (a project carrying `schedule.php`) — or both candidates
    * regardless, with `$every`, to find what an earlier install left.
    *
    * @param string $projectName Canonical project path.
    * @param string $projectDir Resolved project directory, with a trailing separator.
    * @param string $user Account the units run as.
    * @param bool $every Both candidates, whatever the project carries today.
    *
    * @return array<Service>
    */
   private function compose (string $projectName, string $projectDir, string $user, bool $every = false): array
   {
      $launcher = BOOTGLY_WORKING_DIR . 'bootgly';
      $directory = rtrim(BOOTGLY_WORKING_DIR, '/');
      // ! A project with a database starts after the usual servers, when they exist
      $after = is_dir("{$projectDir}configs/database") === true
         ? ['postgresql.service', 'mysql.service', 'mysqld.service', 'mariadb.service']
         : [];

      $Services = [];

      $interfaces = Projects::read()[$projectName]['interfaces'] ?? [];
      if ($every === true || in_array('WPI', $interfaces, true) === true) {
         $Services[] = new Service(
            name: Service::identify($projectName),
            project: $projectName,
            kit: $directory,
            description: "Bootgly project {$projectName}",
            command: [PHP_BINARY, $launcher, 'project', $projectName, 'start', '-f'],
            user: $user,
            after: $after,
            // ! The master re-execs on SIGUSR2 — `systemctl reload` is a hot reload
            reload: '/bin/kill -USR2 $MAINPID'
         );
      }
      if ($every === true || is_file("{$projectDir}schedule.php") === true) {
         $Services[] = new Service(
            name: Service::identify($projectName, 'schedule'),
            project: $projectName,
            kit: $directory,
            description: "Bootgly project {$projectName} — schedule worker",
            command: [PHP_BINARY, $launcher, 'project', $projectName, 'schedule', 'run'],
            user: $user,
            after: $after
         );
      }

      // :
      return $Services;
   }

   /**
    * Track a freshly minted project in a git repository of its own.
    *
    * Projects are the unit of versioning — the kit is never committed to —
    * so every project starts as a repository of its own, with the scaffold
    * as its first commit. Best-effort by design: whatever refuses here (a
    * disabled shell, a missing git, an unset identity) degrades to a note
    * and never fails the create that already succeeded.
    *
    * @param string $base Projects base directory, with a trailing separator.
    * @param string $path Canonical project path (e.g. `App/API`).
    * @param array<string, bool|int|string> $options
    *
    * @return void
    */
   private function track (string $base, string $path, array $options): void
   {
      // ? Opt-out
      if (isSet($options['no-git']) === true) {
         return;
      }
      // ? Author context: the framework checkout tracks `projects/` itself —
      //   an embedded repository would dirty the author tree
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR && $base === Projects::CONSUMER_DIR) {
         return;
      }

      $Output = CLI->Terminal->Output;

      // ? A host with `shell_exec` disabled loses the repository, not the project
      if (function_exists('shell_exec') === false) {
         $Output->render("@#yellow:Note:@; shell access is disabled — initialize git in @#cyan:projects/{$path}@; yourself.@.;");

         return;
      }
      // ? git availability
      if (trim((string) shell_exec('command -v git 2>/dev/null')) === '') {
         $Output->render("@#yellow:Note:@; git was not found — initialize a repository in @#cyan:projects/{$path}@; yourself.@.;");

         return;
      }

      // ! Paths — every shell line quotes them; comparisons normalize slashes
      $target = "{$base}{$path}";
      $dir = escapeshellarg($target);

      // ? An existing project repository below the projects base already
      //   governs this directory — a nested project belongs to its parent's
      //   repository. The kit repository ABOVE the base proves nothing: the
      //   kit is always a repository, which is exactly why a bare
      //   "inside a repo?" check would never init anything.
      $toplevel = trim((string) shell_exec("git -C {$dir} rev-parse --show-toplevel 2>/dev/null"));
      if ($toplevel !== '') {
         $projects = str_replace('\\', '/', (string) realpath($base));
         $governor = str_replace('\\', '/', (string) realpath($toplevel));
         if ($projects !== '' && str_starts_with("{$governor}/", rtrim($projects, '/') . '/') === true) {
            // ! Silence here reads as success: `project <Name> boot` would
            //   print nothing and exit 0 after declining to do anything
            $owner = str_replace(str_replace('\\', '/', (string) realpath($base)) . '/', '', $governor);

            CLI->Terminal->Output->render(
               "@#yellow:Note:@; @#cyan:projects/{$path}@; is versioned by "
               . "@#cyan:projects/{$owner}@; — nothing to initialize.@.;"
            );

            return;
         }
      }

      // @ Init — `init.defaultBranch` is the user's to set
      shell_exec("git -C {$dir} init --quiet 2>/dev/null");
      // ?
      if (is_dir("{$target}/.git") === false) {
         $Output->render("@#yellow:Note:@; could not initialize a git repository in @#cyan:projects/{$path}@;.@.;");

         return;
      }

      // @ Stage — an anchored pathspec inside a directory this command just
      //   minted in full: nothing user-authored can exist in it yet
      shell_exec("git -C {$dir} add . 2>/dev/null");

      // ? Identity — never fabricated
      $name = trim((string) shell_exec("git -C {$dir} config user.name 2>/dev/null"));
      $email = trim((string) shell_exec("git -C {$dir} config user.email 2>/dev/null"));
      if ($name === '' || $email === '') {
         $Output->render(
            '@#yellow:Note:@; git identity unset — repository initialized, initial commit skipped. '
            . 'Set @#cyan:git config user.name/user.email@; and commit.@.;'
         );

         return;
      }

      // @ Commit — machine-authored, so it ships unsigned: a configured
      //   signing key would park the CLI behind a pinentry prompt
      $message = escapeshellarg("chore: create {$path} project scaffold");
      $committed = trim((string) shell_exec(
         "git -C {$dir} -c commit.gpgsign=false commit --quiet -m {$message} 2>/dev/null && echo committed"
      ));
      // ?
      if ($committed !== 'committed') {
         $Output->render("@#yellow:Note:@; repository initialized, but the initial commit failed in @#cyan:projects/{$path}@;.@.;");

         return;
      }

      // :
      $Output->render("@#green:Initialized@; a git repository in @#cyan:projects/{$path}@;.@.;");
   }

   /**
    * Open one project file without booting it.
    */
   private function open (string $projectName): null|Project
   {
      $Output = CLI->Terminal->Output;
      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return null;
      }

      $projectFile = $projectDir . basename($projectName) . '.Project.php';
      if (is_file($projectFile) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No project file found for @#cyan:{$projectName}@;.";
         $Alert->render();

         return null;
      }

      // ! Per-project Composer autoload — before the signature, so its
      //   top-level third-party references resolve
      Projects::load($projectDir);

      $Project = require $projectFile;
      if ($Project instanceof Project === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid project file for @#cyan:{$projectName}@;.";
         $Alert->render();

         return null;
      }

      return $Project;
   }

   /**
    * Configure the project database facade from the database config scope.
    */
   private function configure (Project $Project): null|SQL
   {
      $Output = CLI->Terminal->Output;
      $configsDir = $Project->path . 'configs/';

      if (is_dir($configsDir) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project has no configs directory: @#cyan:{$Project->folder}@;@.;";
         $Alert->render();

         return null;
      }

      $Configs = new Configs($configsDir);
      if ($Configs->load('database') === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project has no database config scope: @#cyan:{$Project->folder}@;@.;";
         $Alert->render();

         return null;
      }

      $Scope = $Configs->Scopes->get('database');
      if ($Scope === null) {
         return null;
      }

      $Config = new DatabaseConfig($Scope)->configure();

      return new SQL($Config);
   }

   /**
    * Resolve the project directory path.
    *
    * @param string $projectName
    *
    * @return null|string The resolved directory path, or null if not found.
    */
   private function resolve (string $projectName): null|string
   {
      $Output = CLI->Terminal->Output;

      // ? Security gate: path-safety + allow-list membership
      if (Projects::validate($projectName) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project not registered: @#cyan:{$projectName}@;@.;";
         $Alert->render();

         $Output->render(
            '@#Green:Tip:@; Register it in @#Cyan:projects/Bootgly.projects.php@; or use @#Blue:bootgly projects list@;.@..;'
         );

         return null;
      }

      // @ Resolve dir (consumer dir wins, framework fallback)
      $projectsBase = BOOTGLY_WORKING_DIR . 'projects/';
      $projectDir = $projectsBase . $projectName . '/';
      if (is_dir($projectDir) === false) {
         $projectsBase = BOOTGLY_ROOT_DIR . 'projects/';
         $projectDir = $projectsBase . $projectName . '/';
      }
      if (is_dir($projectDir) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project not found: @#cyan:{$projectName}@;@.;";
         $Alert->render();

         $Output->render(
            '@#Green:Tip:@; Use @#Blue:bootgly projects list@; to see all available projects.@..;'
         );

         return null;
      }

      // ? Defense-in-depth: jail the resolved dir under the projects base
      $real = realpath($projectDir);
      $realBase = realpath($projectsBase);
      if ($real === false || $realBase === false || str_starts_with($real, $realBase . '/') === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project path escapes the projects directory: @#cyan:{$projectName}@;@.;";
         $Alert->render();

         return null;
      }

      // :
      return $projectDir;
   }

   /**
    * Locate a running project's authenticated process data.
    *
    * @param string $projectName
    * @param null|string $instance Optional instance qualifier — the bound port (e.g. '8080').
    *
    * @return null|array{master:int,workers:array<int>,started:int,status?:string,type:string,host?:string,port?:int,AutoTLS?:array<string,mixed>}
    */
   private function locate (string $projectName, null|string $instance = null): null|array
   {
      // : Shared discovery lives in ACI (States); commands only translate the name
      return States::locate(Projects::encode($projectName), $instance);
   }

   /** Best-effort cleanup without tombstoning a replacement instance. */
   private function scrub (string $projectName, string $instance, int $masterPID): bool
   {
      try {
         $State = new State(
            Projects::encode($projectName),
            $instance !== '' ? $instance : null
         );
         // ! Cleanup authority comes from acquiring the exact stable lock,
         //   not merely from observing an unauthenticated/stale JSON snapshot.
         //   A replacement master or surviving lineage keeps this acquisition
         //   contended, so a delayed stop can never tombstone the new state.
         if ($State->lock(LOCK_EX | LOCK_NB) === false) {
            return false;
         }
         $current = $State->read();
         if (
            is_array($current)
            && ($current['master'] ?? null) !== $masterPID
         ) {
            $State->lock(LOCK_UN);
            return false;
         }
         $State->clean();

         return true;
      }
      catch (Throwable) {
         // ? State cleanup has always been best-effort here. An unsafe storage
         //   directory fails closed instead of falling back to raw pathname IO.
         return false;
      }
   }

   /**
    * List all running instances for a project.
    *
    * @param string $projectName
    *
    * @return array<string, array{master:int,workers:array<int>,started:int,status?:string,type:string,host?:string,port?:int,AutoTLS?:array<string,mixed>}>
    *         Keys are instance qualifiers ('' for legacy unqualified files, the bound port otherwise)
    */
   private function scan (string $projectName): array
   {
      // : Shared discovery lives in ACI (States); commands only translate the name
      return States::scan(Projects::encode($projectName));
   }

   /**
    * Re-authenticate the master immediately before a project-control action.
    *
    * @phpstan-impure The kernel process and flock state can change between calls.
    */
   private function authenticate (
      string $projectName,
      string $instance,
      int $PID,
      null|int $parent = null
   ): bool
   {
      // : Shared discovery lives in ACI (States); commands only translate the name
      return States::authenticate(Projects::encode($projectName), $instance, $PID, $parent);
   }

   /**
    * Explain the privilege boundary when root-owned project state cannot be
    * verified by the current runtime user.
    */
   private function hint (string $projectName, string $action): void
   {
      // ? Running as root already sees everything
      if (posix_getuid() === 0) {
         return;
      }

      // ? Only when candidate state files actually exist
      $encoded = Projects::encode($projectName);
      $states = array_merge(
         glob(BOOTGLY_STORAGE_DIR . "pids/{$encoded}.json") ?: [],
         glob(BOOTGLY_STORAGE_DIR . "pids/{$encoded}.*.json") ?: []
      );
      if ($states === []) {
         return;
      }

      CLI->Terminal->Output->render(
         "@#Green:Tip:@; state files exist but could not be verified — manage the process from its original service account. If this is an intentional root-controlled deployment, invoke that deployment's absolute PHP binary and launcher directly for @#Blue:project {$projectName} {$action}@; (never @#red:sudo bootgly@;).@..;"
      );
   }

   /**
    * Show detailed information about a specific project.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function info (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Require project name
      $folder = $arguments[0] ?? null;
      if ($folder === null || $folder === '') {
         return $this->help(['info']);
      }

      // @ Resolve project directory
      $projectDir = $this->resolve($folder);
      if ($projectDir === null) {
         return false;
      }

      // @ Load metadata from project file (leaf-named)
      $projectFile = $projectDir . basename($folder) . '.Project.php';
      $meta = is_file($projectFile)
         ? Projects::inspect($projectFile, $folder)
         : ['name' => $folder, 'description' => '', 'version' => '', 'author' => ''];

      // @ Detect interfaces from index files
      $interfaces = [];
      $projects_CLI = Projects::discover('CLI');
      $projects_WPI = Projects::discover('WPI');
      if (isSet($projects_CLI[$folder])) {
         $interfaces[] = 'CLI';
      }
      if (isSet($projects_WPI[$folder])) {
         $interfaces[] = 'WPI';
      }

      // @ Build Fieldset content
      $content = '';
      $content .= '@#Green:' . str_pad('Name', 14) . ' @; ' . $meta['name'] . PHP_EOL;
      $content .= '@#Green:' . str_pad('Folder', 14) . ' @; ' . $folder . PHP_EOL;
      $content .= '@#Green:' . str_pad('Description', 14) . ' @; ' . ($meta['description'] ?: '(none)') . PHP_EOL;
      $content .= '@#Green:' . str_pad('Version', 14) . ' @; ' . ($meta['version'] ?: '(none)') . PHP_EOL;
      $content .= '@#Green:' . str_pad('Author', 14) . ' @; ' . ($meta['author'] ?: '(none)') . PHP_EOL;
      $content .= '@#Green:' . str_pad('Interfaces', 14) . ' @; ' . (implode(', ', $interfaces) ?: '(none)') . PHP_EOL;
      $content .= '@#Green:' . str_pad('Path', 14) . ' @; ' . $projectDir;

      $Output->write(PHP_EOL);
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Project Info @;';
      $Fieldset->content = $content;
      $Fieldset->render();
      $Output->write(PHP_EOL);

      return true;
   }

   // ...
   /**
    * Display usage help or report invalid arguments.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function help (array $arguments = []): bool
   {
      $Output = CLI->Terminal->Output;

      // @
      $output = '';
      $status = true;

      if ( empty($arguments) ) {
         $Output->write(PHP_EOL);

         // # Header
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = "@#Cyan: {$this->name} @;";
         $Fieldset->content = $this->description;
         $Fieldset->render();

         // # Arguments
         $content = '';
         foreach ($this->arguments as $name => $value) {
            /** @var array{description: string, arguments: array<string,string>}|string $value */
            $description = is_array($value) ? $value['description'] : $value;
            $label = $name;
            $content .= '@#Yellow:' . $name . '@;';
            $content .= str_pad('', 10 - strlen($label)) . '  ' . $description . PHP_EOL;
         }
         $content = rtrim($content);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project arguments @;';
         $Fieldset->content = $content;
         $Fieldset->render();

         // # Usage
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Project usage @;';
         $Fieldset->content = 'bootgly project @#Black: <argument> @;@.;';
         $Fieldset->content .= 'bootgly project @#Black: <argument> <name> @;@.;';
         $Fieldset->content .= 'bootgly project @#Black: <name> <argument> @;';
         $Fieldset->render();

         // # Examples
         $exampleLines = '@#Blue:bootgly project Demo/HTTP_Server_CLI start@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI stop@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI show@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI restart@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI info@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI logs -f@; @#Black:(follow live — unrelated to `start -f`)@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI schedule run@; @#Black:(cron-style worker — no server started)@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI startup --now@; @#Black:(systemd service — boots at startup)@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI status@;' . PHP_EOL;
         $exampleLines .= PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project start Demo/HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project stop Demo/HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project show Demo/HTTP_Server_CLI@;';
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Project examples @;';
         $Fieldset->content = $exampleLines;
         $Fieldset->render();
      }
      else if ( isSet($this->arguments[$arguments[0]]) ) {
         $status = false;

         // @ Show usage for a valid subcommand
         $subcommand = $arguments[0];
         /** @var array{description: string, arguments: array<string,string>} $meta */
         $meta = $this->arguments[$subcommand];

         $Output->write(PHP_EOL);
         $Output->render("@#Black: {$meta['description']}@;@.;");

         // @ Alert missing <name>
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Missing required argument: @#cyan:<name>@;';
         $Alert->render();
         $Output->write(PHP_EOL);

         // @ Show arguments if any
         if ( !empty($meta['arguments']) ) {
            $argLines = '';
            foreach ($meta['arguments'] as $arg => $argDesc) {
               $argLines .= '@#cyan:' . str_pad($arg, 9) . '@; ' . $argDesc . PHP_EOL;
            }
            $argLines = rtrim($argLines);

            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Project ' . $subcommand . ' arguments @;';
            $Fieldset->content = $argLines;
            $Fieldset->render();
         }

         // # Usage
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project ' . $subcommand . ' usage @;';
         $Fieldset->content = 'bootgly project ' . $subcommand . ' @#Black: <name> @;' . PHP_EOL
            . 'bootgly project @#Black: <name>  @;' . $subcommand;
         $Fieldset->render();

         // # Example
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project ' . $subcommand . ' example @;';
         $Fieldset->content = '@#Blue:bootgly project Demo/HTTP_Server_CLI ' . $subcommand . '@;' . PHP_EOL
            . '@#Blue:bootgly project ' . $subcommand . ' Demo/HTTP_Server_CLI@;';
         $Fieldset->render();

         // # Hint
         $Output->render(
            '@.;@#Green:Tip:@; Use @#Blue:bootgly projects list@; to see all available projects.@.;'
         );
      }
      else {
         $status = false;

         // @ Show invalid argument alert then general help
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid argument: @#cyan:{$arguments[0]}@;.";
         $Alert->render();

         $this->help([]);

         return false;
      }

      $output .= '@.;';

      $Output->render($output);

      return $status;
   }
}
