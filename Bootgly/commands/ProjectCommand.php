<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\commands;


use function array_keys;
use function array_slice;
use function basename;
use function count;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function intdiv;
use function is_array;
use function is_dir;
use function is_file;
use function json_decode;
use function posix_kill;
use function rtrim;
use function str_pad;
use function strlen;
use function substr;
use function time;
use function unlink;
use function usleep;
use const SIGKILL;
use const SIGTERM;
use const SIGUSR2;

use Bootgly\API\Projects\Project;
use const Bootgly\CLI;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Fieldset;


/**
 * CLI command for managing Bootgly projects.
 *
 * Provides subcommands to list, set, run, and inspect projects
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
      'list' => [
         'description' => 'List all registered projects',
         'arguments'   => []
      ],
      'run' => [
         'description' => 'Run a project by name',
         'arguments'   => [
            '<name>' => 'Project name to run'
         ]
      ],
      'stop' => [
         'description' => 'Stop a running project',
         'arguments'   => [
            '<name>' => 'Project name to stop'
         ]
      ],
      'show' => [
         'description' => 'Show status of a running project',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
      'reload' => [
         'description' => 'Hot-reload a running project',
         'arguments'   => [
            '<name>' => 'Project name to reload'
         ]
      ],
      'restart' => [
         'description' => 'Restart a running project by name',
         'arguments'   => [
            '<name>' => 'Project name to restart'
         ]
      ],
      'info' => [
         'description' => 'Show detailed info about a project',
         'arguments'   => [
            '<name>' => 'Project name'
         ]
      ],
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
         'list'    => $this->list(),
         'run'     => $this->start(
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

         default   => $this->help($arguments)
      };
   }

   // # Subcommands
   /**
    * List all discovered projects with their interfaces and default marker.
    *
    * @return bool
    */
   public function list (): bool
   {
      $Output = CLI->Terminal->Output;

      // @ Discover CLI projects
      $projects_CLI = $this->discover('CLI');
      // @ Discover WPI projects
      $projects_WPI = $this->discover('WPI');

      // @ Merge all projects
      /** @var array<string, array{interfaces: list<string>, name: string, description: string}> $all */
      $all = [];
      foreach ($projects_CLI as $folder => $meta) {
         $all[$folder] = [
            'interfaces'  => ['CLI'],
            'name'        => $meta['name'],
            'description' => $meta['description']
         ];
      }
      foreach ($projects_WPI as $folder => $meta) {
         if (isSet($all[$folder])) {
            $all[$folder]['interfaces'][] = 'WPI';
         }
         else {
            $all[$folder] = [
               'interfaces'  => ['WPI'],
               'name'        => $meta['name'],
               'description' => $meta['description']
            ];
         }
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
         return $this->help(['run']);
      }

      // @ Resolve project directory
      $projectDir = $this->resolve($projectName);
      if ($projectDir === null) {
         return false;
      }

      // ? Check if project is already running
      $PIDs = $this->locate($projectName);
      if ($PIDs !== null && $this->probe($PIDs['master'])) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is already running (PID: {$PIDs['master']}). Use `project restart` instead.@.;";
         $Alert->render();

         return false;
      }

      // @ Slice out the project name from arguments for boot
      $bootArguments = $projectName === $arguments[0] // @phpstan-ignore identical.alwaysTrue
         ? array_slice($arguments, 1)
         : $arguments;

      // @ Load and boot the project file
      $projectFile = $projectDir . $projectName . '.project.php';
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

      $Output->render(
         '@.;@#yellow:' . $Project->name . '@;'
         . ($Project->description !== '' ? ' — ' . $Project->description : '')
         . '@.;'
      );
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
      $instances = $this->locateAll($projectName);
      if (count($instances) === 0) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();
         return false;
      }

      $stopped = 0;
      foreach ($instances as $instance => $PIDs) {
         if ($this->probe($PIDs['master']) === false) {
            // @ Clean stale PID file
            $suffix = $instance !== '' ? '.' . $instance : '';
            $pidFile = BOOTGLY_WORKING_DIR . '/workdata/pids/' . $projectName . $suffix . '.json';
            if (is_file($pidFile)) {
               @unlink($pidFile);
            }
            continue;
         }

         $masterPid = $PIDs['master'];

         // @ Send SIGTERM to master
         posix_kill($masterPid, SIGTERM);

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

         // @ Remove PID file
         $suffix = $instance !== '' ? '.' . $instance : '';
         $pidFile = BOOTGLY_WORKING_DIR . '/workdata/pids/' . $projectName . $suffix . '.json';
         if (is_file($pidFile)) {
            @unlink($pidFile);
         }

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

      $instances = $this->locateAll($projectName);
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

      $PIDs = $this->locate($projectName);
      if ($PIDs === null || $this->probe($PIDs['master']) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.@.;";
         $Alert->render();

         return false;
      }

      // @ Send SIGUSR2 to master
      posix_kill($PIDs['master'], SIGUSR2);

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Reload signal sent to project @#cyan:{$projectName}@;.@.;";
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

      // @ Stop if running
      $PIDs = $this->locate($projectName);
      if ($PIDs !== null && $this->probe($PIDs['master'])) {
         $Output->render('@.;@#yellow:Stopping project...@;@.;');
         $this->stop($arguments);
      }

      // @ Start
      return $this->start($arguments, $options);
   }

   // @ Helpers
   /**
    * Resolve the project directory path.
    *
    * @param string $projectName
    *
    * @return null|string The resolved directory path, or null if not found.
    */
   private function resolve (string $projectName): null|string
   {
      $projectDir = BOOTGLY_WORKING_DIR . 'projects/' . $projectName . '/';
      if (is_dir($projectDir) === false) {
         $projectDir = BOOTGLY_ROOT_DIR . 'projects/' . $projectName . '/';
      }
      if (is_dir($projectDir) === false) {
         $Output = CLI->Terminal->Output;

         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project not found: @#cyan:{$projectName}@;@.;";
         $Alert->render();

         $Output->render(
            '@#Green:Tip:@; Use @#Black:bootgly project list@; to see all available projects.@..;'
         );

         return null;
      }

      return $projectDir;
   }

   /**
    * Locate a running project's PID data from its state file.
    *
    * @param string $projectName
    * @param null|string $instance Optional instance qualifier (e.g. 'test').
    *
    * @return null|array{master: int, workers: array<int>, host: string, port: int, started: int, type: string}
    */
   private function locate (string $projectName, null|string $instance = null): null|array
   {
      $suffix = $instance !== null ? '.' . $instance : '';
      $pidFile = BOOTGLY_WORKING_DIR . '/workdata/pids/' . $projectName . $suffix . '.json';

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

   /**
    * List all running instances for a project.
    *
    * @param string $projectName
    *
    * @return array<string, array{master: int, workers: array<int>, host: string, port: int, started: int, type: string}>
    *         Keys are instance names ('' for primary, 'test' for test, etc.)
    */
   private function locateAll (string $projectName): array
   {
      $pidsDir = BOOTGLY_WORKING_DIR . '/workdata/pids/';
      $instances = [];

      // @ Primary instance
      $primary = $this->locate($projectName);
      if ($primary !== null) {
         $instances[''] = $primary;
      }

      // @ Named instances (e.g. HTTP_Server_CLI.test.json)
      $pattern = $pidsDir . $projectName . '.*.json';
      $files = glob($pattern);
      if ($files !== false) {
         foreach ($files as $file) {
            $basename = basename($file, '.json'); // HTTP_Server_CLI.test
            $instance = substr($basename, strlen($projectName) + 1); // test
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
    * @param int $pid
    *
    * @return bool
    */
   private function probe (int $pid): bool
   {
      return posix_kill($pid, 0);
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

      // @ Load the interface index file
      $indexFile = $projectsDir . $interface . '.projects.php';
      if (is_file($indexFile) === false) {
         return $projects;
      }

      /** @var array<string>|false $index */
      $index = @include $indexFile;
      if (is_array($index) === false) {
         return $projects;
      }

      foreach ($index as $folder) {
         $file = $projectsDir . $folder . '/' . $folder . '.project.php';
         if (is_file($file)) {
            $projects[$folder] = $this->get($file, $folder);
         }
      }

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

      // @ Load metadata from project file
      $projectFile = $projectDir . $folder . '.project.php';
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
         $Fieldset->title = '@#Cyan: Project usage @;';
         $Fieldset->content = 'bootgly project @#Black: <argument> @;@.;';
         $Fieldset->content .= 'bootgly project @#Black: <argument> <name> @;@.;';
         $Fieldset->content .= 'bootgly project @#Black: <name> <argument> @;';
         $Fieldset->render();

         // # Examples
         $exampleLines = '@#Black:bootgly project list@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project run HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project stop HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project show HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project restart HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project info HTTP_Server_CLI@;' . PHP_EOL;
         $exampleLines .= PHP_EOL;
         $exampleLines .= '@#Black:bootgly project HTTP_Server_CLI run@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project HTTP_Server_CLI stop@;' . PHP_EOL;
         $exampleLines .= '@#Black:bootgly project HTTP_Server_CLI show@;';
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project examples @;';
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
         $Fieldset->content = '@#Black:bootgly project ' . $subcommand . ' HTTP_Server_CLI@;' . PHP_EOL
            . '@#Black:bootgly project HTTP_Server_CLI ' . $subcommand . '@;';
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
