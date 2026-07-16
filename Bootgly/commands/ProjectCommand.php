<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use const ARRAY_FILTER_USE_KEY;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_TTY;
use const BOOTGLY_WORKING_DIR;
use const GLOB_ONLYDIR;
use const PHP_EOL;
use const SIGKILL;
use const SIGTERM;
use const SIGUSR2;
use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function escapeshellarg;
use function explode;
use function file_get_contents;
use function getmypid;
use function glob;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_numeric;
use function is_string;
use function json_decode;
use function json_encode;
use function passthru;
use function posix_get_last_error;
use function posix_kill;
use function preg_match;
use function putenv;
use function realpath;
use function rmdir;
use function rtrim;
use function scandir;
use function shell_exec;
use function str_pad;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function time;
use function unlink;
use function usleep;
use Exception;
use Throwable;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\CLI;
use Bootgly\ACI\Process\State;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Migrations;
use Bootgly\ADI\Databases\SQL\Schema\Runner as MigrationRunner;
use Bootgly\ADI\Databases\SQL\Seed\Runner as SeedRunner;
use Bootgly\ADI\Databases\SQL\Seed\Seeders;
use Bootgly\API\Environment\Configs\DatabaseConfig;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Configs;
use Bootgly\API\Projects\Project;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Fieldset;
use Bootgly\CLI\UI\Components\Menu;
use Bootgly\CLI\UI\Components\Question;
use Bootgly\CLI\UX\Components\Wizard;
use Bootgly\commands\BootCommand;


/**
 * CLI command for managing Bootgly projects.
 *
 * Provides subcommands to list, set, start, and inspect projects
 * registered in the projects/ directory (consumer or framework).
 */
class ProjectCommand extends Command
{
   // * Config
   public bool $separate = true;
   public int $group = 2;

   // * Data
   // # Command
   public string $name = 'project';
   public string $description = 'Manage Bootgly projects';
   /** @phpstan-ignore property.phpDocType */
   /** @var array<string,array<string,array<string,string>|string>> */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'create' => [
         'description' => 'Create a new project (wizard on interactive terminals)',
         'arguments'   => [
            '[name]' => 'Project path to create (e.g. App or App/API)'
         ]
      ],
      'import' => [
         'description' => 'Import a project from a git repository URL',
         'arguments'   => [
            '<url>'  => 'Repository URL with a *.project.php signature at its root',
            '[name]' => 'Project path to import as (defaults to the repository name)'
         ]
      ],
      'list' => [
         'description' => 'List all registered projects',
         'arguments'   => []
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
            '[port]' => 'Stop only one instance — bound port (servers) or master PID (TUI)'
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
   ];
   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Preview seed run without executing SQL' => ['--dry-run'],
      'Platforms to set up on first run (create/import)' => ['--platform=console', '--platform=web', '--platform=console,web'],
      'Creation source: from scratch or a platform project' => ['--from=scratch', '--from=<source>'],
      'Interface bound to the new project (create/import)' => ['--interfaces=CLI', '--interfaces=WPI'],
      'New project metadata (create)' => ['--description=', '--version=', '--author=', '--port='],
      'Flag the new project as the web default (create/import)' => ['--default'],
      'Skip confirmations (create/import)' => ['--yes'],
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
         'create'  => $this->create(
            array_slice($arguments, 1),
            $options
         ),
         'import'  => $this->import(
            array_slice($arguments, 1),
            $options
         ),
         'list'    => $this->list(),
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

         default   => $this->help($arguments)
      };
   }

   // # Subcommands
   /**
    * Create a new project — the canonical (one-way) project creation entry.
    *
    * On interactive terminals a wizard fills the missing inputs (platform
    * setup, from-scratch or platform-project import, path, metadata). On
    * non-interactive terminals (or with `--yes`) everything comes from the
    * arguments and options.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function create (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Inputs
      $path = $arguments[0] ?? null;
      $from = isSet($options['from']) && is_string($options['from']) ? $options['from'] : null;

      // @ Wizard on interactive terminals (unless --yes) — kit setup is its first step
      if (BOOTGLY_TTY === true && isSet($options['yes']) === false) {
         return $this->wizard($path, $from, $options);
      }

      // @ Kit setup (platform submodules + resource dirs) when needed
      if ($this->prepare($options) === false) {
         return false;
      }

      // @ Non-interactive
      $from ??= 'scratch';

      // ? Project path required (imports default to the platform path)
      if ($path === null || $path === '') {
         if ($from !== 'scratch') {
            $path = $from;
         }
         else {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = 'Missing project path. Usage: @#cyan:bootgly project create <Name> '
               . '[--from=scratch|<source>] [--interfaces=CLI|WPI] [--port=] [--description=] '
               . '[--version=] [--author=] [--default] [--yes]@;';
            $Alert->render();

            return false;
         }
      }

      // @ From scratch
      if ($from === 'scratch') {
         // ? Project path validity
         $result = $this->assess($path);
         if ($result !== true) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = $result;
            $Alert->render();

            return false;
         }

         $interface = strtoupper((string) ($options['interfaces'] ?? 'CLI'));
         // ?
         if ($interface !== 'CLI' && $interface !== 'WPI') {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Invalid interface: @#cyan:{$interface}@;. Use CLI or WPI.";
            $Alert->render();

            return false;
         }

         $done = Projects::generate(
            BOOTGLY_ROOT_DIR . "Bootgly/commands/stubs/{$interface}",
            $path,
            [
               'interfaces'  => [$interface],
               'default'     => isSet($options['default']),
               'name'        => basename($path),
               'description' => (string) ($options['description'] ?? ''),
               'version'     => (string) ($options['version'] ?? '1.0.0'),
               'author'      => (string) ($options['author'] ?? ''),
               'port'        => (string) ($options['port'] ?? '8080'),
            ]
         );

         return $this->report($done, $path);
      }

      // @ From a platform project
      // ? Target path-safety
      if (Projects::check($path) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid project path: @#cyan:{$path}@;.";
         $Alert->render();

         return false;
      }

      $source = $this->trace($from);
      // ?
      if ($source === null) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Source project @#cyan:{$from}@; not found in the platform folders.";
         $Alert->render();

         return false;
      }

      // ? User-level copies overwrite the platform ones on load — refresh them
      if (is_dir(Projects::CONSUMER_DIR . $path) === true) {
         $this->erase(Projects::CONSUMER_DIR . $path);
      }

      $interfaces = $this->detect($from)
         ?? [strtoupper((string) ($options['interfaces'] ?? 'WPI'))];

      $done = Projects::import($source, $path, [
         'interfaces' => $interfaces,
         'default'    => isSet($options['default']),
      ]);

      return $this->report($done, $path);
   }

   /**
    * Import a project from a git repository URL.
    *
    * The repository must carry the Bootgly project signature — a
    * `*.project.php` file at its root.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function import (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Repository URL required
      $url = $arguments[0] ?? null;
      if ($url === null || $url === '') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Missing repository URL. Usage: @#cyan:bootgly project import <url> [Name]@;';
         $Alert->render();

         return false;
      }

      // @ Kit setup (platform submodules + resource dirs) when needed
      if ($this->prepare($options) === false) {
         return false;
      }

      // ! Target project path
      $path = $arguments[1] ?? basename($url, '.git');
      // ?
      $result = $this->assess($path);
      if ($result !== true) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "{$result} Pass the target path explicitly: "
            . "@#cyan:bootgly project import {$url} <Name>@;";
         $Alert->render();

         return false;
      }

      // @ Fetch with the system git
      $tmp = sys_get_temp_dir() . '/bootgly-import-' . getmypid();
      $this->erase($tmp);

      $Output->render("@#green:Fetching@; @#cyan:{$url}@;@.;");
      passthru('git clone --depth 1 ' . escapeshellarg($url) . ' ' . escapeshellarg($tmp), $status);
      // ?
      if ($status !== 0 || is_dir($tmp) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Could not clone @#cyan:{$url}@;.";
         $Alert->render();

         $this->erase($tmp);

         return false;
      }

      // ? Bootgly project signature
      if ((glob("{$tmp}/*.project.php") ?: []) === []) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Not a Bootgly project: no @#cyan:*.project.php@; signature file at the repository root.';
         $Alert->render();

         $this->erase($tmp);

         return false;
      }

      // ! Interface
      $interface = strtoupper((string) ($options['interfaces'] ?? 'WPI'));
      // ?
      if ($interface !== 'CLI' && $interface !== 'WPI') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid interface: @#cyan:{$interface}@;. Use CLI or WPI.";
         $Alert->render();

         $this->erase($tmp);

         return false;
      }

      // ! Summary
      $content  = '@#Green:' . str_pad('Mode', 12) . ' @; Import external repository' . PHP_EOL;
      $content .= '@#Green:' . str_pad('Source', 12) . ' @; ' . $url . PHP_EOL;
      $content .= '@#Green:' . str_pad('Path', 12) . ' @; ' . $path . PHP_EOL;
      $content .= '@#Green:' . str_pad('Interfaces', 12) . ' @; ' . $interface;

      $Output->write(PHP_EOL);
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Import project @;';
      $Fieldset->content = $content;
      $Fieldset->render();
      $Output->write(PHP_EOL);

      // ? Imported projects execute third-party code when started
      if (isSet($options['yes']) === false) {
         $confirmed = $this->confirm(
            "Importing will run third-party code when the project starts. Import as `{$path}`?"
         );

         if ($confirmed === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Attention->set();
            $Alert->message = 'Import aborted.';
            $Alert->render();

            $this->erase($tmp);

            return false;
         }
      }

      // @ Strip VCS metadata + import
      $this->erase("{$tmp}/.git");

      $done = Projects::import($tmp, $path, [
         'interfaces' => [$interface],
         'default'    => isSet($options['default']),
      ]);

      $this->erase($tmp);

      // :
      return $this->report($done, $path);
   }

   /**
    * List all discovered projects with their interfaces and default marker.
    *
    * @return bool
    */
   public function list (): bool
   {
      $Output = CLI->Terminal->Output;

      // @ Discover per-interface metadata
      $projects_CLI = $this->discover('CLI');
      $projects_WPI = $this->discover('WPI');

      // @ Merge in registry order (kept alphabetical by path)
      /** @var array<string, array{interfaces: list<string>, name: string, description: string}> $all */
      $all = [];
      foreach (array_keys(Projects::read()) as $folder) {
         $interfaces = [];
         if (isSet($projects_CLI[$folder])) {
            $interfaces[] = 'CLI';
         }
         if (isSet($projects_WPI[$folder])) {
            $interfaces[] = 'WPI';
         }
         if ($interfaces === []) {
            continue;
         }

         $meta = $projects_CLI[$folder] ?? $projects_WPI[$folder];
         $all[$folder] = [
            'interfaces'  => $interfaces,
            'name'        => $meta['name'],
            'description' => $meta['description']
         ];
      }

      if (empty($all)) {
         $Output->render('@.;@#red: No projects found. @; @.;');
         return true;
      }

      $Output->render('@.;@#cyan: Project list: @;@..;');

      $index = 1;
      foreach ($all as $folder => $info) {
         $interfaceList = implode(', ', $info['interfaces']);

         $Output->render(
            "@#magenta: #{$index} @; - "
            . "@#yellow:{$folder}@;"
            . PHP_EOL
         );

         if ($info['description'] !== '') {
            $Output->render(
               "     @#green:Description:@; {$info['description']}" . PHP_EOL
            );
         }

         $Output->render(
            "     @#green:Type:@; {$interfaceList}@..;"
         );

         $index++;
      }

      $Output->write(PHP_EOL);

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
      $projectFile = $projectDir . basename($projectName) . '.project.php';
      if (is_file($projectFile) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No project file found for @#cyan:{$projectName}@;.";
         $Alert->render();

         return false;
      }

      $Project = require $projectFile;
      if ($Project instanceof Project === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid project file for @#cyan:{$projectName}@;.";
         $Alert->render();

         return false;
      }

      $Project->boot($bootArguments, $options);

      return true;
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
         return false;
      }

      $stopped = 0;
      foreach ($instances as $instance => $PIDs) {
         if ($this->probe($PIDs['master']) === false) {
            // @ Tombstone stale per-instance state without requiring the
            //   runtime UID to own the shared parent directory.
            $this->scrub($projectName, $instance);
            continue;
         }

         $masterPid = $PIDs['master'];

         // @ Send SIGTERM to master
         if (
            posix_kill($masterPid, SIGTERM) === false
            && posix_get_last_error() === 1 // EPERM
         ) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Project @#cyan:{$projectName}@; is running as a different user (PID {$masterPid}). Run with @#Black:sudo@; to stop it.@.;";
            $Alert->render();
            return false;
         }

         // @ Wait for graceful shutdown
         $elapsed = 0.0;
         while ($elapsed < 5.0 && $this->probe($masterPid)) {
            usleep(100000); // 100ms
            $elapsed += 0.1;
         }

         // @ Force kill if still alive
         if ($this->probe($masterPid)) {
            posix_kill($masterPid, SIGKILL);
            usleep(100000);
         }

         // @ Kill remaining workers
         foreach ($PIDs['workers'] as $workerPid) {
            if ($this->probe($workerPid)) {
               posix_kill($workerPid, SIGTERM);
            }
         }
         usleep(100000);
         foreach ($PIDs['workers'] as $workerPid) {
            if ($this->probe($workerPid)) {
               posix_kill($workerPid, SIGKILL);
            }
         }

         // @ Tombstone PID/command state. The lock inode is preserved so a
         //   concurrent restart cannot split flock exclusivity across inodes.
         $this->scrub($projectName, $instance);

         $stopped++;
      }

      if ($stopped === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();
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

         return false;
      }

      foreach ($instances as $instance => $PIDs) {
         // @ Check master
         $masterAlive = $this->probe($PIDs['master']);
         $status = $masterAlive ? '@#green:running@;' : '@#red:stopped@;';

         // @ Count alive workers
         $workers = $PIDs['workers'];
         $aliveWorkers = 0;
         foreach ($workers as $workerPid) {
            if ($this->probe($workerPid)) {
               $aliveWorkers++;
            }
         }
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

         if ($PIDs['type'] === 'WPI') {
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
      foreach ($instances as $PIDs) {
         if ($this->probe($PIDs['master']) === false) {
            continue;
         }

         // @ Send SIGUSR2 to master
         posix_kill($PIDs['master'], SIGUSR2);

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
         if ($this->probe($PIDs['master'])) {
            $live[$instance] = $PIDs;
         }
      }

      // ? Ambiguous target: multiple instances and no port
      if ($port === null && count($live) > 1) {
         $ports = implode(', ', array_map(
            fn (array $PIDs): string => (string) $PIDs['port'],
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
         $port = (string) $live[$stopKey]['port'];
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

   // @ Helpers
   /**
    * Confirm one destructive CLI action.
    */
   private function confirm (string $question): bool
   {
      $Terminal = CLI->Terminal;

      $Question = new Question($Terminal->Input, $Terminal->Output);

      return $Question->confirm($question);
   }

   /**
    * Interactive project creation wizard (Wizard UX component).
    *
    * Every phase — kit setup, start mode and the branch it resolves — is a
    * wizard step. Handlers render their own failure Alerts, then throw a
    * short slug for the ✖ timeline note.
    *
    * @param null|string $path
    * @param null|string $from
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   private function wizard (null|string $path, null|string $from, array $options): bool
   {
      $Terminal = CLI->Terminal;
      $Output = $Terminal->Output;
      $Input = $Terminal->Input;

      // ! Flow state (closure-captured across steps)
      $branch = '';
      /** @var array<array{path: string, source: string}> $imports */
      $imports = [];
      /** @var array<string> $transferred */
      $transferred = [];
      /** @var array{interfaces?: array<string>, default?: bool, name?: string, description?: string, version?: string, author?: string, port?: int|string} $meta */
      $meta = ['default' => isSet($options['default'])];
      $interface = '';
      $url = '';
      $target = '';

      $Wizard = new Wizard($Input, $Output);
      $Wizard->title = '@#Cyan: Bootgly — New project wizard @;';

      // ! Branch steps — appended by the Mode handler once the branch is known
      // # From scratch: Path → Interface → Metadata → Confirm → Scaffold
      $scratch = function (Wizard $Wizard) use (&$path, &$meta, &$interface, &$options, $Input, $Output): void {
         $Wizard->add('Path', function () use (&$path, $Input, $Output): string {
            $Question = new Question($Input, $Output);
            $Question->prompt = 'Project path (e.g. `App` or `App/API`)';
            $Question->required = true;
            $Question->default = $path ?? '';
            $Question->Validator = fn (string $answer): true|string => $this->assess($answer);
            $path = $Question->ask();
            // ? EOF or invalid prefilled path
            if ($this->assess($path) !== true) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'A valid project path is required.';
               $Alert->render();

               throw new Exception('invalid path');
            }

            // :
            return $path;
         });

         $Wizard->add('Interface', function () use (&$meta, &$interface, &$options): string {
            // ? A valid --interfaces option skips the question
            $interface = strtoupper((string) ($options['interfaces'] ?? ''));
            if ($interface !== 'CLI' && $interface !== 'WPI') {
               $web = BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR
                  || is_file(BOOTGLY_WORKING_DIR . 'Web/' . BOOTSTRAP_FILENAME);

               $interface = 'CLI';
               if ($web === true) {
                  $choice = $this->choose('Which interface?', [
                     'CLI — Console app',
                     'WPI — Web (HTTP) server'
                  ]);
                  $interface = $choice === 1 ? 'WPI' : 'CLI';
               }
            }
            $meta['interfaces'] = [$interface];

            // :
            return $interface;
         });

         $Wizard->add('Metadata', function () use (&$path, &$meta, &$interface, &$options, $Input, $Output): null {
            // # Port (WPI)
            if ($interface === 'WPI') {
               $Question = new Question($Input, $Output);
               $Question->prompt = 'Server port';
               $Question->default = (string) ($options['port'] ?? '8080');
               $Question->Validator = static function (string $answer): true|string {
                  // ?:
                  if (preg_match('#^\d{1,5}$#', $answer) !== 1) {
                     return 'Invalid port: use a number between 1 and 65535.';
                  }

                  // :
                  return true;
               };
               $meta['port'] = $Question->ask();
            }

            // # Description / Version / Author (options prefill the defaults)
            $Question = new Question($Input, $Output);
            $Question->prompt = 'Description';
            $Question->default = (string) ($options['description'] ?? '');
            $meta['description'] = $Question->ask();

            $Question = new Question($Input, $Output);
            $Question->prompt = 'Version';
            $Question->default = (string) ($options['version'] ?? '1.0.0');
            $meta['version'] = $Question->ask();

            $Question = new Question($Input, $Output);
            $Question->prompt = 'Author';
            $Question->default = (string) ($options['author'] ?? '');
            $meta['author'] = $Question->ask();

            $meta['name'] = basename((string) $path);

            // :
            return null;
         });

         $Wizard->add('Confirm', function () use (&$path, &$meta, &$options, $Input, $Output): null {
            // ! Summary
            $content  = '@#Green:' . str_pad('Path', 12) . ' @; ' . $path . PHP_EOL;
            $content .= '@#Green:' . str_pad('Mode', 12) . ' @; From scratch' . PHP_EOL;
            $content .= '@#Green:' . str_pad('Interfaces', 12) . ' @; ' . implode(', ', $meta['interfaces'] ?? []);
            if (isSet($meta['port'])) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Port', 12) . ' @; ' . $meta['port'];
            }
            $content .= PHP_EOL . '@#Green:' . str_pad('Description', 12) . ' @; ' . (($meta['description'] ?? '') ?: '(none)');
            $content .= PHP_EOL . '@#Green:' . str_pad('Version', 12) . ' @; ' . ($meta['version'] ?? '');
            $content .= PHP_EOL . '@#Green:' . str_pad('Author', 12) . ' @; ' . (($meta['author'] ?? '') ?: '(none)');

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: New project @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            // ? Confirm
            if (isSet($options['yes']) === false) {
               $Question = new Question($Input, $Output);

               if ($Question->confirm('Create the project?', default: true) === false) {
                  $Alert = new Alert($Output);
                  $Alert->Type::Attention->set();
                  $Alert->message = 'Aborted.';
                  $Alert->render();

                  throw new Exception('aborted');
               }
            }

            // :
            return null;
         });

         $Wizard->add('Scaffold', function () use (&$path, &$meta, &$interface): string {
            $stub = $interface === 'WPI' ? 'WPI' : 'CLI';
            $done = Projects::generate(BOOTGLY_ROOT_DIR . "Bootgly/commands/stubs/{$stub}", (string) $path, $meta);
            // ? The report renders the actionable failure Alert (permissions / registry)
            if ($done === false) {
               $this->report(false, (string) $path);

               throw new Exception('generation failed');
            }

            // :
            return 'generated';
         });
      };

      // # From Platforms: [Pick →] Confirm → Transfer
      $platforms = function (Wizard $Wizard, bool $pick) use (&$imports, &$transferred, &$options, $Input, $Output): void {
         if ($pick === true) {
            $Wizard->add('Pick', function () use (&$imports, $Output): string {
               $sources = $this->survey();
               $labels = array_keys($sources);
               $picked = $this->select('Pick the projects to import:', $labels);

               // ? Nothing selected
               if ($picked === []) {
                  $Alert = new Alert($Output);
                  $Alert->Type::Attention->set();
                  $Alert->message = 'No projects selected.';
                  $Alert->render();

                  throw new Exception('nothing selected');
               }

               foreach ($picked as $index) {
                  $imports[] = $sources[(string) $labels[$index]];
               }

               // :
               return count($imports) . ' project(s)';
            });
         }

         $Wizard->add('Confirm', function () use (&$imports, &$options, $Input, $Output): null {
            // ! Summary (existing user-level copies are flagged as overwrite)
            $content = '@#Green:' . str_pad('Mode', 12) . ' @; Import projects from Platforms';
            foreach ($imports as $import) {
               $path = $import['path'];

               // ! Platform of origin (traced from the source directory)
               $platform = match (true) {
                  str_starts_with($import['source'], BOOTGLY_WORKING_DIR . 'Console/') => 'Console',
                  str_starts_with($import['source'], BOOTGLY_WORKING_DIR . 'Web/') => 'Web',
                  default => 'Bootgly'
               };

               $content .= PHP_EOL
                  . '@#Green:' . str_pad('Import', 12) . ' @; ' . $path
                  . " @#Cyan:(from {$platform})@;"
                  . (is_dir(Projects::CONSUMER_DIR . $path) ? ' @#Yellow:(overwrite)@;' : '');
            }

            $Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Import projects @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Output->write(PHP_EOL);

            // ? Confirm
            if (isSet($options['yes']) === false) {
               $Question = new Question($Input, $Output);

               if ($Question->confirm('Import the selected projects?', default: true) === false) {
                  $Alert = new Alert($Output);
                  $Alert->Type::Attention->set();
                  $Alert->message = 'Aborted.';
                  $Alert->render();

                  throw new Exception('aborted');
               }
            }

            // :
            return null;
         });

         $Wizard->add('Transfer', function () use (&$imports, &$transferred): string {
            $transferred = $this->transfer($imports);

            // ? Failure Alerts rendered at the failure site (transfer)
            if (count($transferred) !== count($imports)) {
               throw new Exception('import failed');
            }

            // :
            return count($transferred) . ' project(s)';
         });
      };

      // # From Git remote: URL → Path → Interface → Import
      $git = function (Wizard $Wizard) use (&$url, &$target, &$options, $Input, $Output): void {
         $Wizard->add('URL', function () use (&$url, $Input, $Output): null {
            $Question = new Question($Input, $Output);
            $Question->prompt = 'Repository URL (git)';
            $Question->required = true;
            $url = $Question->ask();
            // ?
            if ($url === '') {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'A repository URL is required.';
               $Alert->render();

               throw new Exception('URL required');
            }

            // :
            return null;
         });

         $Wizard->add('Path', function () use (&$url, &$target, $Input, $Output): string {
            $default = basename($url, '.git');
            $Question = new Question($Input, $Output);
            $Question->prompt = 'Project path (e.g. `App` or `App/API`)';
            $Question->required = true;
            $Question->default = $this->assess($default) === true ? $default : '';
            $Question->Validator = fn (string $answer): true|string => $this->assess($answer);
            $target = $Question->ask();
            // ?
            if ($this->assess($target) !== true) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'A valid project path is required.';
               $Alert->render();

               throw new Exception('invalid path');
            }

            // :
            return $target;
         });

         $Wizard->add('Interface', function () use (&$options): string {
            // ? A valid --interfaces option skips the question
            if (isSet($options['interfaces']) === false) {
               $choice = $this->choose('Which interface?', [
                  'CLI — Console app',
                  'WPI — Web (HTTP) server'
               ]);
               $options['interfaces'] = $choice === 1 ? 'WPI' : 'CLI';
            }

            // :
            return (string) $options['interfaces'];
         });

         $Wizard->add('Import', function () use (&$url, &$target, &$options): string {
            // ? Delegated to the import subcommand (clone, validate, confirm, register)
            if ($this->import([$url, $target], $options) === false) {
               throw new Exception('not imported');
            }

            // :
            return 'imported';
         });
      };

      // ! Seed steps
      // # Kit setup (platform submodules + resource dirs) — framework repo skips it
      if (BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR) {
         $Wizard->add('Platforms', function () use (&$options): string {
            // ? prepare() rendered its Alerts
            if ($this->prepare($options) === false) {
               throw new Exception('setup failed');
            }

            // :
            return 'ready';
         });
      }

      // # Start mode — resolves the branch and appends its steps
      $Wizard->add('Mode', function (Wizard $Wizard)
         use (&$branch, &$imports, $from, $scratch, $platforms, $git, $Output): string {
         // ? A source option picks the platform-import branch with no menu
         if ($from !== null && $from !== 'scratch') {
            $source = $this->trace($from);

            // ?
            if ($source === null) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = "Source project @#cyan:{$from}@; not found in the platform folders.";
               $Alert->render();

               throw new Exception('source not found');
            }

            $imports[] = ['path' => $from, 'source' => $source];

            $branch = 'platforms';
            $platforms($Wizard, pick: false);

            // :
            return "from {$from}";
         }

         // ? --from=scratch skips the menu
         if ($from === 'scratch') {
            $branch = 'scratch';
            $scratch($Wizard);

            // :
            return 'scratch';
         }

         // ! Start modes (platform import only when exportable sources exist)
         $modes = ['Create project from scratch'];
         if ($this->survey() !== []) {
            $modes[] = 'Import projects from Platforms (Demos, Example)';
         }
         $modes[] = 'Import project from Git remote';

         $mode = $modes[$this->choose('How do you want to start?', $modes)] ?? $modes[0];

         if ($mode === 'Import projects from Platforms (Demos, Example)') {
            $branch = 'platforms';
            $platforms($Wizard, pick: true);

            // :
            return 'platforms';
         }

         if ($mode === 'Import project from Git remote') {
            $branch = 'git';
            $git($Wizard);

            // :
            return 'git remote';
         }

         $branch = 'scratch';
         $scratch($Wizard);

         // :
         return 'scratch';
      });

      // @ Run the flow
      $done = $Wizard->run();

      // ? Failure Alerts render at the failure site (handlers) — the final frame appends below them
      if ($done === false) {
         return false;
      }

      // : Closing report — rendered after the completion screen, so it stays visible
      if ($branch === 'git') {
         return $this->report(true, $target);
      }

      if ($branch === 'platforms') {
         foreach ($transferred as $index => $imported) {
            $Alert = new Alert($Output);
            $Alert->spaced = $index === 0;
            $Alert->Type::Success->set();
            $Alert->message = "Project @#cyan:{$imported}@; imported!";
            $Alert->render();
         }

         $prefix = shell_exec('command -v bootgly 2>/dev/null') ? '' : 'php ';
         $Output->render("@.;@#Green:Tip:@; Use @#Black:{$prefix}bootgly project {$transferred[0]} start@; to boot it.@..;");

         return true;
      }

      return $this->report(true, (string) $path);
   }

   /**
    * Import platform projects into the working directory, keeping their paths.
    *
    * No questions are asked per project: each source is recursively copied to
    * `projects/<path>` at the working directory — the wizard Confirm step
    * already summarized and confirmed the batch. Existing user-level copies —
    * which overwrite the platform ones on load — are refreshed. Success
    * feedback is the caller's (it must survive the wizard completion screen);
    * only failures render Alerts here.
    *
    * @param array<array{path: string, source: string}> $imports
    *
    * @return array<string> The imported project paths.
    */
   private function transfer (array $imports): array
   {
      $Output = CLI->Terminal->Output;

      // @ Execute
      $paths = [];
      foreach ($imports as $import) {
         $path = $import['path'];

         // ? User-level copies overwrite the platform ones on load — refresh them
         if (is_dir(Projects::CONSUMER_DIR . $path) === true) {
            $this->erase(Projects::CONSUMER_DIR . $path);
         }

         $imported = Projects::import($import['source'], $path, [
            'interfaces' => $this->detect($path) ?? ['CLI'],
            'default'    => false,
         ]);

         // ? Failures render at the failure site — the wizard keeps them on screen
         if ($imported === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Could not import project @#cyan:{$path}@;.";
            $Alert->render();

            continue;
         }

         $paths[] = $path;
      }

      // :
      return $paths;
   }

   /**
    * Prepare the working directory (kit) on first run: platform submodules
    * (system git) and resource directories (`boot --resources`).
    *
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   private function prepare (array $options): bool
   {
      // ? Framework repo: nothing to prepare
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
         return true;
      }

      $Output = CLI->Terminal->Output;

      // # Platform submodules (kit)
      $gitmodules = is_file(BOOTGLY_WORKING_DIR . '.gitmodules');
      $console = is_file(BOOTGLY_WORKING_DIR . 'Console/' . BOOTSTRAP_FILENAME);
      $web = is_file(BOOTGLY_WORKING_DIR . 'Web/' . BOOTSTRAP_FILENAME);

      if ($gitmodules === true) {
         // ! Requested platforms (comma-separated: --platform=console,web)
         $platforms = null;
         if (isSet($options['platform']) && is_string($options['platform'])) {
            $platforms = array_filter(explode(',', strtolower($options['platform'])));

            // ?
            foreach ($platforms as $platform) {
               if ($platform !== 'console' && $platform !== 'web') {
                  $Alert = new Alert($Output);
                  $Alert->Type::Failure->set();
                  $Alert->message = "Invalid platform: @#cyan:{$platform}@;. "
                     . 'Use console, web or console,web.';
                  $Alert->render();

                  return false;
               }
            }
         }

         // ? Fresh kit (no resources booted yet): select interactively
         $fresh = is_file(BOOTGLY_WORKING_DIR . 'projects/Bootgly.projects.php') === false;
         if ($fresh === true && $platforms === null) {
            // ! Offer only platforms not initialized yet — a resumed install
            //   pre-marks the ones already present instead of re-asking
            $available = [];
            if ($console === false) {
               $available['console'] = 'Console — opinionated CLI extras (TUI apps)';
            }
            if ($web === false) {
               $available['web'] = 'Web — opinionated WPI extras';
            }

            if (BOOTGLY_TTY === true && $available !== []) {
               $Output->render(
                  '@.;The @#Cyan:Bootgly@; base platform is always included — unopinionated, '
                  . 'it ships the @#Cyan:CLI@; and @#Cyan:WPI@; interfaces.@..;'
               );

               $pinned = ['Bootgly — base platform (always included)'];
               if ($console === true) {
                  $pinned[] = 'Console — already set up';
               }
               if ($web === true) {
                  $pinned[] = 'Web — already set up';
               }

               $picked = $this->select(
                  'Which extra platforms do you want to set up?',
                  array_values($available),
                  pinned: $pinned
               );

               $platforms = [];
               $keys = array_keys($available);
               foreach ($picked as $index) {
                  if (isSet($keys[$index]) === true) {
                     $platforms[] = $keys[$index];
                  }
               }
            }
            else {
               $platforms = array_keys($available);
            }
         }

         // ! Missing submodules for the requested platforms
         $platforms ??= [];

         $targets = [];
         if ($console === false && in_array('console', $platforms, true) === true) {
            $targets[] = 'Console';
         }
         if ($web === false && in_array('web', $platforms, true) === true) {
            $targets[] = 'Web';
         }

         if ($targets !== []) {
            $modules = implode(' ', $targets);

            $Output->render("@.;@#green:Initializing platform submodules:@; @#cyan:{$modules}@;@.;");

            passthru(
               'git -C ' . escapeshellarg(BOOTGLY_WORKING_DIR) . " submodule update --init {$modules}",
               $status
            );

            // ?
            if ($status !== 0) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'Could not initialize the platform submodules. Run manually: '
                  . "@#cyan:git submodule update --init {$modules}@;";
               $Alert->render();

               return false;
            }
         }
      }

      // # Resource directories
      if (is_file(BOOTGLY_WORKING_DIR . 'projects/Bootgly.projects.php') === false) {
         $Boot = new BootCommand;

         if ($Boot->run([], ['resources' => true]) === false) {
            return false;
         }
      }

      // :
      return true;
   }

   /**
    * Assess a new project path: naming pattern, registry and directory collisions.
    *
    * @param string $path
    *
    * @return true|string True when usable; an error message otherwise.
    */
   private function assess (string $path): true|string
   {
      // ? Naming pattern
      if (preg_match('#^[A-Z][A-Za-z0-9_-]*(?:/[A-Z][A-Za-z0-9_-]*)*$#', $path) !== 1) {
         return "Invalid project path: `{$path}`. Segments must start uppercase and use "
            . 'only letters, numbers, `_` or `-` (e.g. `App` or `App/API`).';
      }
      // ? Reserved platform namespace root (would shadow the framework/platform namespaces)
      $root = strtolower(explode('/', $path)[0]);
      foreach (Projects::RESERVED as $reserved) {
         if ($root === strtolower($reserved)) {
            return "Invalid project path: `{$path}`. `{$reserved}` is a reserved Bootgly "
               . 'namespace root (framework/platform) and cannot be used as a project name.';
         }
      }
      // ? Registry collision
      if (array_key_exists($path, Projects::read()) === true) {
         return "Project `{$path}` is already registered.";
      }
      // ? Directory collision
      if (
         is_dir(Projects::CONSUMER_DIR . $path) === true
         || is_dir(Projects::AUTHOR_DIR . $path) === true
      ) {
         return "Project directory `projects/{$path}` already exists.";
      }

      // :
      return true;
   }

   /**
    * Survey the platform folders for exportable projects (Bootgly signature).
    *
    * Scans `projects/` inside each platform folder — `Bootgly/` (the framework),
    * `Console/` and `Web/` — up to two levels deep. Only projects declared
    * exportable (`new Project(exportable: true, ...)`) are listed.
    *
    * @return array<string, array{path: string, source: string}> Map of label to import info.
    */
   private function survey (): array
   {
      // !
      $sources = [];

      $bases = [
         'Bootgly' => Projects::AUTHOR_DIR,
         'Console' => BOOTGLY_WORKING_DIR . 'Console/projects/',
         'Web'     => BOOTGLY_WORKING_DIR . 'Web/projects/',
      ];

      // @@
      foreach ($bases as $platform => $base) {
         if (is_dir($base) === false) {
            continue;
         }

         $dirs = glob("{$base}*", GLOB_ONLYDIR) ?: [];
         foreach ($dirs as $dir) {
            $prefix = $platform === 'Bootgly' ? '' : "{$platform}: ";

            // ? Direct project (signature at depth 1)
            if ($this->inspect($dir) === true) {
               $path = substr($dir, strlen($base));
               $sources[$prefix . $path] = ['path' => $path, 'source' => $dir];

               continue;
            }

            // ? Subprojects (signature at depth 2)
            $subs = glob("{$dir}/*", GLOB_ONLYDIR) ?: [];
            foreach ($subs as $sub) {
               if ($this->inspect($sub) === true) {
                  $path = substr($sub, strlen($base));
                  $sources[$prefix . $path] = ['path' => $path, 'source' => $sub];
               }
            }
         }
      }

      // :
      return $sources;
   }

   /**
    * Inspect a directory signature for an exportable Bootgly project.
    *
    * @param string $dir
    *
    * @return bool True when the signature file returns an exportable Project.
    */
   private function inspect (string $dir): bool
   {
      // ? Bootgly project signature
      $signatures = glob("{$dir}/*.project.php") ?: [];
      if ($signatures === []) {
         return false;
      }

      try {
         $Project = include $signatures[0];
      }
      catch (Throwable) {
         return false;
      }

      // :
      return $Project instanceof Project && $Project->exportable === true;
   }

   /**
    * Trace a creation source against the platform folders.
    *
    * @param string $from Platform project path (e.g. `Demo/HTTP_Server_CLI`).
    *
    * @return null|string The source directory, or null when not found.
    */
   private function trace (string $from): null|string
   {
      // ?
      if (Projects::check($from) === false) {
         return null;
      }

      $bases = [
         Projects::AUTHOR_DIR,
         BOOTGLY_WORKING_DIR . 'Console/projects/',
         BOOTGLY_WORKING_DIR . 'Web/projects/',
      ];

      // @
      foreach ($bases as $base) {
         $dir = "{$base}{$from}";

         if (is_dir($dir) === true && (glob("{$dir}/*.project.php") ?: []) !== []) {
            // :
            return $dir;
         }
      }

      // :
      return null;
   }

   /**
    * Detect the interfaces bound to a platform project in the author registry.
    *
    * @param null|string $sourcePath
    *
    * @return null|array<string>
    */
   private function detect (null|string $sourcePath): null|array
   {
      // ?
      if ($sourcePath === null) {
         return null;
      }

      $file = Projects::AUTHOR_DIR . 'Bootgly.projects.php';
      if (is_file($file) === false) {
         return null;
      }

      $registry = include $file;
      if (is_array($registry) === false) {
         return null;
      }

      $meta = $registry[$sourcePath] ?? null;
      if (is_array($meta) === false) {
         return null;
      }

      $interfaces = $meta['interfaces'] ?? null;
      if (is_array($interfaces) === false) {
         return null;
      }

      // ! String-only interface list
      $list = [];
      foreach ($interfaces as $interface) {
         if (is_string($interface) === true) {
            $list[] = $interface;
         }
      }

      // :
      return $list === [] ? null : $list;
   }

   /**
    * Choose one option from a vertical, unique-selection Menu.
    *
    * @param string $prompt
    * @param array<string> $labels
    * @param int $default Index assumed when nothing is selected.
    * @param array<string> $pinned Display-only labels rendered first — always marked, locked.
    *
    * @return int The selected option index, relative to $labels.
    */
   private function choose (string $prompt, array $labels, int $default = 0, array $pinned = []): int
   {
      $Terminal = CLI->Terminal;

      $Menu = new Menu($Terminal->Input, $Terminal->Output);
      $Menu->prompt = "@#Cyan:{$prompt}@;\n@#Black:(↑/↓ to move, Space to select one, Enter to confirm)@;\n";

      $Options = $Menu->Items->Options;
      $Options->Selection::Unique->set();

      // ! Pinned labels render first — always marked, locked out of the selection
      foreach ($pinned as $label) {
         $Options->add(label: (string) $label, locked: true);
      }
      foreach ($labels as $label) {
         $Options->add(label: (string) $label);
      }

      // @@ Render until Enter
      foreach ($Menu->rendering() as $ignored);

      // : Index relative to $labels (pinned options never enter the selection)
      $offset = count($pinned);

      return (int) ($Menu->selected[0] ?? $default + $offset) - $offset;
   }

   /**
    * Select options from a vertical, multiple-selection Menu.
    *
    * @param string $prompt
    * @param array<string> $labels
    * @param array<string> $pinned Display-only labels rendered first — always marked, locked.
    *
    * @return array<int> The selected option indexes, relative to $labels (empty when none).
    */
   private function select (string $prompt, array $labels, array $pinned = []): array
   {
      $Terminal = CLI->Terminal;

      $Menu = new Menu($Terminal->Input, $Terminal->Output);
      $Menu->prompt = "@#Cyan:{$prompt}@;\n@#Black:(↑/↓ to move, Space to select multiple, Enter to confirm)@;\n";

      $Options = $Menu->Items->Options;
      // ? Selection mode is static per enum — always set it explicitly
      $Options->Selection::Multiple->set();

      // ! Pinned labels render first — always marked, locked out of the selection
      foreach ($pinned as $label) {
         $Options->add(label: (string) $label, locked: true);
      }
      foreach ($labels as $label) {
         $Options->add(label: (string) $label);
      }

      // @@ Render until Enter
      foreach ($Menu->rendering() as $ignored);

      // ! Integer-only index list, relative to $labels (pinned options never enter the selection)
      $offset = count($pinned);

      $indexes = [];
      foreach ($Menu->selected as $index) {
         $indexes[] = (int) $index - $offset;
      }

      // :
      return $indexes;
   }

   /**
    * Report the create/import outcome.
    *
    * @param bool $done
    * @param string $path
    *
    * @return bool
    */
   private function report (bool $done, string $path): bool
   {
      $Output = CLI->Terminal->Output;

      $Alert = new Alert($Output);

      if ($done === true) {
         $Alert->Type::Success->set();
         $Alert->message = "Project @#cyan:{$path}@; created!";
         $Alert->render();

         $prefix = shell_exec('command -v bootgly 2>/dev/null') ? '' : 'php ';
         $Output->render("@.;@#Green:Tip:@; Use @#Black:{$prefix}bootgly project {$path} start@; to boot it.@..;");
      }
      else {
         $Alert->Type::Failure->set();
         $Alert->message = "Could not create project @#cyan:{$path}@;. "
            . 'Check the target directory and the registry file (projects/Bootgly.projects.php) permissions.';
         $Alert->render();
      }

      // :
      return $done;
   }

   /**
    * Erase a file or directory recursively.
    *
    * @param string $target
    *
    * @return void
    */
   private function erase (string $target): void
   {
      // ?
      if (is_link($target) === true || is_file($target) === true) {
         unlink($target);

         return;
      }
      if (is_dir($target) === false) {
         return;
      }

      // @@
      $paths = scandir($target) ?: [];
      foreach ($paths as $entry) {
         if ($entry === '.' || $entry === '..') {
            continue;
         }

         $this->erase("{$target}/{$entry}");
      }

      rmdir($target);
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

      $projectFile = $projectDir . basename($projectName) . '.project.php';
      if (is_file($projectFile) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No project file found for @#cyan:{$projectName}@;.";
         $Alert->render();

         return null;
      }

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
            '@#Green:Tip:@; Register it in @#Black:projects/Bootgly.projects.php@; or use @#Black:bootgly project list@;.@..;'
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
            '@#Green:Tip:@; Use @#Black:bootgly project list@; to see all available projects.@..;'
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
    * Locate a running project's PID data from its state file.
    *
    * @param string $projectName
    * @param null|string $instance Optional instance qualifier — the bound port (e.g. '8080').
    *
    * @return null|array{master: int, workers: array<int>, host: string, port: int, started: int, type: string}
    */
   private function locate (string $projectName, null|string $instance = null): null|array
   {
      $suffix = $instance !== null ? '.' . $instance : '';
      $pidFile = BOOTGLY_STORAGE_DIR . 'pids/' . Projects::encode($projectName) . $suffix . '.json';

      if (is_file($pidFile) === false) {
         return null;
      }

      $content = file_get_contents($pidFile);
      if ($content === false) {
         return null;
      }

      /** @var null|array{master: int, workers: array<int>, host: string, port: int, started: int, type: string} $data */
      $data = json_decode($content, true);

      if (is_array($data) === false || isSet($data['master']) === false) { // @phpstan-ignore isset.offset, identical.alwaysFalse
         return null;
      }

      return $data;
   }

   /** Best-effort cleanup through the same safe state protocol as the server. */
   private function scrub (string $projectName, string $instance): void
   {
      try {
         $State = new State(
            Projects::encode($projectName),
            $instance !== '' ? $instance : null
         );
         $State->clean();
      }
      catch (Throwable) {
         // ? State cleanup has always been best-effort here. An unsafe storage
         //   directory fails closed instead of falling back to raw pathname IO.
      }
   }

   /**
    * List all running instances for a project.
    *
    * @param string $projectName
    *
    * @return array<string, array{master: int, workers: array<int>, host: string, port: int, started: int, type: string}>
    *         Keys are instance qualifiers ('' for legacy unqualified files, the bound port otherwise)
    */
   private function scan (string $projectName): array
   {
      $pidsDir = BOOTGLY_STORAGE_DIR . 'pids/';
      $instances = [];

      // @ Primary instance
      $primary = $this->locate($projectName);
      if ($primary !== null) {
         $instances[''] = $primary;
      }

      // @ Qualified instances (e.g. Demo~HTTP_Server_CLI.8082.json)
      $encoded = Projects::encode($projectName);
      $pattern = $pidsDir . $encoded . '.*.json';
      $files = glob($pattern);
      if ($files !== false) {
         foreach ($files as $file) {
            $basename = basename($file, '.json'); // Demo~HTTP_Server_CLI.8082
            $instance = substr($basename, strlen($encoded) + 1); // 8082
            $data = $this->locate($projectName, $instance);
            if ($data !== null) {
               $instances[$instance] = $data;
            }
         }
      }

      return $instances;
   }

   /**
    * Probe if a process is alive.
    *
    * Uses `/proc/<pid>` when available (Linux) so we detect processes owned
    * by other users (e.g. a server demoted to www-data probed by the caller
    * as rodrigo — `posix_kill($pid, 0)` returns false with EPERM in that
    * case, which would otherwise be misread as "process not running").
    *
    * @param int $pid
    *
    * @return bool
    */
   private function probe (int $pid): bool
   {
      if ($pid <= 0) {
         return false;
      }

      if (is_dir('/proc/' . $pid)) {
         return true;
      }

      if (posix_kill($pid, 0)) {
         return true;
      }

      return posix_get_last_error() === 1; // EPERM → process exists, not ours
   }

   /**
    * Discover projects for a given interface type.
    *
    * @param string $interface CLI or WPI
    *
    * @return array<string, array{name: string, description: string, version: string, author: string}>
    */
   private function discover (string $interface): array
   {
      // !
      $projects = [];

      // @ Try consumer dir first, then framework dir
      $projectsDir = is_dir(BOOTGLY_WORKING_DIR . 'projects')
         ? BOOTGLY_WORKING_DIR . 'projects/'
         : BOOTGLY_ROOT_DIR . 'projects/';

      // @ Iterate the registered paths for this interface (leaf-named project files)
      foreach (Projects::filter($interface) as $path) {
         $leaf = basename($path);
         $file = $projectsDir . $path . '/' . $leaf . '.project.php';
         if (is_file($file)) {
            $projects[$path] = $this->get($file, $path);
         }
      }

      // :
      return $projects;
   }

   /**
    * Get project metadata from project file.
    *
    * @param string $file The project file path
    * @param string $folder The project folder name (fallback)
    *
    * @return array{name: string, description: string, version: string, author: string}
    */
   private function get (string $file, string $folder): array
   {
      // !
      $defaults = [
         'name'        => $folder,
         'description' => '',
         'version'     => '',
         'author'      => ''
      ];

      // @
      $Project = require $file;
      if ($Project instanceof Project === false) {
         return $defaults;
      }

      // :
      return [
         'name'        => $Project->name !== '' ? $Project->name : $folder,
         'description' => $Project->description,
         'version'     => $Project->version,
         'author'      => $Project->author
      ];
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
      $projectFile = $projectDir . basename($folder) . '.project.php';
      $meta = is_file($projectFile)
         ? $this->get($projectFile, $folder)
         : ['name' => $folder, 'description' => '', 'version' => '', 'author' => ''];

      // @ Detect interfaces from index files
      $interfaces = [];
      $projects_CLI = $this->discover('CLI');
      $projects_WPI = $this->discover('WPI');
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
   public function help (array $arguments): bool
   {
      $Output = CLI->Terminal->Output;

      // @
      $output = '';
      $status = true;

      if ( empty($arguments) ) {
         $Output->write(PHP_EOL);

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
         $exampleLines = '@#Black:bootgly project create@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project create App/API --from=scratch --interfaces=WPI --yes@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project import https://github.com/foo/project1 Project1@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project list@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project Demo/HTTP_Server_CLI start@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project Demo/HTTP_Server_CLI stop@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project Demo/HTTP_Server_CLI show@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project Demo/HTTP_Server_CLI restart@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project Demo/HTTP_Server_CLI info@;' . PHP_EOL;
         $exampleLines .= PHP_EOL;
         $exampleLines .= '@#Black:bootgly project start Demo/HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project stop Demo/HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project show Demo/HTTP_Server_CLI@;';
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
         $Fieldset->content = '@#Black:bootgly project Demo/HTTP_Server_CLI ' . $subcommand . '@;' . PHP_EOL
            . '@#Black:bootgly project ' . $subcommand . ' Demo/HTTP_Server_CLI@;';
         $Fieldset->render();

         // # Hint
         $Output->render(
            '@.;@#Green:Tip:@; Use @#Black:bootgly project list@; to see all available projects.@.;'
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
