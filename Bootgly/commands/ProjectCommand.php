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
use function array_merge;
use function array_slice;
use function array_unique;
use function basename;
use function count;
use function dirname;
use function file_get_contents;
use function file_put_contents;
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
   // @ Command
   public string $name = 'project';
   public string $description = 'Manage Bootgly projects';
   /** @phpstan-ignore property.phpDocType */
   /** @var array<string,array<string,list<string>|string>> */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'list' => [
         'description' => 'List all registered projects',
         'options'     => []
      ],
      'set' => [
         'description' => 'Set project properties (e.g. default)',
         'options'     => [
            '--default'
         ]
      ],
      // TODO: add separator here
      'run' => [
         'description' => 'Run a project (default or named)',
         'options'     => [
            '--cli',
            '--wpi',
            '-d',
            '-it',
            '-m'
         ]
      ],
      'stop' => [
         'description' => 'Stop a running project',
         'options'     => [
            '<name>'
         ]
      ],
      'show' => [
         'description' => 'Show status of a running project',
         'options'     => [
            '<name>'
         ]
      ],
      'reload' => [
         'description' => 'Hot-reload a running project',
         'options'     => [
            '<name>'
         ]
      ],
      'restart' => [
         'description' => 'Restart a running project',
         'options'     => [
            '--cli',
            '--wpi',
            '-d',
            '-it',
            '-m'
         ]
      ],
      'info' => [
         'description' => 'Show detailed info about a project',
         'options'     => [
            '<name>'
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
      return match ($arguments[0] ?? null) {
         'list'    => $this->list(),
         'set'     => $this->set(
            array_slice($arguments, 1),
            $options
         ),
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
         'info'    => $this->info($arguments),

         default   => $this->help($arguments)
      };
   }

   // @ Subcommands
   /**
    * List all discovered projects with their interfaces and default marker.
    *
    * @return bool
    */
   public function list (): bool
   {
      $Output = CLI->Terminal->Output;

      // @ Load @.php
      $config = @include(BOOTGLY_WORKING_DIR . 'projects/@.php');
      if ($config === false) {
         $config = @include(BOOTGLY_ROOT_DIR . 'projects/@.php');
      }
      $default = $config['default'] ?? '';

      // @ Discover CLI projects
      $cli_projects = $this->discover('CLI');
      // @ Discover WPI projects
      $wpi_projects = $this->discover('WPI');

      // @ Merge all projects
      /** @var array<string, array{interfaces: list<string>, name: string, description: string}> $all */
      $all = [];
      foreach ($cli_projects as $folder => $meta) {
         $all[$folder] = [
            'interfaces'  => ['CLI'],
            'name'        => $meta['name'],
            'description' => $meta['description']
         ];
      }
      foreach ($wpi_projects as $folder => $meta) {
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

      $Output->render('@.;@#cyan: Project list: @; @.;');

      $index = 1;
      foreach ($all as $folder => $info) {
         $interfaceList = implode(', ', $info['interfaces']);
         $defaultMark = ($folder === $default) ? " @#green:[default]@;" : '';

         $Output->render(
            "@#magenta: #{$index} @; - "
            . "@#yellow:{$info['name']}@; @#Black:(projects/{$folder})@; [{$interfaceList}]{$defaultMark}"
            . PHP_EOL
         );

         if ($info['description'] !== '') {
            $Output->render(
               "     @#white:{$info['description']}@;" . PHP_EOL
            );
         }

         $index++;
      }

      $Output->write(PHP_EOL);

      return true;
   }

   /**
    * Set project properties (e.g. default).
    * 
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    */
   public function set (array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Validate project name
      $projectName = $arguments[0] ?? null;
      if ($projectName === null || $projectName === '') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Usage: project set <name> --default';
         $Alert->render();
         return false;
      }

      // ? Validate project exists
      $cli = $this->discover('CLI');
      $wpi = $this->discover('WPI');
      $allProjects = array_unique(array_merge(
         array_keys($cli),
         array_keys($wpi)
      ));

      if (in_array($projectName, $allProjects) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; not found.";
         $Alert->render();
         return false;
      }

      // # Set as default
      if (isSet($options['default'])) {
         $configPath = BOOTGLY_WORKING_DIR . 'projects/@.php';
         if (is_file($configPath) === false) {
            $configPath = BOOTGLY_ROOT_DIR . 'projects/@.php';
         }

         $content = "<?php\nreturn [\n   'default' => '{$projectName}'\n];\n";
         file_put_contents($configPath, $content);

         $Output->render("@.;@#green: Default project set to:  @;@#cyan: {$projectName} @;@.;");

         return true;
      }

      // ? No option specified
      $Alert = new Alert($Output);
      $Alert->Type::Failure->set();
      $Alert->message = 'No option specified. Use --default.';
      $Alert->render();

      return false;
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

      if ($projectName === null) {
         // @ Load default from @.php
         $config = @include(BOOTGLY_WORKING_DIR . 'projects/@.php');
         if ($config === false) {
            $config = @include(BOOTGLY_ROOT_DIR . 'projects/@.php');
         }
         $projectName = $config['default'] ?? null;
      }

      if ($projectName === null || $projectName === '') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'No project specified and no default set.';
         $Alert->render();
         return false;
      }

      // @ Resolve project directory
      $projectDir = BOOTGLY_WORKING_DIR . 'projects/' . $projectName . '/';
      if (is_dir($projectDir) === false) {
         $projectDir = BOOTGLY_ROOT_DIR . 'projects/' . $projectName . '/';
      }
      if (is_dir($projectDir) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project directory not found: @#cyan:{$projectName}@;";
         $Alert->render();
         return false;
      }

      // ? Check if project is already running
      $PIDs = $this->locate($projectName);
      if ($PIDs !== null && $this->probe($PIDs['master'])) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is already running (PID: {$PIDs['master']}). Use `project restart` instead.";
         $Alert->render();
         return false;
      }

      // @ Slice out the project name from arguments for boot
      $bootArguments = $projectName === ($arguments[0] ?? null)
         ? array_slice($arguments, 1)
         : $arguments;

      // @ Determine which @autoboot files to run
      $filterCLI = isSet($options['cli']);
      $filterWPI = isSet($options['wpi']);
      $noFilter = !$filterCLI && !$filterWPI;

      $booted = false;

      // @ Boot WPI
      if ($filterWPI || $noFilter) {
         $wpiFile = $projectDir . 'WPI.project.php';
         if (is_file($wpiFile) === false) {
            $wpiFile = $projectDir . 'Web.project.php';
         }
         if (is_file($wpiFile)) {
            $Project = require $wpiFile;

            // @ Show project header
            if ($Project instanceof Project) {
               $Project->folder = $projectName;
               Project::$current = $Project;

               $Output->render(
                  '@.;@#yellow:' . $Project->name . '@;'
                  . ($Project->description !== '' ? ' — ' . $Project->description : '')
                  . '@.;'
               );
               $Project->boot($bootArguments, $options);
            }

            $booted = true;
         }
      }

      // @ Boot CLI
      if ($filterCLI || $noFilter) {
         $cliFile = $projectDir . 'CLI.project.php';
         if (is_file($cliFile) === false) {
            $cliFile = $projectDir . 'Console.project.php';
         }
         if (is_file($cliFile)) {
            $Project = require $cliFile;

            // @ Show project header
            if ($Project instanceof Project) {
               $Project->folder = $projectName;
               Project::$current = $Project;

               $Output->render(
                  '@.;@#cyan: Starting @;@#yellow:' . $Project->name . '@;'
                  . ($Project->description !== '' ? ' — ' . $Project->description : '')
                  . '@.;'
               );
               $Project->boot($bootArguments, $options);
            }

            $booted = true;
         }
      }

      if ($booted === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $filter = $filterCLI ? 'CLI' : ($filterWPI ? 'WPI' : 'any');
         $Alert->message = "No project file found for @#cyan:{$projectName}@; ({$filter}).";
         $Alert->render();
         return false;
      }

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

      // @
      $projectName = $this->resolve($arguments);
      if ($projectName === null) {
         return false;
      }

      $PIDs = $this->locate($projectName);
      if ($PIDs === null || $this->probe($PIDs['master']) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.";
         $Alert->render();
         return false;
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

      // @ Remove PID file
      $pidFile = BOOTGLY_WORKING_DIR . '/workdata/pids/' . $projectName . '.json';
      if (is_file($pidFile)) {
         @unlink($pidFile);
      }

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Project @#cyan:{$projectName}@; stopped.";
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

      // @
      $projectName = $this->resolve($arguments);
      if ($projectName === null) {
         return false;
      }

      $PIDs = $this->locate($projectName);
      if ($PIDs === null) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "No running instance found for project @#cyan:{$projectName}@;.";
         $Alert->render();
         return false;
      }

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
      $content = '';
      $content .= '@#Green:' . str_pad('Project', 14) . ' @; ' . $projectName . PHP_EOL;
      $content .= '@#Green:' . str_pad('Type', 14) . ' @; ' . $PIDs['type'] . PHP_EOL;
      $content .= '@#Green:' . str_pad('Status', 14) . ' @; ' . $status . PHP_EOL;
      $content .= '@#Green:' . str_pad('Master PID', 14) . ' @; ' . $PIDs['master'] . PHP_EOL;
      $content .= '@#Green:' . str_pad('Workers', 14) . ' @; ' . $aliveWorkers . '/' . $totalWorkers . PHP_EOL;

      $content .= '@#Green:' . str_pad('Address', 14) . ' @; ' . $PIDs['host'] . ':' . $PIDs['port'] . PHP_EOL;

      if ($uptime !== '') {
         $content .= '@#Green:' . str_pad('Uptime', 14) . ' @; ' . $uptime;
      }

      $content = rtrim($content);

      $Output->write(PHP_EOL);
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Project Status @;';
      $Fieldset->content = $content;
      $Fieldset->render();

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

      // @
      $projectName = $this->resolve($arguments);
      if ($projectName === null) {
         return false;
      }

      $PIDs = $this->locate($projectName);
      if ($PIDs === null || $this->probe($PIDs['master']) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:{$projectName}@; is not running.";
         $Alert->render();
         return false;
      }

      // @ Send SIGUSR2 to master
      posix_kill($PIDs['master'], SIGUSR2);

      $Alert = new Alert($Output);
      $Alert->Type::Success->set();
      $Alert->message = "Reload signal sent to project @#cyan:{$projectName}@;.";
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

      // @
      $projectName = $this->resolve($arguments);
      if ($projectName === null) {
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
    * Resolve a project name from arguments or default config.
    *
    * @param array<string> $arguments
    *
    * @return null|string
    */
   private function resolve (array $arguments): null|string
   {
      $projectName = $arguments[0] ?? null;

      if ($projectName === null) {
         $config = @include(BOOTGLY_WORKING_DIR . 'projects/@.php');
         if ($config === false) {
            $config = @include(BOOTGLY_ROOT_DIR . 'projects/@.php');
         }
         $projectName = $config['default'] ?? null;
      }

      if ($projectName === null || $projectName === '') {
         $Output = CLI->Terminal->Output;
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'No project specified and no default set.';
         $Alert->render();
         return null;
      }

      return $projectName;
   }

   /**
    * Locate a running project's PID data from its state file.
    *
    * @param string $projectName
    *
    * @return null|array{master: int, workers: array<int>, host: string, port: int, started: int, type: string}
    */
   private function locate (string $projectName): null|array
   {
      $pidFile = BOOTGLY_WORKING_DIR . '/workdata/pids/' . $projectName . '.json';

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

      $platformSuffix = match ($interface) {
         'CLI' => 'Console',
         'WPI' => 'Web',
         default => null
      };

      // @ Try consumer dir first, then framework dir
      $projectsDir = is_dir(BOOTGLY_WORKING_DIR . 'projects')
         ? BOOTGLY_WORKING_DIR . 'projects'
         : BOOTGLY_ROOT_DIR . 'projects';

      // # Framework Interface suffixes
      foreach (glob($projectsDir . "/*/{$interface}.project.php") ?: [] as $file) {
         $folder = basename(dirname($file));
         $projects[$folder] = $this->get($file, $folder);
      }
      // # Consumer Platform suffixes
      if ($platformSuffix !== null) {
         foreach (glob($projectsDir . "/*/{$platformSuffix}.project.php") ?: [] as $file) {
            $folder = basename(dirname($file));
            if (isSet($projects[$folder]) === false) {
               $projects[$folder] = $this->get($file, $folder);
            }
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

      // ? Validate argument
      $folder = $arguments[1] ?? null;
      if ($folder === null || $folder === '') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Usage: project info <name>';
         $Alert->render();
         return false;
      }

      // @ Resolve project directory
      $projectDir = BOOTGLY_WORKING_DIR . 'projects/' . $folder;
      if (is_dir($projectDir) === false) {
         $projectDir = BOOTGLY_ROOT_DIR . 'projects/' . $folder;
      }
      if (is_dir($projectDir) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project not found: @#cyan:{$folder}@;";
         $Alert->render();
         return false;
      }

      // @ Load metadata from first project file found
      $autobootFile = null;
      foreach (['WPI.project.php', 'Web.project.php', 'CLI.project.php', 'Console.project.php'] as $candidate) {
         if (is_file($projectDir . '/' . $candidate)) {
            $autobootFile = $projectDir . '/' . $candidate;
            break;
         }
      }
      $meta = $autobootFile !== null
         ? $this->get($autobootFile, $folder)
         : ['name' => $folder, 'description' => '', 'version' => '', 'author' => ''];

      // @ Detect interfaces
      $interfaces = [];
      if (is_file($projectDir . '/CLI.project.php') || is_file($projectDir . '/Console.project.php')) {
         $interfaces[] = 'CLI';
      }
      if (is_file($projectDir . '/WPI.project.php') || is_file($projectDir . '/Web.project.php')) {
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

      if ( empty($arguments) ) {
         $Output->write(PHP_EOL);

         $content = '';
         foreach ($this->arguments as $name => $value) {
            /** @var string $description */
            $description = is_array($value) ? ($value['description'] ?? '') : $value; // @phpstan-ignore function.alreadyNarrowedType, cast.string
            $content .= '@#Green:' . str_pad($name, 12) . ' @; ' . $description . PHP_EOL;
         }
         $content = rtrim($content);

         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Project arguments @;';
         $Fieldset->content = $content;
         $Fieldset->render();
      }
      else if ( count($arguments) > 1 ) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Too many arguments!';
         $Alert->render();
      }
      else {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid argument: @#cyan:{$arguments[0]}@;.";
         $Alert->render();
      }

      $output .= '@.;';

      $Output->render($output);

      return true;
   }
}
