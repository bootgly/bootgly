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
use const BOOTGLY_TTY;
use const BOOTGLY_WORKING_DIR;
use const GLOB_ONLYDIR;
use const LOCK_EX;
use const LOCK_NB;
use const LOCK_UN;
use const PHP_EOL;
use const SIGCONT;
use const SIGKILL;
use const SIGSTOP;
use const SIGTERM;
use const SIGUSR2;
use const STR_PAD_LEFT;
use function array_filter;
use function array_key_exists;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_merge;
use function array_slice;
use function array_values;
use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function fgets;
use function file_get_contents;
use function function_exists;
use function getmypid;
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
use function is_resource;
use function is_string;
use function json_encode;
use function ltrim;
use function max;
use function microtime;
use function min;
use function posix_get_last_error;
use function posix_getpid;
use function posix_getuid;
use function posix_kill;
use function posix_strerror;
use function preg_match;
use function proc_close;
use function proc_open;
use function putenv;
use function realpath;
use function register_shutdown_function;
use function rmdir;
use function rtrim;
use function scandir;
use function shell_exec;
use function str_contains;
use function str_pad;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtok;
use function strtolower;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function time;
use function trim;
use function unlink;
use function usleep;
use Exception;
use Throwable;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\CLI;
use Bootgly\ABI\Code\__String;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\States;
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
use Bootgly\CLI\UI\Components\Select;
use Bootgly\CLI\UI\Components\Textbox;
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
   /** The wizard Validator and the non-interactive create enforce the same port rule: 1–65535, no leading zeros. */
   private const string PORT_PATTERN = '#^(?:[1-9]\d{0,3}|[1-5]\d{4}|6[0-4]\d{3}|65[0-4]\d{2}|655[0-2]\d|6553[0-5])$#';
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
            '<url>'  => 'Repository URL with a *.Project.php signature at its root',
            '[name]' => 'Project path to import as (defaults to the repository name)'
         ]
      ],
      'boot' => [
         'description' => 'Boot a project — initialize its own resources (create runs this as a hook)',
         'arguments'   => [
            '<name>' => 'Project path to boot (e.g. App or Blog)'
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
   ];
   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      'Preview seed run without executing SQL' => ['--dry-run'],
      'Platforms to set up — all of them on first run (create/import)' => ['--platform=console', '--platform=web', '--platform=console,web', '--platform=none'],
      'Creation source: from scratch or a platform project' => ['--from=scratch', '--from=<source>'],
      'Interface bound to the new project (create/import)' => ['--interfaces=CLI', '--interfaces=WPI'],
      'New project metadata (create)' => ['--description=', '--version=', '--author=', '--port='],
      'Skip confirmations (create/import)' => ['--yes'],
      'Do not create a git repository for the new project (create)' => ['--no-git'],
      'Replace an existing project, git history included (create --from)' => ['--refresh'],
      'Keep following new records — logs (unrelated to `start -f`)' => ['-f', '--follow'],
      'Log filters and output shape (logs)' => ['--instance=<id>', '--channel=<channel>', '--level=<level>', '--since=<time>', '--json'],
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
         'boot'    => $this->boot(
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
         'logs'    => $this->logs(
            array_slice($arguments, 1),
            $options
         ),

         default   => $this->help($arguments)
      };
   }

   // # Subcommands
   /**
    * Refuse an option this subcommand does not implement.
    *
    * The parser accepts any `--flag` (`CLI/Commands/Arguments.php`) and a
    * command's option table only renders help, so an inapplicable flag used to
    * be taken and silently dropped: `--dry-run` — this command's SEEDER flag —
    * made `project create` write the project for real while the caller read the
    * run as a preview, and the create that followed was then refused for a name
    * the preview had consumed. Naming where the flag does apply keeps the
    * refusal actionable instead of merely loud.
    *
    * @param array<int,string> $accepted
    * @param array<string,bool|int|string> $options
    */
   private function admit (array $accepted, array $options): bool
   {
      // ! The global flags every command carries are always admitted.
      $accepted = [...$accepted, 'help', 'h', 'v'];

      foreach ($options as $option => $value) {
         $option = (string) $option;

         if (in_array($option, $accepted, true) === true) {
            continue;
         }

         // ! Where the flag DOES apply, when this command declares it elsewhere.
         $applies = '';
         foreach ($this->options as $description => $flags) {
            foreach ($flags as $flag) {
               if (ltrim((string) strtok($flag, '='), '-') === $option) {
                  $applies = $description;

                  break 2;
               }
            }
         }

         $Output = CLI->Terminal->Output;

         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Unknown option @#cyan:--{$option}@; for this command.";
         $Alert->render();

         // ! The Alert clips a long line, so the actionable half gets its own —
         //   knowing the flag is refused is loud, knowing where it applies is
         //   what lets the caller fix the command.
         if ($applies !== '') {
            $Output->render("@#Green:Note:@; @#Blue:--{$option}@; is: {$applies}.@.;");
         }

         return false;
      }

      return true;
   }

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

      // ? Refuse before anything is written — a flag this command does not
      //   implement must never be accepted and dropped.
      if (
         $this->admit(
            ['platform', 'from', 'interfaces', 'description', 'version', 'author', 'port', 'yes', 'no-git', 'refresh'],
            $options
         ) === false
      ) {
         return false;
      }

      // ! Inputs
      $path = $arguments[0] ?? null;
      $from = isSet($options['from']) && is_string($options['from']) ? $options['from'] : null;

      // @ Wizard on interactive terminals (unless --yes) — kit setup is its first step
      if (BOOTGLY_TTY === true && isSet($options['yes']) === false) {
         return $this->wizard($path, $from, $options);
      }

      // @ Kit setup (platform submodules + resource dirs) when needed. The target
      //   is reserved: an example stocked by this very run must never take the
      //   name the user just asked for, and then refuse the command that stocked it
      if ($this->prepare($options, $path) === false) {
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
               . '[--version=] [--author=] [--yes]@;';
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

         // ? Port validity — the same rule the wizard Validator enforces;
         //   `(int) 'not-a-port'` would otherwise bind the server on port 0
         $port = (string) ($options['port'] ?? '8080');
         if (preg_match(self::PORT_PATTERN, $port) !== 1) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Invalid port: @#cyan:{$port}@;. Use a number between 1 and 65535.";
            $Alert->render();

            return false;
         }

         // ? Control characters — generate() refuses them too, but its refusal
         //   is generic; here the caller learns which flag was rejected
         foreach (['description', 'version', 'author'] as $field) {
            if (preg_match('#[\x00-\x1F]#', (string) ($options[$field] ?? '')) === 1) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = "Invalid @#cyan:--{$field}@;: control characters are not allowed.";
               $Alert->render();

               return false;
            }
         }

         $done = Projects::generate(
            [
               BOOTGLY_ROOT_DIR . "Bootgly/commands/stubs/{$interface}",
               BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/project',
            ],
            $path,
            [
               'interfaces'  => [$interface],
               'name'        => basename($path),
               'description' => (string) ($options['description'] ?? ''),
               'version'     => (string) ($options['version'] ?? '1.0.0'),
               'author'      => (string) ($options['author'] ?? ''),
               'port'        => $port,
            ]
         );
         // ?
         if ($done === true) {
            $this->track(Projects::CONSUMER_DIR, $path, $options);
         }

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
      // ? Name — the naming half of assess(); a collision is what a refresh
      //   replaces, so the other half does not apply here
      $result = $this->screen($path);
      if ($result !== true) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $result;
         $Alert->render();

         return false;
      }
      // ? The refresh replaces a project copy — never a group of projects or a
      //   tree the user made by hand, which carry no signature at their root
      $existing = Projects::CONSUMER_DIR . $path;
      if (is_dir($existing) === true && (glob("{$existing}/*.Project.php") ?: []) === []) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project directory @#cyan:projects/{$path}@; exists and carries no "
            . 'project signature — it is not replaced.';
         $Alert->render();

         return false;
      }

      $source = $this->trace($from);
      // ?
      if ($source === null) {
         $message = "Source project @#cyan:{$from}@; not found in the platform folders.";
         // ? Platforms not initialized in the kit are invisible to trace()
         if (
            BOOTGLY_ROOT_DIR !== BOOTGLY_WORKING_DIR
            && (
               is_file(BOOTGLY_WORKING_DIR . 'Console/' . BOOTSTRAP_FILENAME) === false
               || is_file(BOOTGLY_WORKING_DIR . 'Web/' . BOOTSTRAP_FILENAME) === false
            )
         ) {
            $message .= ' Initialize a platform with @#cyan:--platform=console|web@;.';
         }

         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $message;
         $Alert->render();

         return false;
      }

      $interfaces = $this->detect($source)
         ?? [strtoupper((string) ($options['interfaces'] ?? 'WPI'))];

      // ? A refresh replaces the whole copy, and a repository inside it is the
      //   user's only copy of that history — so replacing it is asked for,
      //   never assumed: a warning would arrive after the decision was made
      if (is_dir("{$existing}/.git") === true && isSet($options['refresh']) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Project @#cyan:projects/{$path}@; is a git repository — replacing it "
            . 'destroys its history. Pass @#cyan:--refresh@; to replace it, or remove the '
            . 'directory yourself.';
         $Alert->render();

         return false;
      }

      // @ User-level copies overwrite the platform ones on load — an existing
      //   one is refreshed, and import() keeps it until the new copy is
      //   complete. Platform copies arrive UNBOOTED (no repository of their
      //   own) — adopt one with `bootgly project <Name> boot`.
      $done = Projects::import($source, $path, [
         'interfaces' => $interfaces,
      ], refresh: true);

      return $this->report($done, $path);
   }

   /**
    * Import projects — from the Platforms or from a git repository URL.
    *
    * With a URL argument, imports the repository directly (it must carry the
    * Bootgly project signature — a `*.Project.php` file at its root). Without
    * one, interactive terminals choose the import source: the Platforms
    * (pick, confirm, transfer) or a Git remote (asks the URL).
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function import (array $arguments = [], array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Refuse before anything is written, as create() does.
      if ($this->admit(['platform', 'interfaces', 'yes'], $options) === false) {
         return false;
      }

      // ? No URL — interactive terminals choose the import source
      $url = $arguments[0] ?? null;
      if ($url === null || $url === '') {
         if (BOOTGLY_TTY === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = 'Missing repository URL. Usage: @#cyan:bootgly project import <url> [Name]@;';
            $Alert->render();

            return false;
         }

         // # Git remote — the one interactive source (the shipped platform
         //   projects are imported automatically when the kit is prepared):
         //   ask the URL and continue with the direct flow
         $Textbox = new Textbox(CLI->Terminal->Input, $Output);
         $Textbox->prompt = 'Repository URL (git)';
         $Textbox->required = true;
         $url = $Textbox->ask();
         // ?
         if ($url === '') {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = 'A repository URL is required.';
            $Alert->render();

            return false;
         }
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
      $repository = escapeshellarg($url);
      $target = escapeshellarg($tmp);

      $status = $this->execute("git clone {$repository} {$target}");
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
      $signatures = glob("{$tmp}/*.Project.php") ?: [];
      if ($signatures === []) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Not a Bootgly project: no @#cyan:*.Project.php@; signature file at the repository root.';
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

      // @ Import — VCS metadata KEPT: the copy stays a working clone, with
      //   its history and its `origin`, so the user keeps committing and
      //   pushing from `projects/` — the project is the unit of versioning
      $done = Projects::import($tmp, $path, [
         'interfaces' => [$interface],
      ]);

      $this->erase($tmp);

      // ? The signature rename (source leaf -> target leaf) is a real,
      //   uncommitted change inside the clone — the user's to review
      $leaf = basename($signatures[0], '.Project.php');
      if ($done === true && $leaf !== basename($path)) {
         $Output->render(
            "@#yellow:Note:@; the project signature was renamed for the target path — "
            . "review and commit it inside @#cyan:projects/{$path}@;.@.;"
         );
      }

      // :
      return $this->report($done, $path);
   }

   /**
    * Boot a project — initialize the resources a project of the user's own
    * carries. Today that is version control (a git repository of its own,
    * with the current tree as the initial commit); more responsibilities
    * will land here as the project lifecycle grows.
    *
    * `create` runs the same hook for every project the user creates from
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
    * List all discovered projects with their descriptions.
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
      /** @var array<string, array{description: string}> $all */
      $all = [];
      foreach (Projects::read() as $folder => $entry) {
         $meta = $projects_CLI[$folder] ?? $projects_WPI[$folder] ?? null;
         if ($meta === null) {
            continue;
         }

         $all[$folder] = [
            'description' => $meta['description'],
         ];
      }

      if (empty($all)) {
         $Output->render('@.;@#red: No projects found. @; @.;');
         return true;
      }

      // ! Inner width — fit the terminal, keep the box readable
      $width = isSet(Terminal::$width) === true
         ? min(max(Terminal::$width - 6, 40), 100)
         : 80;

      // ! Index gutter — right-aligned indexes keep names and descriptions
      //   aligned past #9
      $count = count($all);
      $gutter = strlen((string) $count) + 1;
      $indent = str_repeat(' ', $gutter + 1);

      // @ One row per project — folder and wrapped description
      $index = 1;
      $rows = [];
      foreach ($all as $folder => $info) {
         // ? Right-align outside the markup token — it swallows adjacent spaces
         $number = "#{$index}";
         $aligned = str_repeat(' ', max(0, $gutter - strlen($number)));

         $row = "{$aligned}@#Magenta:{$number}@; @#Yellow:{$folder}@;";

         if ($info['description'] !== '') {
            // @phpstan-ignore-next-line -- wrap() resolves via __callStatic (pad precedent)
            foreach (explode("\n", (string) __String::wrap($info['description'], $width - $gutter - 1)) as $piece) {
               $row .= "\n{$indent}{$piece}";
            }
         }

         $rows[] = $row;
         $index++;
      }

      $Fieldset = new Fieldset($Output);
      $Fieldset->width = $width;
      $Fieldset->title = "@#Cyan: Projects ({$count}) @;";
      $Fieldset->content = implode("\n@---;\n", $rows);

      $Output->write(PHP_EOL);
      $Fieldset->render();
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
    * port is only the `--instance` tiebreaker when several instances are live.
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

   // @ Helpers
   /**
    * Confirm one destructive CLI action.
    */
   private function confirm (string $question, bool $default = false): bool
   {
      $Terminal = CLI->Terminal;

      $Textbox = new Textbox($Terminal->Input, $Terminal->Output);

      return $Textbox->confirm($question, default: $default);
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
      /** @var array<array{path: string, source: string, from?: string}> $imports */
      $imports = [];
      /** @var array<string> $transferred */
      $transferred = [];
      /** @var array{interfaces?: array<string>, name?: string, description?: string, version?: string, author?: string, port?: int|string} $meta */
      $meta = [];
      $interface = '';
      $url = '';
      $target = '';

      // ! The running build — an install screen must say WHICH code it is
      //   installing (every `dev-main` install reports the same version, so
      //   the commit is what tells two of them apart)
      $Build = Build::detect();

      $Wizard = new Wizard($Input, $Output);
      // ! The breathing space stays OUTSIDE the markup (it swallows adjacent ones)
      $Wizard->title = '@#Cyan: Bootgly — New project wizard @;'
         . " @#Black:{$Build->identify()}@;";

      // ! Branch steps — appended by the Mode handler once the branch is known
      // # From scratch: Path → Interface → Metadata → Confirm → Scaffold
      $scratch = function (Wizard $Wizard) use (&$path, &$meta, &$interface, &$options): void {
         $Wizard->add('Path', function (Wizard $Wizard) use (&$path): string {
            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Project path (e.g. `App` or `App/API`)';
            $Textbox->required = true;
            $Textbox->default = $path ?? '';
            $Textbox->Validator = fn (string $answer): true|string => $this->assess($answer);
            $path = $Textbox->ask();
            // ? EOF or invalid prefilled path
            if ($this->assess($path) !== true) {
               $Alert = new Alert($Wizard->Output);
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
         }, rows: 5);

         $Wizard->add('Metadata', function (Wizard $Wizard) use (&$path, &$meta, &$interface, &$options): null {
            // # Port (WPI)
            if ($interface === 'WPI') {
               $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
               $Textbox->prompt = 'Server port';
               $Textbox->default = (string) ($options['port'] ?? '8080');
               $Textbox->Validator = static function (string $answer): true|string {
                  // ?:
                  if (preg_match(self::PORT_PATTERN, $answer) !== 1) {
                     return 'Invalid port: use a number between 1 and 65535.';
                  }

                  // :
                  return true;
               };
               $meta['port'] = $Textbox->ask();
            }

            // # Description / Version / Author (options prefill the defaults)
            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Description';
            $Textbox->default = (string) ($options['description'] ?? '');
            $meta['description'] = $Textbox->ask();

            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Version';
            $Textbox->default = (string) ($options['version'] ?? '1.0.0');
            $meta['version'] = $Textbox->ask();

            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Author';
            $Textbox->default = (string) ($options['author'] ?? '');
            $meta['author'] = $Textbox->ask();

            $meta['name'] = basename((string) $path);

            // :
            return null;
         }, rows: 5);

         $Wizard->add('Confirm', function (Wizard $Wizard) use (&$path, &$meta, &$options): null {
            // ! Summary
            $content  = '@#Green:' . str_pad('Path', 12) . ' @; ' . $path . PHP_EOL;
            $content .= '@#Green:' . str_pad('Mode', 12) . ' @; From scratch' . PHP_EOL;
            $content .= '@#Green:' . str_pad('Interface', 12) . ' @; ' . implode(', ', $meta['interfaces'] ?? []);
            if (isSet($meta['port'])) {
               $content .= PHP_EOL . '@#Green:' . str_pad('Port', 12) . ' @; ' . $meta['port'];
            }
            $content .= PHP_EOL . '@#Green:' . str_pad('Description', 12) . ' @; ' . (($meta['description'] ?? '') ?: '(none)');
            $content .= PHP_EOL . '@#Green:' . str_pad('Version', 12) . ' @; ' . ($meta['version'] ?? '');
            $content .= PHP_EOL . '@#Green:' . str_pad('Author', 12) . ' @; ' . (($meta['author'] ?? '') ?: '(none)');

            $Wizard->Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Wizard->Output);
            $Fieldset->title = '@#Cyan: New project @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Wizard->Output->write(PHP_EOL);

            // ? Confirm
            if (isSet($options['yes']) === false) {
               $Textbox = new Textbox($Wizard->Input, $Wizard->Output);

               if ($Textbox->confirm('Create the project?', default: true) === false) {
                  $Alert = new Alert($Wizard->Output);
                  $Alert->Type::Attention->set();
                  $Alert->message = 'Aborted.';
                  $Alert->render();

                  throw new Exception('aborted');
               }
            }

            // :
            return null;
         }, rows: 13);

         $Wizard->add('Scaffold', function () use (&$path, &$meta, &$interface, &$options): string {
            $stub = $interface === 'WPI' ? 'WPI' : 'CLI';
            $done = Projects::generate(
               [
                  BOOTGLY_ROOT_DIR . "Bootgly/commands/stubs/{$stub}",
                  BOOTGLY_ROOT_DIR . 'Bootgly/commands/stubs/project',
               ],
               (string) $path,
               $meta
            );
            // ? The report renders the actionable failure Alert (permissions / registry)
            if ($done === false) {
               $this->report(false, (string) $path);

               throw new Exception('generation failed');
            }

            $this->track(Projects::CONSUMER_DIR, (string) $path, $options);

            // :
            return 'generated';
         });
      };

      // # From a platform source (--from=<source>): Confirm → Transfer
      $platforms = function (Wizard $Wizard) use (&$imports, &$transferred, &$options): void {
         $Wizard->add('Confirm', function (Wizard $Wizard) use (&$imports, &$options): null {
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

            $Wizard->Output->write(PHP_EOL);
            $Fieldset = new Fieldset($Wizard->Output);
            $Fieldset->title = '@#Cyan: Import projects @;';
            $Fieldset->content = $content;
            $Fieldset->render();
            $Wizard->Output->write(PHP_EOL);

            // ? Confirm
            if (isSet($options['yes']) === false) {
               $Textbox = new Textbox($Wizard->Input, $Wizard->Output);

               if ($Textbox->confirm('Import the selected projects?', default: true) === false) {
                  $Alert = new Alert($Wizard->Output);
                  $Alert->Type::Attention->set();
                  $Alert->message = 'Aborted.';
                  $Alert->render();

                  throw new Exception('aborted');
               }
            }

            // :
            return null;
         }, rows: 12);

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
      $git = function (Wizard $Wizard) use (&$url, &$target, &$options): void {
         $Wizard->add('URL', function (Wizard $Wizard) use (&$url): null {
            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Repository URL (git)';
            $Textbox->required = true;
            $url = $Textbox->ask();
            // ?
            if ($url === '') {
               $Alert = new Alert($Wizard->Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'A repository URL is required.';
               $Alert->render();

               throw new Exception('URL required');
            }

            // :
            return null;
         });

         $Wizard->add('Path', function (Wizard $Wizard) use (&$url, &$target): string {
            $default = basename($url, '.git');
            $Textbox = new Textbox($Wizard->Input, $Wizard->Output);
            $Textbox->prompt = 'Project path (e.g. `App` or `App/API`)';
            $Textbox->required = true;
            $Textbox->default = $this->assess($default) === true ? $default : '';
            $Textbox->Validator = fn (string $answer): true|string => $this->assess($answer);
            $target = $Textbox->ask();
            // ?
            if ($this->assess($target) !== true) {
               $Alert = new Alert($Wizard->Output);
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
         }, rows: 5);

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
         }, rows: 11);
      }

      // # Start mode — resolves the branch and appends its steps
      $Wizard->add('Mode', function (Wizard $Wizard)
         use (&$branch, &$imports, $path, $from, $scratch, $platforms, $git): string {
         // ? A source option picks the platform-import branch with no menu
         if ($from !== null && $from !== 'scratch') {
            $source = $this->trace($from);

            // ?
            if ($source === null) {
               $Alert = new Alert($Wizard->Output);
               $Alert->Type::Failure->set();
               $Alert->message = "Source project @#cyan:{$from}@; not found in the platform folders.";
               $Alert->render();

               throw new Exception('source not found');
            }

            // ! A `<Name>` argument names the target, exactly as it does on
            //   the non-interactive route; without one the source path is kept
            $imports[] = [
               'path'   => $path !== null && $path !== '' ? $path : $from,
               'source' => $source,
               'from'   => $from,
            ];

            $branch = 'platforms';
            $platforms($Wizard);

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

         // ! Start modes — the shipped platform projects are imported
         //   automatically when the kit is prepared, so the wizard only asks
         //   what only the user can answer. Creating nothing comes FIRST: a
         //   prepared kit already carries the whole set of guides, and reading
         //   one is how most people (and every AI agent) start.
         $modes = $this->offer();

         $mode = $this->choose('How do you want to start?', $modes);

         if ($mode === 1) {
            $branch = 'scratch';
            $scratch($Wizard);

            // :
            return 'scratch';
         }

         if ($mode === 2) {
            $branch = 'git';
            $git($Wizard);

            // :
            return 'git remote';
         }

         // ! Nothing to create — the imported projects are the starting point
         $branch = 'imported';

         // :
         return 'imported projects';
      }, rows: 6);

      // @ Run the flow
      $done = $Wizard->run();

      // ? Failure Alerts render at the failure site (handlers) — the final frame appends below them
      if ($done === false) {
         return false;
      }

      // : Closing report — rendered after the completion screen, so it stays visible
      if ($branch === 'git') {
         $this->summarize("Project {$target}", [
            'Path'       => "projects/{$target}/",
            'Interface'  => (string) ($options['interfaces'] ?? 'CLI'),
            'Source'     => $url,
            'History'    => 'kept — the clone carries its own origin',
         ]);

         return $this->report(true, $target);
      }

      if ($branch === 'imported') {
         // ! What the kit holds, by the platform that ships it. The names are
         //   the point — a count alone does not tell anyone what to open.
         $listed = 0;
         $rows = [];
         foreach ($this->gather() as $origin => $paths) {
            $listed += count($paths);

            // ! One row per origin, kept narrow by dropping whole names —
            //   a path cut in half names no project anyone can open
            $named = [];
            $width = 0;
            $hidden = 0;
            foreach ($paths as $name) {
               if ($named !== [] && $width + strlen($name) + 2 > 52) {
                  $hidden++;

                  continue;
               }

               $named[] = $name;
               $width += strlen($name) + 2;
            }

            $listing = implode(', ', $named);
            if ($hidden > 0) {
               $listing .= " … +{$hidden}";
            }

            $rows[$origin] = str_pad((string) count($paths), 3, ' ', STR_PAD_LEFT) . '  ' . $listing;
         }

         $this->summarize("Imported projects ({$listed})", $rows);

         $prefix = shell_exec('command -v bootgly 2>/dev/null') ? '' : 'php ';

         $Output->render(
            "@#Green:Tip:@; Use @#Blue:{$prefix}bootgly project list@; to see them all.@.;"
         );
         $Output->render(
            "@#Green:Tip:@; Use @#Blue:{$prefix}bootgly project <Name> start@; to boot one.@.;"
         );
         $Output->render(
            "@#Green:Tip:@; Run @#Blue:cd projects/<Name> && {$prefix}bootgly test@; to run its suites.@.;"
         );
         $Output->write(PHP_EOL);

         // :
         return true;
      }

      if ($branch === 'platforms') {
         foreach ($transferred as $index => $imported) {
            $Alert = new Alert($Output);
            $Alert->spaced = $index === 0;
            $Alert->Type::Success->set();
            $Alert->message = "Project @#cyan:{$imported}@; imported!";
            $Alert->render();
         }

         $this->advise($transferred);

         return true;
      }

      $this->summarize("Project {$path}", [
         'Path'        => "projects/{$path}/",
         'Interface'   => implode(', ', $meta['interfaces'] ?? []),
         'Port'        => (string) ($meta['port'] ?? ''),
         'Description' => (string) ($meta['description'] ?? ''),
         'Version'     => (string) ($meta['version'] ?? ''),
         'Author'      => (string) ($meta['author'] ?? ''),
         'Repository'  => is_dir(Projects::CONSUMER_DIR . "{$path}/.git") === true
            ? 'its own, with the scaffold as the first commit'
            : '',
      ]);

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
    * @param array<array{path: string, source: string, from?: string}> $imports
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

         // ? Name — import() refuses it anyway; refusing here names the reason
         $result = $this->screen($path);
         if ($result !== true) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = $result;
            $Alert->render();

            continue;
         }

         // ? A refresh replaces the whole copy — committed history inside it
         //   dies with the directory, and that is worth a line before it does
         if (is_dir(Projects::CONSUMER_DIR . "{$path}/.git") === true) {
            $Alert = new Alert($Output);
            $Alert->Type::Attention->set();
            $Alert->message = "Refreshing @#cyan:projects/{$path}@; replaces it, git history included.";
            $Alert->render();
         }

         // @ User-level copies overwrite the platform ones on load — an
         //   existing one is refreshed, and import() keeps it until the new
         //   copy is complete (a project that is its own source, as in the
         //   framework checkout, is left in place)
         $imported = Projects::import($import['source'], $path, [
            'interfaces' => $this->detect($import['source']) ?? ['CLI'],
         ], refresh: true);

         // ? Failures render at the failure site — the wizard keeps them on screen
         if ($imported === false) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Could not import project @#cyan:{$path}@;.";
            $Alert->render();

            continue;
         }

         $this->remind($path);
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
   private function prepare (array $options, null|string $reserve = null): bool
   {
      // ? Framework repo: nothing to prepare
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
         return true;
      }

      $Output = CLI->Terminal->Output;

      // # Platform submodules (kit)
      $initialized = [];
      $gitmodules = is_file(BOOTGLY_WORKING_DIR . '.gitmodules');
      $console = is_file(BOOTGLY_WORKING_DIR . 'Console/' . BOOTSTRAP_FILENAME);
      $web = is_file(BOOTGLY_WORKING_DIR . 'Web/' . BOOTSTRAP_FILENAME);

      if ($gitmodules === true) {
         // ! Requested platforms (comma-separated: --platform=console,web)
         $platforms = null;
         if (isSet($options['platform']) && is_string($options['platform'])) {
            $platforms = array_filter(explode(',', strtolower($options['platform'])));

            // ? `none` keeps the base platform only (no extra submodules)
            if ($platforms === ['none']) {
               $platforms = [];
            }

            // ?
            foreach ($platforms as $platform) {
               if ($platform !== 'console' && $platform !== 'web') {
                  $Alert = new Alert($Output);
                  $Alert->Type::Failure->set();
                  $Alert->message = "Invalid platform: @#cyan:{$platform}@;. "
                     . 'Use console, web, console,web or none.';
                  $Alert->render();

                  return false;
               }
            }
         }

         // ? Fresh kit (no resources booted yet): EVERY platform is set up.
         //   The kit is a guide before it is a workspace — it carries the whole
         //   shelf so the user (or an AI agent reading it) can decide later what
         //   to keep, and adding a platform in the future costs nobody a choice
         //   at install time. A later run only ever sets up what `--platform=`
         //   names: a platform the user removed stays removed.
         $fresh = is_file(BOOTGLY_WORKING_DIR . 'projects/Bootgly.projects.php') === false;
         if ($fresh === true && $platforms === null) {
            $platforms = [];

            if ($console === false) {
               $platforms[] = 'console';
            }
            if ($web === false) {
               $platforms[] = 'web';
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

            $working = escapeshellarg(BOOTGLY_WORKING_DIR);

            $status = $this->execute("git -C {$working} submodule update --init {$modules}");

            // ?
            if ($status !== 0) {
               $Alert = new Alert($Output);
               $Alert->Type::Failure->set();
               $Alert->message = 'Could not initialize the platform submodules. Run manually: '
                  . "@#cyan:git submodule update --init {$modules}@;";
               $Alert->render();

               return false;
            }

            // @ Land each platform on a real release: a gitlink left on an
            //   untagged commit ships whatever the kit last recorded, which in
            //   the worst case is an unreleased development build
            foreach ($targets as $target) {
               $release = $this->align(BOOTGLY_WORKING_DIR, $target);

               // ?
               if ($release !== null) {
                  // ! `@` is this Output's markup introducer and is a legal
                  //   character in a tag name — stripping it keeps a careless
                  //   tag from injecting resets into the row. The row is also
                  //   kept short on purpose: a long one hard-wraps at the
                  //   terminal edge and escapes the Wizard's region gutter.
                  $named = str_replace('@', '', $release);

                  $Output->render(
                     "@#yellow:{$target}@;: pinned past a release — "
                     . "using @#cyan:{$named}@;.@.;"
                  );
               }
            }

            $initialized = $targets;
         }

         // @@ An explicit `--platform=` asks for that platform's examples too.
         //   Without this, a platform initialized any other way (by hand, as
         //   the framework's own messages suggest, or by an earlier run) can
         //   never stock them: no other command does, and `$targets` is empty
         //   the moment the folder exists.
         foreach ($platforms as $platform) {
            $folder = $platform === 'console' ? 'Console' : 'Web';

            if (
               is_file(BOOTGLY_WORKING_DIR . "{$folder}/" . BOOTSTRAP_FILENAME) === true
               && in_array($folder, $initialized, true) === false
            ) {
               $initialized[] = $folder;
            }
         }
      }

      // # Resource directories
      $fresh = is_file(BOOTGLY_WORKING_DIR . 'projects/Bootgly.projects.php') === false;
      if ($fresh === true) {
         $Boot = new BootCommand;

         if ($Boot->run([], ['resources' => true]) === false) {
            return false;
         }
      }

      // # Shipped example projects — the kit's living guides
      $this->stock($fresh, $initialized, $reserve);

      // :
      return true;
   }

   /**
    * Stock the kit with the shipped platform projects.
    *
    * The Demos, the Console games and the Web examples are not optional: a
    * fresh kit carries them as living guides — for people and for AI agents
    * alike. One-shot by trigger: the whole exportable set on the kit's FIRST
    * boot, and a platform's set when that platform is initialized — never on
    * ordinary runs, so an example the user deleted stays deleted.
    *
    * @param bool $fresh First boot — no project registry yet.
    * @param array<string> $initialized Platform folders initialized this run (e.g. `Console`).
    *
    * @return void
    */
   private function stock (bool $fresh, array $initialized, null|string $reserve = null): void
   {
      // ?
      if ($fresh === false && $initialized === []) {
         return;
      }

      // ! The exportable platform projects, filtered to this run's triggers
      $imports = [];
      foreach ($this->survey() as $import) {
         // ? A fresh boot takes everything; a platform init takes its own
         if ($fresh === false) {
            $granted = false;
            foreach ($initialized as $platform) {
               if (str_starts_with($import['source'], BOOTGLY_WORKING_DIR . "{$platform}/") === true) {
                  $granted = true;

                  break;
               }
            }
            // ?
            if ($granted === false) {
               continue;
            }
         }
         // ? Only ever the MISSING ones — an existing copy is the user's
         if (is_dir(Projects::CONSUMER_DIR . $import['path']) === true) {
            continue;
         }
         // ? The path this run is creating belongs to the user: an example
         //   must never claim it and refuse the very command that stocked it
         if ($reserve !== null && $import['path'] === $reserve) {
            continue;
         }

         $imports[] = $import;
      }
      // ?
      if ($imports === []) {
         return;
      }

      $Output = CLI->Terminal->Output;
      $count = count($imports);
      $Output->render("@.;@#green:Importing@; @#cyan:{$count}@; shipped example projects...@.;");

      // @ The regular transfer: screened, staged and registered. Examples
      //   arrive UNBOOTED — no repository of their own — and are adopted
      //   with `bootgly project <Name> boot`.
      $this->transfer($imports);
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
    * Move a platform submodule onto the newest release reachable from the
    * commit the kit pinned — a stable tag when there is one, the newest
    * pre-release otherwise.
    *
    * `--merged` is what makes this safe: the correction can only ever walk
    * BACKWARDS, so a project never pairs a platform with a kit that was built
    * against something else.
    *
    * Ordering is git's own version sort with `versionsort.suffix` correcting
    * it: bare `-v:refname` ranks `v1.0.0` BELOW `v1.0.0-beta.1`, and declaring
    * `-` a pre-release suffix restores SemVer precedence in ONE pass
    * (`v1.1.0-beta.1` > `v1.0.0` > `v1.0.0-rc.1` > `v1.0.0-beta.9`). Preferring
    * every stable over every pre-release instead reads like "prefer stable" but
    * downgrades a kit pinned at `v1.1.0-beta.1` all the way back to `v1.0.0` —
    * and moves a pin that was already sitting exactly on a release.
    *
    * `--no-column` because `column.ui = always` in a user's config makes
    * `git tag` emit space-padded columns even into a pipe, which no anchored
    * pattern can match — the correction would then silently never fire.
    *
    * Mirrored in POSIX sh by `release ()` in the installer served at
    * `bootgly.com/install`, which does the same for `Bootgly`. The duplication
    * is structural: the installer runs before any PHP can, because the
    * framework that PHP would load IS that submodule. Keep both in step.
    *
    * @param string $working The kit directory holding the submodule, with a trailing separator.
    * @param string $module  The submodule path in the kit, e.g. `Console`.
    *
    * @return null|string The release checked out, or null when nothing moved.
    */
   private function align (string $working, string $module): null|string
   {
      // ? A host with `shell_exec` disabled must lose the correction, not the
      //   install — `prepare()` had no dependency on it before this method
      if (function_exists('shell_exec') === false) {
         return null;
      }

      $root = escapeshellarg($working);
      $path = escapeshellarg($module);
      $sub = escapeshellarg($working . $module);

      // ? Only ever a submodule this run placed. Three things have to agree:
      //   the commit `git submodule update` actually followed (the INDEX), the
      //   commit the kit records (the HEAD tree), and where the submodule now
      //   stands. Staging a gitlink is how someone says "this is the commit I
      //   want", so any disagreement means the pin is not ours to correct.
      //   The width is 40 or 64 — a SHA-256 repository would otherwise capture
      //   40 of 64 characters and disable the guard silently.
      $pinned = trim((string) shell_exec("git -C {$root} ls-files -s {$path} 2>/dev/null"));
      if (preg_match('/^160000 ([0-9a-f]{40,64})/', $pinned, $matches) !== 1) {
         return null;
      }
      $committed = trim((string) shell_exec(
         "git -C {$root} rev-parse " . escapeshellarg("HEAD:{$module}") . ' 2>/dev/null'
      ));
      $head = trim((string) shell_exec("git -C {$sub} rev-parse HEAD 2>/dev/null"));
      if ($head === '' || $head !== $matches[1] || $head !== $committed) {
         return null;
      }

      // ? Nothing of the user's may be at risk: tracked edits, staged changes,
      //   or an untracked file the release's tree would overwrite — `diff`
      //   cannot see that last one, and `checkout` would refuse it loudly
      $clean = trim((string) shell_exec(
         "git -C {$sub} diff --quiet 2>/dev/null"
         . " && git -C {$sub} diff --cached --quiet 2>/dev/null"
         . " && test -z \"$(git -C {$sub} ls-files --others --exclude-standard 2>/dev/null)\""
         . ' && echo clean'
      ));
      if ($clean !== 'clean') {
         return null;
      }

      // ---

      // @ Newest release first, so the first entry shaped like one is the pick
      $tags = trim((string) shell_exec(
         "git -C {$sub} -c versionsort.suffix=- tag --no-column --merged {$head}"
         . ' --sort=-v:refname 2>/dev/null'
      ));

      $release = null;
      foreach (explode("\n", $tags) as $tag) {
         $tag = trim($tag);

         if (preg_match('/^v\d+\.\d+\.\d+(-|$)/', $tag) === 1) {
            $release = $tag;

            break;
         }
      }

      // ? Nothing released to land on
      if ($release === null) {
         return null;
      }

      // ! Fully-qualified `refs/tags/`: `rev-parse` resolves a bare name to the
      //   TAG but `checkout` resolves it to a BRANCH, so a branch sharing the
      //   tag's name would pass the guard below and then check out something
      //   else — possibly ahead of the pin, which this must never do
      $reference = escapeshellarg("refs/tags/{$release}");

      // ? Already standing on it — the normal case, and it must stay silent
      $target = trim((string) shell_exec(
         "git -C {$sub} rev-parse " . escapeshellarg("refs/tags/{$release}^{commit}")
         . ' 2>/dev/null'
      ));
      if ($target === '' || $target === $head) {
         return null;
      }

      // ---

      $status = $this->execute(
         "git -C {$sub} -c advice.detachedHead=false checkout --quiet {$reference}"
      );

      // :
      return $status === 0 ? $release : null;
   }

   /**
    * Runs a system command with its output nested in the current Output.
    *
    * A child process inherits the terminal and writes straight to it, escaping
    * the Wizard's nested region: its lines land without the guide column and
    * break the frame open. Piping the stream back and writing it line by line
    * keeps every row inside the region — the guide re-enters on each break and
    * the content area grows to fit, exactly like any other nested component.
    *
    * @param string $command The command line to run.
    *
    * @return int The command exit status (non-zero on failure).
    */
   private function execute (string $command): int
   {
      // ! Resolved at call time — inside a Wizard step this is the nested Region
      $Output = CLI->Terminal->Output;

      // ! One stream: git reports cloning and checkout notes on stderr, and
      //   both belong to the same visual flow. Piping also drops the progress
      //   bars by itself — git only paints them onto a terminal
      $Pipes = [];
      $Process = proc_open("{$command} 2>&1", [1 => ['pipe', 'w']], $Pipes);

      // ?
      if (is_resource($Process) === false) {
         return 1;
      }

      // @@ Line by line, as it arrives — carriage returns would drag the
      //   cursor out of the region column, so they never reach the Output
      while (($line = fgets($Pipes[1])) !== false) {
         $Output->write(str_replace("\r", '', rtrim($line, "\n")) . PHP_EOL);
      }

      fclose($Pipes[1]);

      // :
      return proc_close($Process);
   }

   /**
    * Screen a project path's name: the naming pattern and the reserved roots.
    *
    * The half of `assess()` every minting route needs BEFORE it touches the
    * disk — the refresh routes (`--from`, the platform import) run only this
    * half, since a collision is exactly what a refresh replaces.
    *
    * @param string $path
    *
    * @return true|string True when usable; an error message otherwise.
    */
   private function screen (string $path): true|string
   {
      // ? Naming pattern (the alphabet lives once, in the API layer)
      if (Projects::vet($path) === false) {
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
      // ? Name
      $result = $this->screen($path);
      if ($result !== true) {
         return $result;
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
      $signatures = glob("{$dir}/*.Project.php") ?: [];
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

         if (is_dir($dir) === true && (glob("{$dir}/*.Project.php") ?: []) !== []) {
            // :
            return $dir;
         }
      }

      // :
      return null;
   }

   /**
    * Detect the interfaces a shipped project is registered with, read from
    * the registry of the platform that ships it — the `projects/` directory
    * the source lives in — so a Web or Console example keeps its own binding
    * instead of the core's.
    *
    * @param null|string $source Absolute path of the source project directory
    *
    * @return null|array<string>
    */
   private function detect (null|string $source): null|array
   {
      // ?
      if ($source === null) {
         return null;
      }

      // ! The registry of the platform that ships the source
      $bases = [
         Projects::AUTHOR_DIR,
         BOOTGLY_WORKING_DIR . 'Console/projects/',
         BOOTGLY_WORKING_DIR . 'Web/projects/',
      ];
      $file = null;
      $key = null;
      foreach ($bases as $base) {
         if (str_starts_with($source, $base) === true) {
            $file = "{$base}Bootgly.projects.php";
            $key = rtrim(substr($source, strlen($base)), '/');

            break;
         }
      }
      // ?
      if ($file === null || is_file($file) === false) {
         return null;
      }

      $registry = include $file;
      if (is_array($registry) === false) {
         return null;
      }

      $meta = $registry[$key] ?? null;
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
    * Summarize a finished wizard run in a titled Fieldset.
    *
    * Rendered after the completion screen, where the full terminal width is
    * available again — the steps' own summaries live inside the wizard region
    * and have to stay narrow.
    *
    * @param string $title
    * @param array<string, string> $rows Label => value; an empty value is dropped.
    *
    * @return void
    */
   private function summarize (string $title, array $rows): void
   {
      $Output = CLI->Terminal->Output;

      // !
      $content = [];
      foreach ($rows as $label => $value) {
         // ? Nothing to say about it
         if ($value === '') {
            continue;
         }

         $content[] = '@#Green:' . str_pad($label, 12) . ' @; ' . $value;
      }

      // ?
      if ($content === []) {
         return;
      }

      // @
      $Output->write(PHP_EOL);

      $Fieldset = new Fieldset($Output);
      $Fieldset->title = "@#Cyan: {$title} @;";
      $Fieldset->content = implode(PHP_EOL, $content);
      $Fieldset->render();

      $Output->write(PHP_EOL);
   }

   /**
    * Gather the shipped projects the working directory HOLDS, by origin.
    *
    * The kit imports every exportable project when it is prepared, so what
    * the wizard reports is the shelf as it stands: an example the user
    * deleted is gone from it, and a platform source that was never imported
    * was never a project of theirs.
    *
    * @return array<string, array<string>> Origin (`Bootgly`, `Console`, `Web`) => project paths.
    */
   private function gather (): array
   {
      $Bootgly = [];
      $Console = [];
      $Web = [];

      // @@
      foreach ($this->survey() as $import) {
         // ? Only what the working directory holds
         if (is_dir(Projects::CONSUMER_DIR . $import['path']) === false) {
            continue;
         }

         $source = $import['source'];

         if (str_starts_with($source, BOOTGLY_WORKING_DIR . 'Console/') === true) {
            $Console[] = $import['path'];
         }
         else if (str_starts_with($source, BOOTGLY_WORKING_DIR . 'Web/') === true) {
            $Web[] = $import['path'];
         }
         else {
            $Bootgly[] = $import['path'];
         }
      }

      // :
      return array_filter([
         'Bootgly' => $Bootgly,
         'Console' => $Console,
         'Web'     => $Web,
      ]);
   }

   /**
    * Offer the start modes, in the order a new user meets them.
    *
    * The first mode creates nothing: a prepared kit already carries the
    * shipped platform projects, so standing pat — and reading one of them —
    * is a first-class way to start. The count is what the kit HOLDS, not what
    * it could hold: an example the user deleted stops being offered.
    *
    * @return array<string>
    */
   private function offer (): array
   {
      // @ Imported guides
      $imported = 0;
      foreach ($this->gather() as $paths) {
         $imported += count($paths);
      }

      // :
      return [
         "Only skip to imported from Platforms ({$imported} available)",
         'Create project from scratch',
         'Import project from Git remote',
      ];
   }

   /**
    * Choose one option from a vertical, single-selection Select.
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

      $Select = new Select($Terminal->Input, $Terminal->Output);
      $Select->title = "@#Cyan:{$prompt}@;\n@#Black:(↑/↓ to move, Space to select one, Enter to confirm)@;";

      // ! Pinned labels render first — always marked, locked out of the selection
      foreach ($pinned as $label) {
         $Select->locked[] = count($Select->options);
         $Select->options[] = (string) $label;
      }
      foreach ($labels as $label) {
         $Select->options[] = (string) $label;
      }

      // @@ Render until Enter
      foreach ($Select->selecting() as $ignored);

      // : Index relative to $labels (pinned options never enter the selection)
      $offset = count($pinned);

      return (int) ($Select->selected[0] ?? $default + $offset) - $offset;
   }

   /**
    * Remind the user of a previous copy a refresh could not remove.
    *
    * A refresh keeps the replaced copy beside the project until the new one
    * is registered, then removes it — whatever the process could not delete
    * (a read-only tree, a file it does not own) stays under a hidden name.
    *
    * @param string $path
    *
    * @return void
    */
   private function remind (string $path): void
   {
      $leaf = basename($path);
      $parent = dirname(Projects::CONSUMER_DIR . $path);

      // ?
      $leftovers = glob("{$parent}/.{$leaf}.backup*", GLOB_ONLYDIR) ?: [];
      if ($leftovers === []) {
         return;
      }

      $Output = CLI->Terminal->Output;
      foreach ($leftovers as $leftover) {
         $relative = substr($leftover, strlen(Projects::CONSUMER_DIR));
         $Output->render(
            "@#Yellow:Note:@; the previous copy could not be fully removed — what remains is at "
            . "@#Cyan:projects/{$relative}@;; delete it once you no longer need it.@.;"
         );
      }
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

         $this->remind($path);
         $this->advise([$path]);
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
    * Advise the next steps for ready projects: migrate and seed when the
    * first one ships database resources, then start.
    *
    * @param array<string> $paths The ready project paths.
    *
    * @return void
    */
   private function advise (array $paths): void
   {
      // ?
      $path = $paths[0] ?? '';
      if ($path === '') {
         return;
      }

      $Output = CLI->Terminal->Output;

      $prefix = shell_exec('command -v bootgly 2>/dev/null') ? '' : 'php ';

      // ! Database steps — only when the project ships the resources
      $database = Projects::CONSUMER_DIR . "{$path}/database/";

      $steps = [];
      if (is_dir("{$database}migrations") === true) {
         $steps[] = ['migrate up', 'create the database schema'];
      }
      if (is_dir("{$database}seeders") === true) {
         $steps[] = ['seed run', 'seed the database'];
      }
      $steps[] = ['start', 'boot it'];

      $Output->write(PHP_EOL);
      foreach ($steps as [$action, $goal]) {
         $Output->render("@#Green:Tip:@; Use @#Blue:{$prefix}bootgly project {$path} {$action}@; to {$goal}.@.;");
      }

      // ? Example tests — projects ship them as a writing guide
      if (is_dir(Projects::CONSUMER_DIR . "{$path}/tests") === true) {
         $Output->render("@#Green:Tip:@; Run @#Blue:cd projects/{$path} && {$prefix}bootgly test@; to run its suites.@.;");
      }

      $Output->write(PHP_EOL);
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
            '@#Green:Tip:@; Register it in @#Cyan:projects/Bootgly.projects.php@; or use @#Blue:bootgly project list@;.@..;'
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
            '@#Green:Tip:@; Use @#Blue:bootgly project list@; to see all available projects.@..;'
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
         $file = $projectsDir . $path . '/' . $leaf . '.Project.php';
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

      // @ The signature is read for its metadata only — never load the
      //   project's Composer autoloader here: `list`/`info` read EVERY
      //   project, and stacking N vendor bootstraps in one process makes a
      //   read-only listing run everyone's dependency code
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
      $projectFile = $projectDir . basename($folder) . '.Project.php';
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
         $exampleLines = '@#Blue:bootgly project create@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project create App/API --from=scratch --interfaces=WPI --yes@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project import https://github.com/foo/project1 Project1@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project list@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI start@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI stop@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI show@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI restart@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI info@;' . PHP_EOL;
         $exampleLines .= '@#Blue:bootgly project Demo/HTTP_Server_CLI logs -f@; @#Black:(follow live — unrelated to `start -f`)@;' . PHP_EOL;
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
            '@.;@#Green:Tip:@; Use @#Blue:bootgly project list@; to see all available projects.@.;'
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
