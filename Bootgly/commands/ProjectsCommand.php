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


use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_STORAGE_DIR;
use const BOOTGLY_TTY;
use const BOOTGLY_WORKING_DIR;
use const GLOB_ONLYDIR;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_EOL;
use const PHP_INT_MAX;
use const STR_PAD_LEFT;
use function array_filter;
use function array_intersect_key;
use function array_key_exists;
use function array_keys;
use function array_map;
use function array_shift;
use function array_slice;
use function array_sum;
use function array_values;
use function basename;
use function count;
use function dirname;
use function escapeshellarg;
use function explode;
use function fclose;
use function fgets;
use function file_exists;
use function filesize;
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
use function is_resource;
use function is_string;
use function json_encode;
use function max;
use function mb_strlen;
use function mb_substr;
use function posix_getuid;
use function preg_match;
use function proc_close;
use function proc_open;
use function rmdir;
use function rtrim;
use function scandir;
use function shell_exec;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function strtoupper;
use function substr;
use function sys_get_temp_dir;
use function time;
use function trim;
use function unlink;
use Exception;
use Throwable;

use const Bootgly\ABI\BOOTSTRAP_FILENAME;
use const Bootgly\CLI;
use Bootgly\ACI\Logs\Data\Record;
use Bootgly\ACI\Process\State;
use Bootgly\ACI\Process\States;
use Bootgly\API\Environment\Build;
use Bootgly\API\Projects;
use Bootgly\API\Projects\Project;
use Bootgly\CLI\Command;
use Bootgly\CLI\Terminal;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Select;
use Bootgly\CLI\UI\Components\Table;
use Bootgly\CLI\UI\Components\Textbox;
use Bootgly\CLI\UX\Components\Wizard;


/**
 * Manage the projects of this kit as a whole — the registry (`create`,
 * `import`, `list`) and what is running across it (`show`). Anything that
 * addresses ONE existing project by name lives in `project <Name> …`.
 */
class ProjectsCommand extends Command
{
   // * Config
   /** The wizard Validator and the non-interactive create enforce the same port rule: 1–65535, no leading zeros. */
   private const string PORT_PATTERN = '#^(?:[1-9]\d{0,3}|[1-5]\d{4}|6[0-4]\d{3}|65[0-4]\d{2}|655[0-2]\d|6553[0-5])$#D';
   /** Scratch-route defaults — `weigh()` judges these values and `create()` consumes them. */
   private const string INTERFACE = 'CLI';
   private const string PORT = '8080';
   /** The `show` columns — key to header, in display order. */
   private const array COLUMNS = [
      'project'  => 'Project',
      'instance' => 'Instance',
      'interface' => 'Interface',
      'status'   => 'Status',
      'master'   => 'Master',
      'workers'  => 'Workers',
      'uptime'   => 'Uptime',
      'address'  => 'Address',
      'tap'      => 'Tap'
   ];
   /** The `show` columns a narrow terminal gives up, first to last. */
   private const array EXPENDABLE = ['tap', 'workers', 'master', 'interface', 'address', 'uptime'];
   public bool $separate = true;
   public int $group = 2;

   // * Data
   // # Command
   public string $name = 'projects';
   public string $description = 'Manage the projects of this kit';
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
      'list' => [
         'description' => 'List all registered projects',
         'arguments'   => []
      ],
      'show' => [
         'description' => 'Show every running instance across projects (one line per instance)',
         'arguments'   => []
      ]
   ];
   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      'Platforms to set up — all of them on first run (create/import)' => ['--platform=console', '--platform=web', '--platform=console,web', '--platform=none'],
      'Creation source: from scratch or a platform project' => ['--from=scratch', '--from=<source>'],
      'Interface bound to the new project (create/import)' => ['--interfaces=CLI', '--interfaces=WPI'],
      'New project metadata (create)' => ['--description=', '--version=', '--author=', '--port='],
      'Skip confirmations (create/import)' => ['--yes'],
      'Do not create a git repository for the new project (create)' => ['--no-git'],
      'Replace an existing project, git history included (create --from)' => ['--refresh'],
      'Include the instances still on record but no longer running (show)' => ['--all'],
      'Machine output — one JSON document (show)' => ['--json'],
   ];


   /**
    * Run the command with the given arguments and options.
    *
    * @param array<string> $arguments
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function run (array $arguments = [], array $options = []): bool
   {
      return match ($arguments[0] ?? null) {
         'create' => $this->create(
            array_slice($arguments, 1),
            $options
         ),
         'import' => $this->import(
            array_slice($arguments, 1),
            $options
         ),
         'list'   => $this->list(),
         'show'   => $this->show($options),
         default  => $this->help($arguments)
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

      // ? `--platform` value — judged for BOTH routes, here, because the wizard
      //   only adds its Platforms step (and with it `prepare()`'s own call)
      //   when the working base is a kit. In a framework checkout an
      //   interactive run would otherwise drop an unimplemented value.
      if ($this->sift($options) === false) {
         return false;
      }

      // @ Wizard on interactive terminals (unless --yes) — kit setup is its first step
      if (BOOTGLY_TTY === true && isSet($options['yes']) === false) {
         return $this->wizard($path, $from, $options);
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
            $Alert->message = 'Missing project path. Usage: @#cyan:bootgly projects create <Name> '
               . '[--from=scratch|<source>] [--interfaces=CLI|WPI] [--port=] [--description=] '
               . '[--version=] [--author=] [--yes]@;';
            $Alert->render();

            return false;
         }
      }

      // ? Every refusal this command can issue WITHOUT a kit is issued here,
      //   ahead of the setup below: preparing a fresh kit lays down the
      //   resource directories, the shipped example projects and the registry
      //   that allow-lists them, and nothing rolls that back — a command that
      //   was never going to proceed must not build a workspace on its way out
      $result = $this->weigh($path, $from, $options);
      if ($result !== true) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = $result;
         $Alert->render();

         return false;
      }

      // ---

      // @ Kit setup (platform submodules + resource dirs) when needed. The target
      //   is reserved: an example stocked by this very run must never take the
      //   name the user just asked for, and then refuse the command that stocked it
      if ($this->prepare($options, $path) === false) {
         return false;
      }

      // @ From scratch
      if ($from === 'scratch') {
         // ? Collisions — the half of assess() the reservation exists for:
         //   only the prepared kit knows what is registered and on disk
         $result = $this->assess($path);
         if ($result !== true) {
            $Alert = new Alert($Output);
            $Alert->Type::Failure->set();
            $Alert->message = $result;
            $Alert->render();

            return false;
         }

         // ! Shape already judged by weigh()
         $interface = strtoupper((string) ($options['interfaces'] ?? self::INTERFACE));
         $port = (string) ($options['port'] ?? self::PORT);

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
            $this->boot($path, $options);
         }

         return $this->report($done, $path);
      }

      // @ From a platform project
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
         $message = "Source project @#cyan:" . $this->clean($from) . "@; not found in the platform folders.";
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
            $Alert->message = 'Missing repository URL. Usage: @#cyan:bootgly projects import <url> [Name]@;';
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

      // ! Target project path
      $path = $arguments[1] ?? basename($url, '.git');

      // ? SHAPE only — a malformed or reserved path needs no kit to be refused,
      //   so it is judged before the bootstrap and before the clone. The
      //   COLLISION half of assess() stays below `prepare()` on purpose: a name
      //   is only taken once the kit has been stocked, and hoisting that half
      //   here would turn a shipped example into a reservation this command
      //   then abandons whenever the clone fails or the user answers `n`.
      $result = $this->screen($path);
      if ($result === true && Projects::check($path) === false) {
         $result = "Invalid project path: @#cyan:" . $this->clean($path) . "@;.";
      }
      if ($result !== true) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "{$result} Pass the target path explicitly: "
            . '@#cyan:bootgly projects import <url> <Name>@;';
         $Alert->render();

         return false;
      }

      // ! Interface — judged here, so a value this command does not implement
      //   costs neither a kit bootstrap nor a clone
      $interface = strtoupper((string) ($options['interfaces'] ?? 'WPI'));
      // ?
      if ($interface !== 'CLI' && $interface !== 'WPI') {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid interface: @#cyan:" . $this->clean($interface) . "@;. Use CLI or WPI.";
         $Alert->render();

         return false;
      }

      // ---

      // @ Kit setup (platform submodules + resource dirs) when needed. NO
      //   reservation here, unlike create(): an import can still fail on the
      //   clone or be declined at the confirm, and a reserved name is a shipped
      //   example left off the shelf — permanently, because stock() is one-shot
      //   on a fresh kit. A collision with an example is a refusal, not a claim
      if ($this->prepare($options) === false) {
         return false;
      }

      // ? Collision — judged once the kit is stocked, so the shelf the answer
      //   depends on is the real one
      $result = $this->assess($path);
      if ($result !== true) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "{$result} Pass the target path explicitly: "
            . '@#cyan:bootgly projects import <url> <Name>@;';
         $Alert->render();

         return false;
      }

      // @ Fetch with the system git
      $tmp = sys_get_temp_dir() . '/bootgly-import-' . getmypid();
      $this->erase($tmp);

      // ! Scrubbed ONCE, for every site that displays it: `@` opens Output
      //   markup and a raw control byte reaches the terminal verbatim (OSC 52
      //   writes the clipboard, and a URL travels in READMEs and issues). The
      //   clone below uses `$url` itself, escaped for the shell.
      $shown = $this->clean($url);

      $Output->render("@#green:Fetching@; @#cyan:{$shown}@;@.;");
      $repository = escapeshellarg($url);
      $target = escapeshellarg($tmp);

      $status = $this->execute("git clone {$repository} {$target}");
      // ?
      if ($status !== 0 || is_dir($tmp) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Could not clone @#cyan:{$shown}@;.";
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

      // ! Summary
      $content  = '@#Green:' . str_pad('Mode', 12) . ' @; Import external repository' . PHP_EOL;
      $content .= '@#Green:' . str_pad('Source', 12) . ' @; ' . $shown . PHP_EOL;
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
    * List every registered project — one row per project, with the
    * interfaces the registry binds it to and the description its signature
    * carries. The description takes whatever width the terminal leaves after
    * the fixed columns, clipped rather than wrapped: a table row is one line.
    *
    * @return bool
    */
   public function list (): bool
   {
      $Output = CLI->Terminal->Output;

      // @ Discover per-interface metadata
      $projects_CLI = Projects::discover('CLI');
      $projects_WPI = Projects::discover('WPI');

      // @ Merge in registry order (kept alphabetical by path)
      /** @var array<string, array{interfaces: string, description: string}> $all */
      $all = [];
      foreach (Projects::read() as $folder => $entry) {
         $meta = $projects_CLI[$folder] ?? $projects_WPI[$folder] ?? null;
         if ($meta === null) {
            continue;
         }

         $all[$folder] = [
            'interfaces'  => implode(', ', $entry['interfaces'] ?? []),
            'description' => $meta['description']
         ];
      }

      // ?
      if ($all === []) {
         $Output->render('@.;@#red: No projects found. @; @.;');

         return true;
      }

      // ! The description column takes what the terminal leaves — the three
      //   fixed columns, plus the borders and the padding every cell carries.
      //   Without a terminal (a pipe, an agent) nothing is clipped.
      $count = count($all);
      $width = max($this->measure(), 60);
      $folders = max(strlen('Project'), ...array_map(mb_strlen(...), array_keys($all)));
      $interfaces = strlen('Interface');
      foreach ($all as $info) {
         $interfaces = max($interfaces, strlen($info['interfaces']));
      }
      $available = max(16, $width - 13 - strlen((string) $count) - $folders - $interfaces);

      // @ One row per project
      $body = [];
      $index = 1;
      foreach ($all as $folder => $info) {
         $body[] = [
            (string) $index,
            $folder,
            $info['interfaces'],
            $this->clip($info['description'], $available)
         ];
         $index++;
      }

      $Table = new Table($Output);
      $Table->Data->Header->set([['#', 'Project', 'Interface', 'Description']]);
      $Table->Data->Body->set($body);

      $Output->write(PHP_EOL);
      $Table->render();
      $Output->write(PHP_EOL);

      return true;
   }

   /**
    * Show every running instance across the registered projects — the `ps`
    * of this kit. One line per INSTANCE, never per project: a project may hold
    * several (one per bound port for servers, one per master PID for console
    * workers), and the instance is what an operator compares and addresses.
    *
    * Liveness is the instance lock, proven by `States::scan()`; the state
    * document is discovery data on top of it. `--all` adds what is still on
    * record but no longer authenticated (stopped); `--json` emits one
    * document with the same rows for scripts and agents.
    *
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   public function show (array $options = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? Refuse before anything is read, as the other subcommands do.
      if ($this->admit(['all', 'json'], $options) === false) {
         return false;
      }

      $all = isSet($options['all']);
      $json = isSet($options['json']);

      // @ One row per instance, in registry order
      $rows = [];
      foreach (array_keys(Projects::read()) as $path) {
         $path = (string) $path;
         $id = Projects::encode($path);

         // ! Live: authenticated masters only
         $instances = States::scan($id);
         foreach ($instances as $qualifier => $data) {
            $rows[] = $this->shape($path, $id, (string) $qualifier, $data, alive: true);
         }

         // ? Still on record: what the scan did not authenticate
         if ($all === false) {
            continue;
         }
         $files = glob(BOOTGLY_STORAGE_DIR . "pids/{$id}.*.json") ?: [];
         if (is_file(BOOTGLY_STORAGE_DIR . "pids/{$id}.json") === true) {
            $files[] = BOOTGLY_STORAGE_DIR . "pids/{$id}.json";
         }
         foreach ($files as $file) {
            $basename = basename($file, '.json');
            $qualifier = $basename === $id ? '' : substr($basename, strlen($id) + 1);
            if (array_key_exists($qualifier, $instances) === true) {
               continue;
            }
            // ! A tombstone (empty document) has nothing left to show but its
            //   qualifier; a stale document keeps what its master last wrote
            try {
               $data = new State($id, $qualifier !== '' ? $qualifier : null)->read();
            }
            catch (Throwable) {
               $data = null;
            }
            $rows[] = $this->shape($path, $id, $qualifier, $data, alive: false);
         }
      }

      // ?: Machine output — the same rows, one document
      if ($json === true) {
         $Output->write(json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL);

         return true;
      }

      // ?
      if ($rows === []) {
         $Output->render('@.;@#yellow: No running instance found. @;@.;');
         $this->hint();

         return true;
      }

      // @ Cells — raw text (the Table pads bytes, not markup)
      $cells = [];
      foreach ($rows as $row) {
         $cells[] = [
            'project'  => $row['project'],
            'instance' => $row['instance'] ?? '-',
            'interface' => $row['interface'],
            'status'   => $row['status'],
            'master'   => $row['master'] === null ? '-' : (string) $row['master'],
            'workers'  => $row['workers'] === null ? '-' : (string) $row['workers'],
            'uptime'   => $row['uptime'] === null ? '-' : $this->elapse($row['uptime']),
            'address'  => $row['address'] ?? '-',
            'tap'      => $row['tap'] === true ? 'yes' : '-'
         ];
      }

      // ! A narrow terminal gives up the secondary columns first, as `pm2 ps` does
      $columns = $this->fit(self::COLUMNS, $cells, $this->measure());
      $body = [];
      foreach ($cells as $cell) {
         $body[] = array_values(array_intersect_key($cell, $columns));
      }

      $Table = new Table($Output);
      $Table->Data->Header->set([array_values($columns)]);
      $Table->Data->Body->set($body);

      $Output->write(PHP_EOL);
      $Table->render();
      $Output->write(PHP_EOL);

      return true;
   }

   // @ Helpers
   /**
    * Shape one instance into the row `show` prints and emits.
    *
    * @param string $path Canonical project path (the registry key).
    * @param string $id The encoded registry id of that path.
    * @param string $qualifier Instance qualifier — a port (servers) or a master PID (console); `''` for the legacy unqualified file.
    * @param null|array<string,mixed> $data The state document, or null for a tombstone.
    * @param bool $alive Whether `States::scan()` authenticated this instance's master.
    *
    * @return array{project:string,instance:null|string,interface:string,status:string,master:null|int,workers:null|int,uptime:null|int,address:null|string,tap:bool}
    */
   private function shape (string $path, string $id, string $qualifier, null|array $data, bool $alive): array
   {
      $type = is_string($data['type'] ?? null) ? $data['type'] : '-';
      $master = is_int($data['master'] ?? null) ? $data['master'] : null;
      // ! Workers are a server topology — a console instance is its own process
      $workers = ($type === 'WPI' || $type === 'WPI-Client') && is_array($data['workers'] ?? null)
         ? count($data['workers'])
         : null;
      $started = is_int($data['started'] ?? null) ? $data['started'] : null;

      // ! The document's `status` is trusted only over a live master — a paused
      //   server still holds its lock, a stopped one wrote `Running` last
      $status = match (true) {
         $alive === false => 'stopped',
         ($data['status'] ?? '') === 'Paused' => 'paused',
         default => 'running'
      };

      $address = null;
      if ($type === 'WPI' && is_string($data['host'] ?? null) && is_int($data['port'] ?? null)) {
         $address = "{$data['host']}:{$data['port']}";
      }

      // ! The tap pathname is recomputed from the instance identity — the
      //   document's `tap` field is advisory, never a trusted path
      $tap = false;
      if ($alive === true && $type === 'WPI') {
         try {
            $tap = file_exists(new State($id, $qualifier !== '' ? $qualifier : null)->tapFile);
         }
         catch (Throwable) {
            $tap = false;
         }
      }

      // :
      return [
         'project'  => $path,
         'instance' => $qualifier !== '' ? $qualifier : null,
         'interface' => $type,
         'status'   => $status,
         'master'   => $master,
         'workers'  => $workers,
         'uptime'   => $alive === true && $started !== null ? max(0, time() - $started) : null,
         'address'  => $address,
         'tap'      => $tap
      ];
   }

   /**
    * Measure the width a table may take: the attached terminal's, or no
    * limit at all without one — a pipe or an agent reads every column.
    */
   private function measure (): int
   {
      // ?:
      if (BOOTGLY_TTY !== true || isSet(Terminal::$width) === false) {
         return PHP_INT_MAX;
      }

      // :
      return Terminal::$width;
   }

   /**
    * Keep the columns a table of the given width can fit — the most
    * expendable ones go first, the way `pm2 ps` narrows. Cells are measured
    * as the Table will pad them: one space, the content, one space, and a
    * separator between columns.
    *
    * @param array<string,string> $columns Key to header, in display order.
    * @param array<int,array<string,string>> $cells One row per instance, keyed like `$columns`.
    * @param int $width The width available.
    *
    * @return array<string,string> The surviving columns, in display order.
    */
   private function fit (array $columns, array $cells, int $width): array
   {
      // ! Column widths: the header or the widest cell
      $widths = [];
      foreach ($columns as $key => $header) {
         $widths[$key] = mb_strlen($header);
         foreach ($cells as $cell) {
            $widths[$key] = max($widths[$key], mb_strlen($cell[$key] ?? ''));
         }
      }
      // ! Borders and padding: `║ ` … ` ║` plus ` │ ` between columns
      $span = static fn (array $widths): int => array_sum($widths) + 3 * count($widths) + 1;

      // @@ Give up the most expendable column until the table fits
      $expendable = self::EXPENDABLE;
      while ($span($widths) > $width && $expendable !== []) {
         $key = array_shift($expendable);
         unset($columns[$key], $widths[$key]);
      }

      // :
      return $columns;
   }

   /**
    * Clip a cell to one line of the given width, marking the cut.
    */
   private function clip (string $text, int $width): string
   {
      // ?:
      if (mb_strlen($text) <= $width) {
         return $text;
      }

      // :
      return mb_substr($text, 0, max(1, $width - 1)) . '…';
   }

   /**
    * Render an uptime compactly — the two most significant units.
    */
   private function elapse (int $seconds): string
   {
      $days = intdiv($seconds, 86400);
      $hours = intdiv($seconds % 86400, 3600);
      $minutes = intdiv($seconds % 3600, 60);
      $secs = $seconds % 60;

      // :
      return match (true) {
         $days > 0    => "{$days}d {$hours}h",
         $hours > 0   => "{$hours}h {$minutes}m",
         $minutes > 0 => "{$minutes}m {$secs}s",
         default      => "{$secs}s"
      };
   }

   /**
    * Explain the privilege boundary when state on record could not be
    * verified by the current runtime user.
    */
   private function hint (): void
   {
      // ? Running as root already sees everything
      if (posix_getuid() === 0) {
         return;
      }

      // ? Only when a documented (non-tombstone) state file actually exists
      foreach (glob(BOOTGLY_STORAGE_DIR . 'pids/*.json') ?: [] as $file) {
         if ((int) filesize($file) > 0) {
            CLI->Terminal->Output->render(
               '@#Green:Tip:@; state files exist but could not be verified — run @#Blue:projects show@; as the service account that started those projects (never @#red:sudo bootgly@;).@..;'
            );

            return;
         }
      }
   }

   /**
    * Run the project's own boot hook — `project <Name> boot` — on a path this
    * command just minted. `--no-git` is the hook's opt-out, so it never runs.
    *
    * @param array<string, bool|int|string> $options
    */
   private function boot (string $path, array $options): void
   {
      // ? Opt-out
      if (isSet($options['no-git']) === true) {
         return;
      }

      // @ One implementation: the singular command owns the hook
      new ProjectCommand()->boot([$path]);
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
               $Textbox->default = (string) ($options['port'] ?? self::PORT);
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

            $this->boot((string) $path, $options);

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
               $Alert->message = "Source project @#cyan:" . $this->clean($from) . "@; not found in the platform folders.";
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
            "@#Green:Tip:@; Use @#Blue:{$prefix}bootgly projects list@; to see them all.@.;"
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
    * (system git) and resource directories (`kit boot --resources`).
    *
    * @param array<string, bool|int|string> $options
    *
    * @return bool
    */
   private function prepare (array $options, null|string $reserve = null): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Requested platforms (comma-separated: --platform=console,web).
      //   Read and judged before anything is prepared, on every layout: a kit
      //   without `.gitmodules` — and the framework checkout, which has
      //   nothing to prepare at all — used to accept an unimplemented value
      //   and drop it. The refusal must never follow a write, and the
      //   resource boot and the example stock are below.
      $platforms = $this->sift($options);
      if ($platforms === false) {
         return false;
      }

      // ? Framework repo: nothing to prepare beyond judging the options above
      if (BOOTGLY_ROOT_DIR === BOOTGLY_WORKING_DIR) {
         return true;
      }

      // ---

      // # Platform submodules (kit)
      $initialized = [];
      $gitmodules = is_file(BOOTGLY_WORKING_DIR . '.gitmodules');
      $console = is_file(BOOTGLY_WORKING_DIR . 'Console/' . BOOTSTRAP_FILENAME);
      $web = is_file(BOOTGLY_WORKING_DIR . 'Web/' . BOOTSTRAP_FILENAME);

      if ($gitmodules === true) {
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
         // ! `kit boot` — the resource directories, laid down once
         if (new KitCommand()->boot(['resources' => true]) === false) {
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
      $pipes = [];
      $process = proc_open("{$command} 2>&1", [1 => ['pipe', 'w']], $pipes);

      // ?
      if (is_resource($process) === false) {
         return 1;
      }

      // @@ Line by line, as it arrives — carriage returns would drag the
      //   cursor out of the region column, so they never reach the Output
      while (($line = fgets($pipes[1])) !== false) {
         $Output->write(str_replace("\r", '', rtrim($line, "\n")) . PHP_EOL);
      }

      fclose($pipes[1]);

      // :
      return proc_close($process);
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
         return "Invalid project path: @#cyan:" . $this->clean($path) . "@;. Segments must start uppercase and use "
            . 'only letters, numbers, `_` or `-` (e.g. `App` or `App/API`).';
      }
      // ? Reserved platform namespace root (would shadow the framework/platform namespaces)
      $root = strtolower(explode('/', $path)[0]);
      foreach (Projects::RESERVED as $reserved) {
         if ($root === strtolower($reserved)) {
            return "Invalid project path: @#cyan:" . $this->clean($path) . "@;. @#cyan:{$reserved}@; is a reserved Bootgly "
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
         return "Project @#cyan:" . $this->clean($path) . "@; is already registered.";
      }
      // ? Directory collision
      if (
         is_dir(Projects::CONSUMER_DIR . $path) === true
         || is_dir(Projects::AUTHOR_DIR . $path) === true
      ) {
         return "Project directory @#cyan:projects/" . $this->clean($path) . "@; already exists.";
      }

      // :
      return true;
   }

   /**
    * Sift the requested platforms out of the options, refusing an unimplemented
    * value.
    *
    * ONE rule, read by `create()`'s entry gate and by `prepare()`: two copies
    * had already drifted apart on `none,web`, where the entry accepted the list
    * and `prepare()` then refused `none` as a platform name — the same sentence
    * calling a value valid and invalid.
    *
    * Three answers, and the difference matters: `null` means the flag was not
    * given at all — a fresh kit reads that as "set up every platform" — while
    * `[]` is an explicit `none`, and `false` is a refusal.
    *
    * @param array<string, bool|int|string> $options
    *
    * @return false|null|array<int,string>
    */
   private function sift (array $options): false|null|array
   {
      // ? Not requested — NOT the same as `none`: the caller decides
      if (isSet($options['platform']) === false || is_string($options['platform']) === false) {
         return null;
      }

      $platforms = array_filter(explode(',', strtolower($options['platform'])));

      // ? `none` keeps the base platform only, and is EXCLUSIVE: pairing it with
      //   a platform asks for nothing and for something in the same breath
      if (in_array('none', $platforms, true) === true) {
         if ($platforms === ['none']) {
            return [];
         }

         $Alert = new Alert(CLI->Terminal->Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Invalid platform: @#cyan:none@; cannot be combined. '
            . 'Use console, web, console,web or none.';
         $Alert->render();

         return false;
      }

      // @
      foreach ($platforms as $platform) {
         if ($platform !== 'console' && $platform !== 'web') {
            $Alert = new Alert(CLI->Terminal->Output);
            $Alert->Type::Failure->set();
            $Alert->message = "Invalid platform: @#cyan:" . $this->clean($platform) . "@;. "
               . 'Use console, web, console,web or none.';
            $Alert->render();

            return false;
         }
      }

      // :
      return $platforms;
   }

   /**
    * Weigh a create: every input the command can judge WITHOUT a kit.
    *
    * `create()` prepares the working directory before it acts — on a fresh
    * kit that means the resource directories, the shipped example projects
    * and the registry that allow-lists them — because the path the user asked
    * for has to be reserved against the examples that same run stocks. That
    * bootstrap is the kit's legitimate first-run work and nothing rolls it
    * back, so a refusal that never needed a kit is decided here, ahead of it.
    *
    * The KIT-dependent collisions (the registry, and the kit's own directory)
    * are deliberately NOT judged here: they are the half of `assess()` the
    * reservation exists for, and only a prepared kit can answer them. The
    * author-shipped collision IS judged here — it reads the framework's own
    * `projects/`, needs no kit, and reserving such a name would cost a fresh
    * kit the example it is about to refuse.
    *
    * @param string $path The target project path.
    * @param string $from `scratch`, or the platform project to copy.
    * @param array<string, bool|int|string> $options
    *
    * @return true|string True when the inputs are usable; an error message otherwise.
    */
   private function weigh (string $path, string $from, array $options): true|string
   {
      // ? Naming pattern and reserved roots. FIRST, because it names the rule
      //   that was broken; `check()` below only reports that the path is
      //   invalid, and every traversal this catches is caught by both
      $result = $this->screen($path);
      if ($result !== true) {
         return $result;
      }
      // ? Path-safety — the platform-import route replaces a directory, so a
      //   traversal must never reach the erase
      if (Projects::check($path) === false) {
         return "Invalid project path: @#cyan:" . $this->clean($path) . "@;.";
      }

      // ? A platform copy carries its own metadata — the rules below are the
      //   scratch route's, which mints it from the options. The `--from` route
      //   also legitimately targets an author-shipped path: importing a
      //   platform project ONTO ITSELF is how `--refresh` works.
      if ($from !== 'scratch') {
         return true;
      }

      // ? The AUTHOR-shipped half of the collision, which needs no kit: it
      //   reads the FRAMEWORK's own `projects/`, the very shelf the examples
      //   are stocked from. `assess()` below would refuse this path anyway —
      //   but only after `prepare()` reserved it, and a reserved name is an
      //   example left off a fresh kit's shelf for good. The kit-dependent
      //   half (the registry, and the kit's own directory) stays in
      //   `assess()`, where the reservation legitimately wins the name.
      if (is_dir(Projects::AUTHOR_DIR . $path) === true) {
         return "Project directory @#cyan:projects/" . $this->clean($path) . "@; already exists.";
      }


      // ? Interface
      $interface = strtoupper((string) ($options['interfaces'] ?? self::INTERFACE));
      if ($interface !== 'CLI' && $interface !== 'WPI') {
         return "Invalid interface: @#cyan:" . $this->clean($interface) . "@;. Use CLI or WPI.";
      }

      // ? Port validity — the same rule the wizard Validator enforces;
      //   `(int) 'not-a-port'` would otherwise bind the server on port 0
      $port = (string) ($options['port'] ?? self::PORT);
      if (preg_match(self::PORT_PATTERN, $port) !== 1) {
         return "Invalid port: @#cyan:" . $this->clean($port) . "@;. Use a number between 1 and 65535.";
      }

      // ? Control characters — generate() refuses them too, but its refusal
      //   is generic; here the caller learns which flag was rejected
      foreach (['description', 'version', 'author'] as $field) {
         if (preg_match('#[\x00-\x1F]#', (string) ($options[$field] ?? '')) === 1) {
            return "Invalid @#cyan:--{$field}@;: control characters are not allowed.";
         }
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
    * Display usage help or report an invalid argument.
    *
    * @param array<string> $arguments
    *
    * @return bool
    */
   public function help (array $arguments = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? An unknown argument is reported before the general help
      $status = true;
      if (isSet($arguments[0]) === true && isSet($this->arguments[$arguments[0]]) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = "Invalid argument: @#cyan:{$arguments[0]}@;.";
         $Alert->render();

         $status = false;
      }

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
         $content .= '@#Yellow:' . $name . '@;';
         $content .= str_pad('', 10 - strlen($name)) . '  ' . $description . PHP_EOL;
      }
      $content = rtrim($content);
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Projects arguments @;';
      $Fieldset->content = $content;
      $Fieldset->render();

      // # Usage
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#green: Projects usage @;';
      $Fieldset->content = 'bootgly projects @#Black: <argument> @;';
      $Fieldset->render();

      // # Examples
      $exampleLines = '@#Blue:bootgly projects create@;' . PHP_EOL;
      $exampleLines .= '@#Blue:bootgly projects create App/API --from=scratch --interfaces=WPI --yes@;' . PHP_EOL;
      $exampleLines .= '@#Blue:bootgly projects import https://github.com/foo/project1 Project1@;' . PHP_EOL;
      $exampleLines .= '@#Blue:bootgly projects list@;' . PHP_EOL;
      $exampleLines .= '@#Blue:bootgly projects show@; @#Black:(every running instance — the `ps` view)@;' . PHP_EOL;
      $exampleLines .= '@#Blue:bootgly projects show --all --json@;';
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#green: Projects examples @;';
      $Fieldset->content = $exampleLines;
      $Fieldset->render();

      $Output->render('@.;');

      return $status;
   }
}
