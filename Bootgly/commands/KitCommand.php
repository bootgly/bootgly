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


use const BOOTGLY_ROOT_BASE;
use const BOOTGLY_ROOT_DIR;
use const BOOTGLY_VERSION;
use const BOOTGLY_WORKING_DIR;
use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const PHP_EOL;
use function array_intersect_key;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_reverse;
use function array_slice;
use function bin2hex;
use function chown;
use function clearstatcache;
use function constant;
use function copy;
use function count;
use function defined;
use function dirname;
use function explode;
use function file_exists;
use function file_get_contents;
use function filegroup;
use function fileowner;
use function getenv;
use function getmypid;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function json_encode;
use function ksort;
use function lchgrp;
use function lchown;
use function lstat;
use function mkdir;
use function posix_geteuid;
use function preg_match;
use function preg_quote;
use function preg_replace;
use function proc_close;
use function proc_open;
use function random_bytes;
use function readlink;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function scandir;
use function sha1;
use function sha1_file;
use function shell_exec;
use function str_contains;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function symlink;
use function time;
use function trim;
use function unlink;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

use const Bootgly\CLI;
use function Bootgly\ABI\copy_recursively;
use Bootgly\ABI\Data\SemVer;
use Bootgly\ACI\Process\States;
use Bootgly\ACI\VCS;
use Bootgly\ACI\VCS\Git;
use Bootgly\API\Environment\Agent;
use Bootgly\API\Environment\Container;
use Bootgly\API\Projects;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Alert;
use Bootgly\CLI\UI\Components\Table;


/**
 * `bootgly kit` — move the Bootgly Kit between Bootgly Platform releases.
 *
 * The kit is the delivery vehicle: the user never commits to it, everything
 * theirs at its root is gitignored, and each of its tags pins a coherent set
 * of framework and platform releases. Moving the kit is therefore checking
 * out one of its tags and letting the submodules follow the index — the
 * update `git pull` never was, and the only one a kit generated from the
 * GitHub template (squashed history, no upstream) can have.
 *
 * Four verbs: `boot` lays down the kit's resource directories, `upgrade`
 * and `downgrade` move it between releases (the direction is the only thing
 * that differs between them), `list` shows where it stands. The git work
 * goes through `ACI\VCS`, the ordering through `ABI\Data\SemVer`.
 */
class KitCommand extends Command
{
   /** The repository every kit descends from — where the releases are. */
   public const string REPOSITORY = 'https://github.com/bootgly/bootgly.kit';
   /** The remote name given to a kit with no remote pointing there (one generated from the template). */
   public const string REMOTE = 'bootgly';
   /** The submodule PATH whose presence in `.gitmodules` makes a checkout a kit. */
   public const string FRAMEWORK = 'Bootgly';
   /** A full commit hash — 40 (SHA-1) or 64 (SHA-256) hex digits. */
   private const string HASH = '/^[0-9a-f]{40,64}$/';
   /**
    * The storage layout a kit runs on, laid down by `boot` — the framework ships no template.
    *
    * Not `sessions/` and not `security/`: their owners demand `0700` and create them so
    * (the File session handler refuses a directory it did not lock down; AutoTLS and the
    * Vault the same) — pre-creating them at the umask would break the first session.
    */
   private const array STORAGE = ['cache/', 'locks/', 'logs/', 'pids/', 'queues/', 'schedule/', 'temp/', 'tests/'];
   /** The name of a skill `boot` owns — the `bootgly-` prefix is Bootgly's. */
   private const string SKILL = '/^bootgly-[a-z0-9-]+$/';
   /** The first line of an agent-rules entry point `boot` owns — anything else in its place is the user's. */
   public const string STAMP = '<!-- Machine-managed by `bootgly kit boot`';
   /** The transports a releases remote may use — anything else is refused. */
   private const array TRANSPORTS = ['https', 'ssh', 'git+ssh', 'file'];

   // * Config
   public int $group = 0;

   // * Data
   // # Command
   public string $name = 'kit';
   public string $description = 'Move the Bootgly Kit between Bootgly Platform releases';
   /** @phpstan-ignore property.phpDocType */
   /** @var array<string,array<string,array<string,string>|string>> */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'boot' => [
         'description' => 'Lay down the kit\'s resource directories (projects, scripts, storage) and its agent rules',
         'arguments'   => []
      ],
      'upgrade' => [
         'description' => 'Move the kit to a newer release — the newest when none is named',
         'arguments'   => [
            '[release]' => 'The release to move to (e.g. v1.0.0-beta.8)'
         ]
      ],
      'downgrade' => [
         'description' => 'Move the kit back to an earlier release — the previous one when none is named',
         'arguments'   => [
            '[release]' => 'The release to move to (e.g. v1.0.0-beta.6)'
         ]
      ],
      'list' => [
         'description' => 'List the releases the kit can move to, marking the current one',
         'arguments'   => []
      ],
   ];
   /** @var array<string,array<string>> */
   public array $options = [
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      'Machine output — one JSON document (upgrade/downgrade/list)' => ['--json'],
      'Answer every confirmation: running instances, a major crossing, a release predating this command (upgrade/downgrade)' => ['--yes'],
      'Lay down only the resource directories: projects, scripts, storage (boot)' => ['--resources'],
      'Lay down only the agent rules and skills: projects/AGENTS.md, projects/.agents/ (boot)' => ['--agents'],
   ];
   // # Kit
   /** The kit root — the launcher's directory. */
   protected string $kit = BOOTGLY_WORKING_DIR;
   /** Where the releases come from. */
   protected string $repository = self::REPOSITORY;
   /** Where `boot` takes the resource templates from — the framework checkout. */
   protected string $templates = BOOTGLY_ROOT_DIR;
   /** The line `setup` writes into the global wrapper — the walk-up one; a launcher copied onto PATH never carries it. */
   public const string WRAPPER_STAMP = '# bootgly-wrapper: walk-up';
   /** @var array<int,string> Files a container runtime leaves behind — Docker, then Podman. */
   protected array $markers = Container::MARKERS;

   // * Metadata
   /** @var array<string,mixed> The document `--json` emits, built as the run goes. */
   private array $document = [];
   private bool $json = false;
   /** @var null|list<string> Files a release would replace that appeared after the guards; null when the tree could not be read. */
   private null|array $late = [];


   /**
    * Run the command with the given arguments and options.
    *
    * @param array<string> $arguments
    * @param array<string,bool|int|string> $options
    *
    * @return bool
    */
   public function run (array $arguments = [], array $options = []): bool
   {
      $this->kit = rtrim($this->kit, '/') ?: '/';
      $this->json = isSet($options['json']);

      $verb = $arguments[0] ?? null;

      // ?: A JSON run gets a document even when the verb is missing or unknown
      if ($this->json === true && in_array($verb, ['upgrade', 'downgrade', 'list'], true) === false) {
         $this->document = ['command' => 'kit', 'verb' => $verb, 'kit' => $this->kit];

         return match ($verb) {
            null   => $this->fail('No kit verb given.', 'One of: upgrade, downgrade, list — `boot` has no JSON form.'),
            'boot' => $this->fail('`boot` has no JSON form.', 'Run it without @#cyan:--json@;: it reports what it lays down as it goes.'),
            default => $this->fail('Unknown kit verb: ' . $this->clean((string) $verb) . '.', 'One of: upgrade, downgrade, list — `boot` has no JSON form.'),
         };
      }

      return match ($verb) {
         'boot'      => $this->boot($options),
         'upgrade'   => $this->move(array_slice($arguments, 1), $options, downgrade: false),
         'downgrade' => $this->move(array_slice($arguments, 1), $options, downgrade: true),
         'list'      => $this->list($options),
         default     => $this->help($arguments)
      };
   }

   /**
    * Render the command's help — the three verbs, their arguments, the options.
    *
    * @param array<string> $arguments The subcommand path; empty for the general help.
    *
    * @return bool False when the subcommand named does not exist.
    */
   public function help (array $arguments = []): bool
   {
      $Output = CLI->Terminal->Output;

      // ? An unknown verb — say so, then the general help
      if ($arguments !== [] && isSet($this->arguments[$arguments[0]]) === false) {
         $Alert = new Alert($Output);
         $Alert->Type::Failure->set();
         $Alert->message = 'Unknown kit verb: @#cyan:' . $this->clean($arguments[0]) . '@;.';
         $Alert->render();

         $this->help([]);

         return false;
      }

      $Output->write(PHP_EOL);

      // # Header
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = "@#Cyan: {$this->name} @;";
      $Fieldset->content = $this->description;
      $Fieldset->render();

      // # Verbs
      $content = '';
      foreach ($this->arguments as $verb => $meta) {
         /** @var array{description:string,arguments:array<string,string>} $meta */
         $content .= '@#Yellow:' . str_pad($verb, 11) . '@;' . $meta['description'] . PHP_EOL;
         foreach ($meta['arguments'] as $argument => $description) {
            $content .= '  @#cyan:' . str_pad($argument, 9) . '@; ' . $description . PHP_EOL;
         }
      }
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Kit verbs @;';
      $Fieldset->content = rtrim($content);
      $Fieldset->render();

      // # Options
      $content = '';
      foreach ($this->options as $description => $flags) {
         $joined = implode(', ', $flags);
         $content .= '@#Yellow:' . str_pad($joined, 14) . '@;  ' . $description . PHP_EOL;
      }
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#Cyan: Kit options @;';
      $Fieldset->content = rtrim($content);
      $Fieldset->render();

      // # Examples
      $Fieldset = new Fieldset($Output);
      $Fieldset->title = '@#green: Kit examples @;';
      $Fieldset->content = '@#Black:bootgly kit boot@;' . PHP_EOL
         . '@#Black:bootgly kit list@;' . PHP_EOL
         . '@#Black:bootgly kit upgrade@;' . PHP_EOL
         . '@#Black:bootgly kit upgrade v1.0.0-beta.8 --yes@;' . PHP_EOL
         . '@#Black:bootgly kit downgrade@;' . PHP_EOL
         . '@#Black:bootgly kit list --json@;';
      $Fieldset->render();

      $Output->write(PHP_EOL);

      // :
      return true;
   }

   // # Verbs
   /**
    * `kit boot` — lay down the resource directories a kit runs on.
    *
    * `projects/` seeded with an EMPTY registry (the framework's own projects —
    * Demos, Benchmarks — are never listed in a kit; `projects create`/`import`
    * fill it), and the framework's `scripts/` and `storage/` templates copied
    * over. Each one only where it does not exist yet: a boot never touches
    * what is already there. No kit-level `public/`: the serving APIs are
    * jailed to the project directory, so assets live per project.
    *
    * The one exception is the agent rules — `projects/AGENTS.md`,
    * `projects/.agents/rules/` and the `bootgly-*` skills (the framework's and
    * each platform package's) with their `projects/.claude/skills/` links:
    * Bootgly's, not the kit's, so they are laid down AND refreshed whenever
    * they differ from the pinned templates — but only while they are
    * Bootgly's (stamped, see `lay()`). `--resources` lays down only the
    * directories, `--agents` only the rules and skills; both by default.
    *
    * `projects create` and `import` run this on a fresh kit by themselves.
    *
    * @param array<string,bool|int|string> $options
    *
    * @return bool
    */
   public function boot (array $options = []): bool
   {
      // ? Refuse a flag this verb does not take
      if ($this->admit(['resources', 'agents'], $options) === false) {
         return false;
      }
      // ! The sets to lay down — both unless one is named
      $resources = isSet($options['agents']) === false || isSet($options['resources']) === true;
      $agents = isSet($options['resources']) === false || isSet($options['agents']) === true;

      $Output = CLI->Terminal->Output;
      $kit = rtrim($this->kit, '/') ?: '/';

      $Output->render('@.;@#green:' . ($resources === true ? 'Booting resource directories...' : 'Booting agent rules...') . '@;@.;');

      // ? The framework checkout is not a kit: its resources are the templates
      $Alert = new Alert($Output);
      if (realpath($kit) === realpath(BOOTGLY_ROOT_BASE)) {
         $Alert->Type::Failure->set();
         $Alert->message = 'No resources to boot!';
         $Alert->render();

         return false;
      }
      $Alert->spaced = false;

      $templates = rtrim($this->templates, '/');

      // # scripts/ — the framework's template, mirrored into a staging directory
      //   and renamed into place: a copy that fails leaves nothing behind that a
      //   later run's `is_dir` could take for the real thing
      if ($resources === true && is_dir("{$kit}/scripts") === false) {
         $staging = "{$kit}/.scripts." . getmypid() . '.partial';
         $this->wipe($staging);
         $mirrored = $this->mirror("{$templates}/scripts/", "{$staging}/")
            && @rename($staging, "{$kit}/scripts")
            && is_dir("{$kit}/scripts");
         // ?
         if ($mirrored === false) {
            $this->wipe($staging);
            $Alert->Type::Failure->set();
            $Alert->message = 'Could not lay down @#cyan:scripts/@; in the kit.';
            $Alert->render();

            return false;
         }

         $Alert->Type::Success->set();
         $Alert->message = 'Resource dir copied: @#cyan:scripts/@;';
         $Alert->render();
      }

      // # storage/ — the layout, created: the framework ships no template, and a
      //   checkout that has been run carries sessions, pid files and key material
      //   that belong to it alone. A layout that cannot be completed is removed
      $created = false;
      if ($resources === true && is_dir("{$kit}/storage") === false) {
         $laid = @mkdir("{$kit}/storage", 0755, true);
         $created = $laid;
         foreach (self::STORAGE as $inner) {
            $laid = $laid && @mkdir("{$kit}/storage/{$inner}", 0755, true);
         }
         // ?
         if ($laid === false) {
            if ($created === true) {
               $this->wipe("{$kit}/storage");
            }
            $Alert->Type::Failure->set();
            $Alert->message = 'Could not lay down @#cyan:storage/@; in the kit.';
            $Alert->render();

            return false;
         }

         $Alert->Type::Success->set();
         $Alert->message = 'Resource dir created: @#cyan:storage/@;';
         $Alert->render();
      }

      // # projects/ — with the empty registry, LAST: the registry is what marks a
      //   kit as prepared (`projects create` boots and stocks a kit without one),
      //   so a boot that fails before it leaves the next run free to repair —
      //   and it is gated on the file it promises, never on the directory alone
      $registry = "{$kit}/projects/Bootgly.projects.php";
      if ($resources === true && is_file($registry) === false) {
         $created = (is_dir("{$kit}/projects") === true || @mkdir("{$kit}/projects", 0755, true))
            && @copy("{$templates}/Bootgly/commands/stubs/Bootgly.projects.php", $registry)
            && is_file($registry);
         // ?
         if ($created === false) {
            $Alert->Type::Failure->set();
            $Alert->message = 'Could not create @#cyan:projects/@; in the kit.';
            $Alert->render();

            return false;
         }

         $Alert->Type::Success->set();
         $Alert->message = 'Resource dir created: @#cyan:projects/@;';
         $Alert->render();

         // @ The registry root just laid down inside a mounted projects/ belongs
         //   to whoever mounted it — the host user, in the documented Docker
         //   flow. Only the file this run wrote: a tree that was already there
         //   is not root's to give, and projects/ itself is the mount
         self::grant($registry);
      }

      // # projects/AGENTS.md + projects/.agents/rules/ — the agent rules, refreshed
      //   whenever they differ from the pinned framework's templates. Advisory:
      //   a failure is said, and fails only a run that asked for the rules alone
      //   (the registry above is already written — a boot that stops here would
      //   leave a kit that never gets its examples stocked)
      if ($agents === true && $this->lay($kit, $templates, $Alert) === false && $resources === false) {
         return false;
      }

      $Output->render('@#green:OK@;@.;');
      $Output->write(PHP_EOL);

      // :
      return true;
   }

   /**
    * Lay down the agent rules in the kit's `projects/` — `AGENTS.md` and
    * `.agents/rules/`, mirrored from the framework's `templates/projects/` —
    * and the `bootgly-*` skills: the framework's and those of each platform
    * package set up in the kit (see `gather()`).
    *
    * They are machine-managed, but only while they are the framework's: an
    * `AGENTS.md` whose first line is the stamp, with `.agents/rules/` beside
    * it (or neither there yet). Anything else in their place — an unstamped
    * or linked `AGENTS.md`, a `.agents/rules/` with no stamped entry point, a
    * linked `.agents/` — is the user's: left exactly as it is, and said. The
    * rest of `.agents/` (a user's own skills) is never touched.
    *
    * When they are the framework's and differ from the pinned templates (a
    * release that moved, a hand edit) they are replaced whole, never merged;
    * when they match, nothing is written. Staging names are unguessable and
    * created fresh, so nothing planted beside them is written through.
    *
    * @param string $kit The kit root.
    * @param string $templates The framework checkout the templates come from.
    * @param Alert $Alert The alert the boot reports through.
    *
    * @return bool False when the rules could not be laid down.
    */
   private function lay (string $kit, string $templates, Alert $Alert): bool
   {
      $source = "{$templates}/Bootgly/commands/templates/projects";
      $target = "{$kit}/projects";
      $entry = "{$target}/AGENTS.md";
      $rules = "{$target}/.agents/rules";

      // ? A framework that predates the rules, or a kit with no projects/ yet
      if (is_file("{$source}/AGENTS.md") === false || is_dir($target) === false) {
         return true;
      }
      // ? A platform checkout — its projects/ are its examples, not a kit's
      foreach (['CONSOLE_ROOT_BASE', 'WEB_ROOT_BASE'] as $root) {
         if (defined($root) === true && realpath((string) constant($root)) === realpath($kit)) {
            return true;
         }
      }
      // ? The user's, not the framework's — left as it is, and said (a skip,
      //   not a failure: a kit may keep its own entry point there)
      if (self::claim($target) === false) {
         $Alert->Type::Attention->set();
         $Alert->message = 'Agent rules skipped: @#cyan:projects/AGENTS.md@; is not Bootgly\'s.';
         $Alert->render();

         return true;
      }
      // ! The skills the framework and the platform packages carry — each a
      //   `bootgly-*` directory. Bootgly's in .agents/skills/ are the stamped
      //   ones (`own()`); every other entry there is the user's, a `bootgly-*`
      //   one included (it may predate the reserved prefix)
      $skills = $this->gather($source, $kit);
      $shelf = "{$target}/.agents/skills";
      // ? A linked skills directory leads outside the kit, and a file there is
      //   not a directory — rules only
      $shelved = is_link($shelf) === false && (is_dir($shelf) === true || file_exists($shelf) === false);
      if ($shelved === false) {
         $skills = [];
      }
      // ? A skill whose place holds something of the user's is not laid — said
      foreach (array_keys($skills) as $name) {
         $place = "{$shelf}/{$name}";
         if ((file_exists($place) === true || is_link($place) === true) && self::own($place) === false) {
            unset($skills[$name]);
            $Alert->Type::Attention->set();
            $Alert->message = "Skill @#cyan:{$name}@; kept: it is not Bootgly's.";
            $Alert->render();
         }
      }

      // ! Both sides as trees to sign: the entry point, the rules and each skill
      $sources = ['AGENTS.md' => "{$source}/AGENTS.md", '.agents/rules' => "{$source}/.agents/rules"];
      $targets = ['AGENTS.md' => $entry, '.agents/rules' => $rules];
      if ($shelved === true) {
         foreach ($skills as $name => $from) {
            $sources[".agents/skills/{$name}"] = $from;
         }
         foreach ((array) @scandir($shelf) as $name) {
            if (preg_match(self::SKILL, (string) $name) === 1 && self::own("{$shelf}/{$name}") === true) {
               $targets[".agents/skills/{$name}"] = "{$shelf}/{$name}";
            }
         }
      }

      // ? Already the templates, file for file — only the Claude links to check
      if ($this->sign($sources) === $this->sign($targets)) {
         $this->link($target, array_keys($skills));

         return true;
      }

      // ! One staging directory for the whole run — unguessable, made fresh
      //   and private (mkdir refuses a planted name): the new rules and skills,
      //   the retired ones and the entry point all pass through it
      $this->sweep($target);
      $staging = "{$target}/.bootgly." . getmypid() . '.' . bin2hex(random_bytes(6));
      $created = is_dir("{$target}/.agents") === false;
      $stocked = is_dir($shelf) === false && is_link($shelf) === false && $skills !== [];
      $laid = @mkdir($staging, 0700) === true
         && ($created === false || @mkdir("{$target}/.agents", 0755) === true)
         && ($stocked === false || @mkdir($shelf, 0755) === true)
         && $this->mirror("{$source}/.agents/rules/", "{$staging}/rules/");
      $laid = $laid && ($skills === [] || @mkdir("{$staging}/skills", 0700) === true);
      foreach ($skills as $name => $from) {
         $laid = $laid && $this->mirror("{$from}/", "{$staging}/skills/{$name}/");
      }

      // @ .agents/rules/ and each skill — swapped in whole; when one fails, every
      //   swap already done is rolled back, so the kit keeps the previous set
      $swaps = ["{$staging}/rules" => [$rules, "{$staging}/retired"]];
      foreach (array_keys($skills) as $name) {
         $swaps["{$staging}/skills/{$name}"] = ["{$shelf}/{$name}", "{$staging}/retired-{$name}"];
      }
      $done = [];
      foreach ($swaps as $fresh => [$place, $retired]) {
         $laid = $laid && $this->exchange($fresh, $place, $retired);
         if ($laid === true) {
            $done[] = [$place, $retired, "{$fresh}.rolled"];
         }
      }
      if ($laid === false) {
         foreach (array_reverse($done) as [$place, $retired, $rolled]) {
            @rename($place, $rolled);
            if (file_exists($retired) === true || is_link($retired) === true) {
               @rename($retired, $place);
            }
         }
      }
      // @ A skill of Bootgly's no longer carried (a platform removed, too) goes with the staging
      if ($laid === true && is_dir($shelf) === true && is_link($shelf) === false) {
         foreach ((array) @scandir($shelf) as $name) {
            if (preg_match(self::SKILL, (string) $name) === 1 && isSet($skills[$name]) === false
               && self::own("{$shelf}/{$name}") === true) {
               @rename("{$shelf}/{$name}", "{$staging}/stale-{$name}");
            }
         }
      }
      // @ AGENTS.md last — renamed over the entry point: a link is replaced
      if ($laid === true) {
         $laid = @copy("{$source}/AGENTS.md", "{$staging}/AGENTS.md") === true
            && @rename("{$staging}/AGENTS.md", $entry) === true;
      }
      $this->wipe($staging);
      // ?
      if ($laid === false) {
         $Alert->Type::Attention->set();
         $Alert->message = 'Could not lay down the agent rules in @#cyan:projects/@;.';
         $Alert->render();

         return false;
      }

      // @ Handed over like the registry — only what this run wrote: a
      //   bind-mounted projects/ is its owner's, and so is a .agents/ that
      //   was already there
      self::grant($rules);
      self::grant($entry);
      foreach (array_keys($skills) as $name) {
         self::grant("{$shelf}/{$name}");
      }
      if ($created === true) {
         self::grant("{$target}/.agents");
      }
      else if ($stocked === true) {
         self::grant($shelf);
      }
      // @ Claude Code reads skills from .claude/skills/ only
      $this->link($target, array_keys($skills));

      $Alert->Type::Success->set();
      $Alert->message = 'Agent rules laid down in @#cyan:projects/@;';
      $Alert->render();

      // :
      return true;
   }

   /**
    * Put a freshly mirrored tree in its place: the one there is moved aside
    * first and put back when the move-in fails.
    *
    * @param string $fresh The new tree, in the staging directory.
    * @param string $place Where it goes.
    * @param string $retired Where the previous one waits, in the staging directory.
    *
    * @return bool
    */
   private function exchange (string $fresh, string $place, string $retired): bool
   {
      $present = file_exists($place) === true || is_link($place) === true;
      // ?
      if ($present === true && @rename($place, $retired) === false) {
         return false;
      }
      if (@rename($fresh, $place) === true) {
         return true;
      }
      if ($present === true) {
         @rename($retired, $place);
      }

      // :
      return false;
   }

   /**
    * Link each skill into `projects/.claude/skills/` — the only place Claude
    * Code reads project skills from — as `bootgly-*` links to
    * `../../.agents/skills/<name>`. Best effort: a `.claude/` or
    * `.claude/skills/` that is a link or a file, a real entry with a skill's
    * name and a link pointing anywhere else are the user's and left alone; a
    * link of ours whose skill is gone goes.
    *
    * @param string $target The kit's `projects/`.
    * @param array<int,string> $skills The skills laid down.
    */
   private function link (string $target, array $skills): void
   {
      $claude = "{$target}/.claude";
      $links = "{$claude}/skills";

      // ? The user's .claude/ — never written through
      foreach ([$claude, $links] as $path) {
         if (is_link($path) === true || (file_exists($path) === true && is_dir($path) === false)) {
            return;
         }
      }
      // ? Nothing to link, and nothing linked before
      if ($skills === [] && is_dir($links) === false) {
         return;
      }
      $made = is_dir($claude) === false;
      $placed = is_dir($links) === false;
      if ($placed === true && @mkdir($links, 0755, true) === false) {
         return;
      }

      // @@ A link of ours whose skill is gone goes — only one shaped like ours,
      //    left dangling: a skill still there (the user's) keeps its link
      foreach ((array) @scandir($links) as $name) {
         $path = "{$links}/{$name}";
         if (preg_match(self::SKILL, (string) $name) === 1 && in_array($name, $skills, true) === false
            && is_link($path) === true && @readlink($path) === "../../.agents/skills/{$name}" && file_exists($path) === false) {
            @unlink($path);
         }
      }
      // @@ Each skill gets its own — unless that name holds a link or an entry already:
      //    ours, or the user's (a link pointing elsewhere, a real entry)
      foreach ($skills as $name) {
         $path = "{$links}/{$name}";
         if (is_link($path) === true || file_exists($path) === true) {
            continue;
         }
         @symlink("../../.agents/skills/{$name}", $path);
      }

      if ($made === true) {
         self::grant($claude);
      }
      else if ($placed === true) {
         self::grant($links);
      }
   }

   /**
    * Tell whether the agent rules in a kit's `projects/` are the
    * framework's to write: a stamped `AGENTS.md` (or no entry point and no
    * `.agents/rules/` yet), under a `.agents/` that is not a link. Anything
    * else there is the user's.
    *
    * @param string $target The kit's `projects/`.
    *
    * @return bool
    */
   private static function claim (string $target): bool
   {
      $entry = "{$target}/AGENTS.md";
      $rules = "{$target}/.agents/rules";

      // ? A linked .agents/ leads outside the kit
      if (is_link("{$target}/.agents") === true) {
         return false;
      }

      // :
      return self::recognize($entry) === true
         || (file_exists($entry) === false && is_link($entry) === false
            && file_exists($rules) === false && is_link($rules) === false);
   }

   /**
    * Tell whether an agent-rules entry point is the framework's: a regular
    * file (never a link) whose first line opens with the stamp.
    *
    * @param string $file
    *
    * @return bool
    */
   private static function recognize (string $file): bool
   {
      // ?
      if (is_link($file) === true || is_file($file) === false) {
         return false;
      }

      $head = @file_get_contents($file, false, null, 0, strlen(self::STAMP));

      // :
      return $head === self::STAMP;
   }

   /**
    * Tell whether a skill in `.agents/skills/` is Bootgly's to rewrite or
    * remove: a real directory (never a link) whose `SKILL.md` — a regular
    * file — carries the stamp as the first line after its frontmatter.
    * Anything else under a `bootgly-*` name (one that predates the reserved
    * prefix, one whose stamp was taken out) is the user's.
    *
    * @param string $skill The skill's directory.
    *
    * @return bool
    */
   private static function own (string $skill): bool
   {
      $file = "{$skill}/SKILL.md";
      // ?
      if (is_link($skill) === true || is_dir($skill) === false || is_link($file) === true || is_file($file) === false) {
         return false;
      }

      $head = (string) @file_get_contents($file, false, null, 0, 4096);

      // : Line endings as checked out — a CRLF checkout keeps its stamp
      return preg_match('/\A---\r?\n(?:(?!---\r?\n)[^\r\n]*\r?\n)*---\r?\n' . preg_quote(self::STAMP, '/') . '/', $head) === 1;
   }

   /**
    * Sweep the staging directories an interrupted `lay()` left behind — only
    * this command's own name pattern, only once they are minutes old (a
    * concurrent boot's live staging is not a leftover), each removed without
    * following a link.
    *
    * @param string $target The kit's `projects/`.
    */
   private function sweep (string $target): void
   {
      // @@
      foreach ((array) @scandir($target) as $name) {
         $path = "{$target}/{$name}";
         if (preg_match('/^\.bootgly\.\d+\.[0-9a-f]{12}$/', (string) $name) !== 1) {
            continue;
         }
         $entry = @lstat($path);
         if ($entry !== false && (int) $entry['mtime'] < time() - 300) {
            $this->wipe($path);
         }
      }
   }

   /**
    * Gather the skills to lay down: the framework's own `bootgly-*` skills,
    * then each platform package's. A platform package is a kit-root
    * `<Platform>/` that is set up (its `autoboot.php` is there) and whose
    * `<Platform>/templates/projects/.agents/skills/` holds skills named
    * `bootgly-<action>-<platform>` — the framework names no platform (nor
    * reads `.gitmodules`, which the kit image drops): a package that is set
    * up brings its skills, one that is not (or was removed) leaves none. Two
    * packages whose names differ only in case are ambiguous and bring none.
    * A skill never replaces one already gathered, and only a stamped one is
    * taken (`own()`): an unstamped source would be laid once and then never
    * owned again, and a linked one would sign as a link but mirror as a
    * copy. Any other name a package ships is ignored.
    *
    * @param string $source The framework's `templates/projects/`.
    * @param string $kit The kit root.
    *
    * @return array<string,string> Each skill's name and the directory it comes from.
    */
   private function gather (string $source, string $kit): array
   {
      $skills = [];

      // @@ The framework's
      foreach ((array) @scandir("{$source}/.agents/skills") as $name) {
         $from = "{$source}/.agents/skills/{$name}";
         if (preg_match(self::SKILL, (string) $name) === 1 && self::own($from) === true) {
            $skills[(string) $name] = $from;
         }
      }
      // ! The platform packages set up in the kit, by lowercase name
      $platforms = [];
      foreach ((array) @scandir($kit) as $platform) {
         $platform = (string) $platform;
         if (preg_match('/^[A-Z][A-Za-z0-9]*$/', $platform) === 1 && $platform !== self::FRAMEWORK
            && is_file("{$kit}/{$platform}/autoboot.php") === true) {
            $platforms[strtolower($platform)][] = $platform;
         }
      }
      // @@ Each one's skills, suffixed with its own name
      foreach ($platforms as $suffix => $named) {
         // ? `Web` and `WEB` — neither is the platform
         if (count($named) !== 1) {
            continue;
         }
         $shelf = "{$kit}/{$named[0]}/{$named[0]}/templates/projects/.agents/skills";
         foreach ((array) @scandir($shelf) as $name) {
            $name = (string) $name;
            $from = "{$shelf}/{$name}";
            if (preg_match("/^bootgly-[a-z0-9-]+-{$suffix}$/", $name) === 1 && isSet($skills[$name]) === false
               && self::own($from) === true) {
               $skills[$name] = $from;
            }
         }
      }

      // :
      return $skills;
   }

   /**
    * Sign trees — each entry under them by its path relative to the tree's
    * key, and its content — so two sets compare as one string. A link is
    * signed by where it points, never followed; a missing tree as absent.
    *
    * @param array<string,string> $trees Each tree's key (`AGENTS.md`, `.agents/rules`,
    *                                    `.agents/skills/<name>`) and its path.
    *
    * @return string
    */
   private function sign (array $trees): string
   {
      $entries = [];

      // @@ Each tree, and everything under it
      foreach ($trees as $key => $tree) {
         $paths = [$key => $tree];
         if (is_dir($tree) === true && is_link($tree) === false) {
            // ? An unreadable tree cannot be the templates — it is drift
            try {
               $Entries = new RecursiveIteratorIterator(
                  new RecursiveDirectoryIterator($tree, FilesystemIterator::SKIP_DOTS),
                  RecursiveIteratorIterator::SELF_FIRST
               );
               /** @var SplFileInfo $Entry */
               foreach ($Entries as $Entry) {
                  $paths[$key . substr($Entry->getPathname(), strlen($tree))] = $Entry->getPathname();
               }
            }
            catch (Throwable) {
               return '';
            }
         }
         foreach ($paths as $relative => $path) {
            $entries[$relative] = match (true) {
               is_link($path) => 'link:' . (string) @readlink($path),
               is_file($path) => 'file:' . (string) @sha1_file($path),
               is_dir($path)  => 'dir',
               default        => 'none',
            };
         }
      }
      ksort($entries);

      // :
      return sha1((string) json_encode($entries));
   }

   /**
    * Hand what root just wrote to the owner of the kit's `projects/`. Only
    * inside the kit image, only when running as root, only for an entry under
    * `projects/` — a project tree or the registry; never `storage/`, which is
    * the runtime's and changes hands to the runtime identity on its own terms
    * — and only when `projects/` is owned by someone else: the host user who
    * bind-mounted it. A directory Docker created itself is root's, and root
    * keeps it — there is nobody to hand it to.
    *
    * The group directories above the entry go with it while they are still
    * root's — the ones this run created for a nested name. On a mount shared
    * by several root runs that also hands over a group directory an earlier
    * run left behind: its entry, and with it what owning a directory means —
    * the power to rename or remove whatever it holds; the trees inside keep
    * their own owners and modes.
    *
    * @param string $path An absolute path under `projects/`.
    */
   public static function grant (string $path): void
   {
      // ?
      if (Container::check() === false || posix_geteuid() !== 0) {
         return;
      }
      if (is_link($path) || file_exists($path) === false) {
         return;
      }
      // ? Anchored on the kit's own projects/, both sides resolved — never a
      //   lexical walk a `..` could steer outside it; projects/ itself is the
      //   mount and is never re-owned
      $mount = realpath(rtrim(Projects::CONSUMER_DIR, '/'));
      $real = realpath($path);
      if ($mount === false || $real === false || $real === $mount || str_starts_with($real, "{$mount}/") === false) {
         return;
      }
      $UID = (int) fileowner($mount);
      $GID = (int) filegroup($mount);
      if ($UID === 0) {
         return;
      }

      if (is_dir($real)) {
         self::hand($real, $UID, $GID);
      }
      @lchown($real, $UID);
      @lchgrp($real, $GID);
      // @ The group directories root created on the way — `projects/<Group>/`
      //   for a nested name — go with it, and only while they are still root's:
      //   one that was already the owner's is left as found
      for ($up = dirname($real); $up !== $mount && str_starts_with($up, "{$mount}/"); $up = dirname($up)) {
         // ? A failed stat (the directory went away) stops the walk too
         $owner = @fileowner($up);
         if ($owner !== 0) {
            break;
         }
         @lchown($up, $UID);
         @lchgrp($up, $GID);
      }
   }

   /**
    * Change the owner of every entry under a directory — never through a
    * symbolic link. `chown()` follows links, so a link a project carries
    * (`vendor/bin/*`, `public/storage -> …`, or one planted by an imported
    * repository) would hand its TARGET over instead; links are left exactly
    * as found, and the walk never descends into a linked directory.
    *
    * Residual: PHP has no `fchownat()`, so a path COMPONENT swapped for a
    * link between the `isLink()` stat and the `lchown()` would redirect that
    * one call. It takes a writer inside `projects/` racing root's import —
    * the mount owner, who is also the beneficiary of the handover.
    */
   private static function hand (string $path, int $UID, int $GID): void
   {
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      /** @var SplFileInfo $Entry */
      foreach ($Iterator as $Entry) {
         if ($Entry->isLink()) {
            continue;
         }
         @lchown($Entry->getPathname(), $UID);
         @lchgrp($Entry->getPathname(), $GID);
      }
   }

   /**
    * How the next command should be typed in a tip: bare `bootgly` only when
    * the `bootgly` on PATH will run THIS kit — inside a container, where the
    * image's own launcher is on PATH, or when the global wrapper is the
    * walk-up one that selects the kit around the working directory. Any
    * other `bootgly` (a stale wrapper pinned to another kit, a launcher
    * copied onto PATH, an unrelated binary) would operate somewhere else, so
    * the tip says `php bootgly`, which always means this kit — and a stale
    * wrapper is pointed at `setup`, once per run.
    */
   public static function suggest (): string
   {
      // ?: A container: the launcher on PATH is the image's own
      if (Container::check() === true) {
         return '';
      }
      // ?: No global at all
      $global = trim((string) shell_exec('command -v bootgly 2>/dev/null'));
      if ($global === '' || is_file($global) === false) {
         return 'php ';
      }
      // ?: The walk-up wrapper — the stamp only `setup` writes; a launcher
      //    copied or linked onto PATH carries the launcher's own text, never
      //    the stamp
      static $noted = false;
      $wrapper = (string) @file_get_contents($global, false, null, 0, 65536);
      if (str_contains($wrapper, self::WRAPPER_STAMP) === false) {
         if ($noted === false) {
            $noted = true;
            CLI->Terminal->Output->render(
               '@#Yellow:Note:@; the global @#cyan:bootgly@; on PATH is not the walk-up wrapper of this '
               . 'release — it runs the kit it points at, not this one. Refresh it with '
               . '@#cyan:php bootgly setup@;.@.;'
            );
         }

         return 'php ';
      }

      // :
      return '';
   }

   /**
    * Mirror a template directory into the kit and prove the copy carried it.
    *
    * The source is walked BEFORE the copy: every regular file (and link) is
    * looked for at the target afterwards, and an entry the copy cannot carry
    * at all — a FIFO, a socket, a device, which `copy_recursively()` skips
    * without a word — fails the mirror up front. A file born in the source
    * during the copy is not in the snapshot, so a live tree is not a failure.
    * `copy_recursively()` returns nothing, but the framework turns every
    * warning into a throw: an unreadable directory or file surfaces here.
    *
    * @param string $source The template directory, with its trailing separator.
    * @param string $target The kit directory, with its trailing separator.
    *
    * @return bool True when every entry of the snapshot exists at the target.
    */
   private function mirror (string $source, string $target): bool
   {
      // ?
      if (is_dir($source) === false) {
         return false;
      }

      // ! The snapshot — and the entries no copy can carry
      $expected = [];
      $Entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
      foreach ($Entries as $Entry) {
         /** @var SplFileInfo $Entry */
         if ($Entry->isLink() === false && $Entry->isFile() === false && $Entry->isDir() === false) {
            return false;
         }
         if ($Entry->isDir() === false) {
            $expected[] = substr($Entry->getPathname(), strlen($source));
         }
      }

      try {
         copy_recursively($source, $target);
      }
      catch (Throwable) {
         return false;
      }

      // @@
      clearstatcache(true);
      foreach ($expected as $relative) {
         if (is_file("{$target}{$relative}") === false && is_link("{$target}{$relative}") === false) {
            return false;
         }
      }

      // :
      return is_dir($target);
   }

   /**
    * Remove a directory this command created — a staging copy, a layout that
    * could not be completed. Never anything the user owns: the callers only
    * point it at what they made a moment ago.
    *
    * @param string $directory
    */
   private function wipe (string $directory): void
   {
      // ?
      if (is_link($directory) === true || is_dir($directory) === false) {
         @unlink($directory);

         return;
      }

      try {
         $Entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
         );
         foreach ($Entries as $Entry) {
            /** @var SplFileInfo $Entry */
            $Entry->isDir() === true && $Entry->isLink() === false ? @rmdir($Entry->getPathname()) : @unlink($Entry->getPathname());
         }
      }
      catch (Throwable) {
         // ? Best effort: an unreadable directory inside stays, and so does this one — never thrown
      }
      @rmdir($directory);
   }

   /**
    * `kit upgrade` / `kit downgrade` — move the kit to another release.
    *
    * @param array<string> $arguments `[release]`, if named.
    * @param array<string,bool|int|string> $options
    * @param bool $downgrade The direction.
    *
    * @return bool
    */
   public function move (array $arguments, array $options, bool $downgrade): bool
   {
      $verb = $downgrade ? 'downgrade' : 'upgrade';

      // # The kit, its releases and where it stands
      $opened = $this->open($verb, $options, ['json', 'yes']);
      if ($opened === null) {
         return false;
      }
      ['VCS' => $VCS, 'releases' => $releases, 'current' => $current, 'head' => $head] = $opened;
      $yes = isSet($options['yes']);

      // ? The releases could not be checked against the canonical remote: a tag
      //   from a fork or a mirror would pass for one — nothing moves unverified
      if ($opened['verified'] === false) {
         $remote = $this->clean($opened['remote']);

         return $this->fail(
            "The releases could not be checked against @#cyan:{$remote}@;.",
            'A move needs the canonical repository reachable — run again when it is; @#cyan:bootgly kit list@; still works offline.'
         );
      }

      // # The target
      $named = isSet($arguments[0]) ? trim($arguments[0]) : null;
      $selection = $this->select($releases, $current, $named, $downgrade);
      if (isSet($selection['refusal']) === true) {
         return $this->fail(...$selection['refusal']);
      }
      // ?: Nothing to move — unless a submodule sits off the pin: a `partial` a
      //    previous run announced stays `partial` until it is repaired
      if (isSet($selection['noop']) === true) {
         return $this->verify($VCS) === [] ? $this->skip($selection['noop']) : $this->linger($VCS);
      }
      $target = $selection['target'] ?? null;
      if ($target === null) {
         return $this->fail('No release to move to.');
      }
      $this->document['target'] = $this->shape([
         'tag' => $target['tag'], 'SemVer' => $target['SemVer'], 'commit' => $target['commit'], 'distance' => 0, 'source' => 'tag',
      ]);
      // ?: Already there
      if ($target['commit'] === $head) {
         return $this->verify($VCS) === [] ? $this->skip("The kit is already on @#cyan:{$target['tag']}@;.") : $this->linger($VCS);
      }

      // # Guards — the kit's own files and its submodules
      $reasons = $this->guard($VCS, $target['tag']);
      if ($reasons !== []) {
         $blockers = [];
         foreach ($reasons as $index => $reason) {
            $blockers[] = [
               'what' => $this->strip($reason['what']), 'paths' => $reason['paths'], 'fix' => $this->strip($reason['fix']),
            ];

            $Alert = new Alert(CLI->Terminal->Output);
            $Alert->Type::Failure->set();
            $Alert->spaced = $index === 0;
            $Alert->message = $reason['what'];
            $this->render($Alert);
            foreach ($reason['paths'] as $path) {
               $this->say('   @#cyan:' . $this->clean($path) . '@;');
            }
            $this->say("   {$reason['fix']}");
         }
         $this->document['blockers'] = $blockers;

         return $this->fail('The kit has changes of its own.', quiet: true);
      }

      // # Confirmations
      $from = $current['tag'] ?? substr($head, 0, 7);
      $questions = [];
      $running = $this->scan();
      if ($running !== []) {
         $this->document['running'] = $running;
         $this->warn('Running instances: ' . $this->clean(implode(', ', $running)) . '.');
         $this->say(
            '   They keep the files they loaded until restarted — '
            . 'stop them first (@#cyan:bootgly project <Name> stop@;) or reload them after.'
         );
         $questions[] = 'Continue with instances running?';
      }
      if ($current['SemVer'] !== null && $current['SemVer']->major !== $target['SemVer']->major) {
         $this->warn("Crossing a major version: {$current['SemVer']->major} → {$target['SemVer']->major}.");
         $questions[] = 'Continue across the major version?';
      }
      if ($this->predate($VCS, $target['tag']) === true) {
         $this->document['predates'] = true;
         $this->warn("@#cyan:{$target['tag']}@; predates this command.");
         $this->say(
            '   Coming back takes git by hand: '
            . "@#cyan:git checkout {$from}@; then @#cyan:git submodule update@; in the kit."
         );
         $questions[] = 'Continue to a release without `bootgly kit upgrade`?';
      }
      foreach ($questions as $question) {
         // ! `--json` is a headless contract: nothing is asked, `--yes` or nothing
         if ($yes === false && ($this->json === true || $this->confirm($question) === false)) {
            return $this->fail('Not confirmed.', 'Run again with @#cyan:--yes@; to proceed.');
         }
      }

      // # The plan
      if ($target['tag'] === $current['tag'] && $current['source'] === 'describe') {
         // ! The kit sits past the release it is on: this is a return, not a move
         $commits = $current['distance'] === 1 ? '1 commit' : "{$current['distance']} commits";
         $this->say("@.;@#green:Returning the kit to@; @#cyan:{$target['tag']}@; ({$commits} back)");
      }
      elseif ($target['tag'] === $current['tag']) {
         // ! A squashed template commit: the kit stands on the release by its pin only
         $this->say("@.;@#green:Placing the kit on@; @#cyan:{$target['tag']}@; — it stood on it by the Bootgly pin only");
      }
      else {
         $verbing = $downgrade ? 'Downgrading' : 'Upgrading';
         $this->say("@.;@#green:{$verbing} the kit:@; @#cyan:{$from}@; → @#cyan:{$target['tag']}@;");
      }
      $notes = $this->clean($VCS->Tags->read($target['tag']), breaks: true);
      if ($notes !== '') {
         $this->document['notes'] = $notes;
         foreach (explode("\n", $notes) as $line) {
            $this->say("   {$line}");
         }
      }

      // # The move — the last thing this process does with the kit's files
      $state = $this->swap($VCS, $target['tag'], $head);
      // ? Nothing changed: everything may still be loaded
      if ($state === 'blocked') {
         if ($this->late === null) {
            return $this->fail('The kit could not be inspected.', 'Run @#cyan:git status@; in the kit to see what git says, and run again.');
         }
         $this->document['blockers'] = [[
            'what' => "Files {$target['tag']} would overwrite appeared meanwhile.", 'paths' => $this->late, 'fix' => 'Move them out of the kit and run again.',
         ]];
         foreach ($this->late as $path) {
            $this->say('   @#cyan:' . $this->clean($path) . '@;');
         }

         return $this->fail(
            "Files @#cyan:{$target['tag']}@; would overwrite appeared meanwhile.",
            'Nothing was changed — move them out of the kit and run again.'
         );
      }
      if ($state === 'refused') {
         return $this->fail("Could not check out @#cyan:{$target['tag']}@;.", 'The kit was left as it was — see the git output above.');
      }

      // ! From here on the kit's files are the release's: only what is already
      //   resident may run — plain lines through the Output, the JSON document —
      //   and nothing may throw into the framework's handler, which would load
      //   its classes from the swapped tree
      $moved = $state === 'moved';
      try {
         $short = substr($head, 0, 7);
         if ($state !== 'moved') {
            $mixed = $state === 'partial-checkout'
               ? "The checkout of {$target['tag']} did not fully apply."
               : "The kit is on {$target['tag']} but its submodules did not follow.";
            $remedy = $this->remedy($VCS, $short);
            $this->document['status'] = 'partial';
            $this->document['reason'] = $mixed;
            $this->document['detail'] = $this->strip($remedy);

            $this->say("@.;@#red:{$mixed}@;");
            $this->say("   Until it is complete the kit runs a mixed framework — {$remedy}");
            $this->emit();

            return false;
         }

         $this->document['status'] = 'moved';

         $this->say("@.;@#green:The kit is on@; @#cyan:{$target['tag']}@;.");
         // @ The agent rules follow the release
         $this->refresh();
         // ! A submodule the release declares that this kit never set up stays
         //   absent — `submodule update` runs without `--init` on purpose — and is named
         $pending = [];
         foreach ($VCS->Submodules->list() as $path) {
            if ($VCS->Submodules->inspect($path)['initialized'] === false) {
               $pending[] = $path;
            }
         }
         if ($pending !== []) {
            $this->document['pending'] = $pending;
            $paths = implode(' ', array_map($this->quote(...), $pending));
            $this->say("   Not set up in this kit: @#cyan:{$paths}@; — @#cyan:git submodule update --init -- {$paths}@; adds it.");
         }
         if ($running !== []) {
            $this->say(
               '   Reload the running instances to load the new files: '
               . '@#cyan:bootgly project <Name> reload@;'
            );
         }
         $this->emit();
      }
      catch (Throwable $Throwable) {
         // ! The files changed — say so with nothing but the stream
         CLI->Terminal->Output->write(
            "The kit is on {$target['tag']}" . ($moved ? '' : ' with parts missing')
            . "; reporting it failed: {$Throwable->getMessage()}" . PHP_EOL
         );
      }

      // :
      return $moved;
   }

   /**
    * Bring the agent rules in `projects/` in line with the release the kit
    * just moved to. This process still runs the outgoing code, so the NEW
    * launcher re-lays them (`kit boot --agents`, its output discarded — it
    * never reaches the stream or the JSON document); a release that predates
    * the rules takes Bootgly's away — a stamped `AGENTS.md`, `.agents/rules/`,
    * the stamped skills and the Claude links they leave dangling — and
    * nothing else. Only built-ins and this class run
    * here — it is called after the swap — and nothing it does may throw.
    */
   private function refresh (): void
   {
      try {
         $kit = rtrim($this->kit, '/');
         // ! The kit's own framework, as the release just checked it out
         $templates = "{$kit}/" . self::FRAMEWORK . '/Bootgly/commands/templates/projects/AGENTS.md';

         // ? A kit that was never booted — its first `projects create` boots it
         if (is_file("{$kit}/projects/Bootgly.projects.php") === false) {
            return;
         }

         // ? The user's own entry point or .agents/ — never the release's to touch
         if (self::claim("{$kit}/projects") === false) {
            $this->document['agents'] = 'kept';
            $this->say('   Agent rules in @#cyan:projects/@; are not Bootgly\'s — left as they are.');

            return;
         }

         // ? A release that predates the rules: the framework's go
         if (is_file($templates) === false) {
            if (self::recognize("{$kit}/projects/AGENTS.md") === false) {
               return;
            }
            // ! The rules first: while one is left, the stamped entry point stays
            //   and a later run can finish — what could not go is said
            $this->wipe("{$kit}/projects/.agents/rules");
            if (file_exists("{$kit}/projects/.agents/rules") === true || is_link("{$kit}/projects/.agents/rules") === true) {
               $this->document['agents'] = 'failed';
               $this->say('   Agent rules could not be removed from @#cyan:projects/@; — remove @#cyan:projects/.agents/rules/@; by hand.');

               return;
            }
            @unlink("{$kit}/projects/AGENTS.md");
            // @@ The skills of Bootgly's — the stamped ones; a `bootgly-*` entry
            //    of the user's stays …
            $shelf = "{$kit}/projects/.agents/skills";
            if (is_dir($shelf) === true && is_link($shelf) === false) {
               foreach ((array) @scandir($shelf) as $name) {
                  if (preg_match(self::SKILL, (string) $name) === 1 && self::own("{$shelf}/{$name}") === true) {
                     $this->wipe("{$shelf}/{$name}");
                  }
               }
               @rmdir($shelf);
            }
            // @@ … and only the Claude links of ours left dangling by that —
            //    never through a linked .claude/, never a real entry
            $links = "{$kit}/projects/.claude/skills";
            if (is_link("{$kit}/projects/.claude") === false && is_dir($links) === true && is_link($links) === false) {
               foreach ((array) @scandir($links) as $name) {
                  $path = "{$links}/{$name}";
                  if (preg_match(self::SKILL, (string) $name) === 1 && is_link($path) === true
                     && @readlink($path) === "../../.agents/skills/{$name}" && file_exists($path) === false) {
                     @unlink($path);
                  }
               }
               @rmdir($links);
               @rmdir("{$kit}/projects/.claude");
            }
            @rmdir("{$kit}/projects/.agents");
            $this->document['agents'] = 'removed';
            $this->say('   Agent rules removed from @#cyan:projects/@; — this release predates them.');

            return;
         }

         // @ The new launcher re-lays them
         $status = -1;
         if (is_file("{$kit}/bootgly") === true) {
            $process = @proc_open(
               [PHP_BINARY, "{$kit}/bootgly", 'kit', 'boot', '--agents'],
               [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
               $pipes,
               $kit
            );
            if (is_resource($process) === true) {
               $status = proc_close($process);
            }
         }

         $this->document['agents'] = $status === 0 ? 'refreshed' : 'failed';
         $this->say(
            $status === 0
               ? '   Agent rules in @#cyan:projects/@; follow the release.'
               : '   Agent rules could not be refreshed — run @#cyan:bootgly kit boot --agents@;.'
         );
      }
      catch (Throwable) {
         $this->document['agents'] = 'failed';
      }
   }

   /**
    * `kit list` — the releases, newest first, marking where the kit stands.
    *
    * @param array<string,bool|int|string> $options
    *
    * @return bool
    */
   public function list (array $options = []): bool
   {
      $opened = $this->open('list', $options, ['json']);
      if ($opened === null) {
         // ?: Inside the image the question was answered, not refused
         return ($this->document['status'] ?? null) === 'image';
      }
      ['VCS' => $VCS, 'releases' => $releases, 'current' => $current] = $opened;

      $this->document['status'] = 'listed';
      $mixed = $this->verify($VCS) !== [];
      $this->document['mixed'] = $mixed;

      // ?: Machine output
      if ($this->json === true) {
         $this->emit();

         return true;
      }

      $Output = CLI->Terminal->Output;

      // @ Rows — raw text (the Table pads bytes, not markup)
      $body = [];
      foreach ($releases as $tag => $release) {
         $status = match (true) {
            $tag !== $current['tag'] => $current['SemVer'] === null ? '' :
               ($release['SemVer']->compare($current['SemVer']) > 0 ? 'newer' : 'older'),
            $mixed === true => 'current (submodules off the pin)',
            $current['distance'] === 0 => 'current',
            $current['source'] === 'pin' => 'current (by the Bootgly pin)',
            default => "current (+{$current['distance']} commits)",
         };
         $body[] = [$tag, substr($release['commit'], 0, 7), $status];
      }

      $Table = new Table($Output);
      $Table->Data->Header->set([['Release', 'Commit', 'Status']]);
      $Table->Data->Body->set($body);

      $Output->write(PHP_EOL);
      $Table->render();
      $Output->write(PHP_EOL);

      if ($current['tag'] === null) {
         $short = substr($current['commit'], 0, 7);
         $this->say("The kit is on @#cyan:{$short}@;, which is not a release.@.;");
      }

      // :
      return true;
   }

   // # Steps
   /**
    * Open the kit: git, the checkout, the releases remote, the releases and
    * where the kit stands — the half every verb shares.
    *
    * @param string $verb
    * @param array<string,bool|int|string> $options
    * @param array<int,string> $accepted The options this verb takes.
    *
    * @return null|array{VCS:VCS,releases:array<string,array{SemVer:SemVer,commit:string,annotated:bool}>,current:array{tag:null|string,SemVer:null|SemVer,commit:string,distance:null|int,source:null|string},head:string,remote:string,verified:bool}
    *         Null after a refusal (already reported).
    */
   private function open (string $verb, array $options, array $accepted): null|array
   {
      $this->document = ['command' => 'kit', 'verb' => $verb, 'kit' => $this->kit, 'status' => 'refused', 'reason' => null];

      // ? Refuse a flag this verb does not take — as a document under `--json`,
      //   where `admit()`'s alert would break the one-document contract
      if ($this->json === true) {
         foreach ($options as $option => $value) {
            if (in_array((string) $option, [...$accepted, 'help', 'h', 'v'], true) === false) {
               $this->fail('Unknown option --' . $this->clean((string) $option) . ' for this verb.', 'Options: --json, --yes (upgrade/downgrade), -v.');

               return null;
            }
         }
      }
      elseif ($this->admit($accepted, $options) === false) {
         return null;
      }

      // # The kit
      if (Git::locate() === null) {
         $this->fail('git was not found on PATH.', 'The kit is a git checkout and moves with git — install it and run again.');

         return null;
      }
      $VCS = new VCS($this->kit);
      if ($VCS->Git->check() === false || in_array(self::FRAMEWORK, $VCS->Submodules->list(), true) === false) {
         // ? A tree with no checkout, inside an image, gets the move that
         //   exists there — never `curl | bash`, which would install a kit the
         //   next `docker run` throws away. A kit MOUNTED into a container has
         //   a checkout and moves normally.
         $place = file_exists("{$this->kit}/.git") === false
            ? $this->stand()
            : 'host';

         if ($place === 'kit' && $verb === 'list') {
            // ?: `list` is a question — answer it: the image is the release,
            //    and the other releases are image tags, not git tags
            $this->document['status'] = 'image';
            $this->document['current'] = [
               'tag' => null,
               'version' => BOOTGLY_VERSION,
               'commit' => (string) getenv('BOOTGLY_FRAMEWORK_SHA') ?: null,
               'distance' => null,
               'source' => 'image',
            ];
            $this->document['detail'] = 'Releases are image tags here: docker pull bootgly/bootgly.kit:<version>; latest follows the stable line.';
            if ($this->json) {
               $this->emit();
            }
            else {
               CLI->Terminal->Output->render(
                  '@#Green:This image ships Bootgly @_:v' . BOOTGLY_VERSION . '@;@; — releases are image tags here.@.;'
                  . 'Move with @#cyan:docker pull bootgly/bootgly.kit:<version>@; and run that tag; '
                  . '@#cyan:latest@; follows the stable line.@.;'
               );
            }

            return null;
         }
         if ($place === 'kit') {
            $this->fail(
               'The image ships the kit, not a git checkout.',
               'Releases are image tags here: @#cyan:docker pull bootgly/bootgly.kit:<version>@;. '
               . 'This image carries framework @#cyan:' . BOOTGLY_VERSION . '@; — a build from a '
               . 'branch reports its development version, which is not a tag you can pull.'
            );
            return null;
         }
         if ($place === 'framework') {
            $this->fail(
               'This image carries the framework, not a kit.',
               'The kit is its own image: @#cyan:docker run -it bootgly/bootgly.kit:<version>@;. '
               . 'Name a tag — @#cyan:latest@; exists only from the first stable release.'
            );

            return null;
         }

         $this->fail(
            'This is not a Bootgly kit.',
            '@#cyan:' . $this->clean($this->kit) . '@; — run the command from a kit installed by '
            . '@#cyan:curl -fsSL https://bootgly.com/install | bash@;.'
         );

         return null;
      }

      // # The releases remote — the repository every kit descends from
      $remote = $VCS->Remotes->find($this->repository);
      $added = false;
      if ($remote === null) {
         // ! A kit generated from the template has no upstream: give it the canonical one
         if (isSet($VCS->Remotes->list()[self::REMOTE]) === true) {
            $this->fail(
               'A remote named @#cyan:' . self::REMOTE . '@; exists but points elsewhere.',
               "Point it at @#cyan:{$this->repository}@; (@#cyan:git remote set-url " . self::REMOTE . '@;) or rename it, and run again.'
            );

            return null;
         }
         if ($VCS->Remotes->add(self::REMOTE, $this->repository) === false) {
            $this->fail('Could not add the releases remote.', "@#cyan:{$this->repository}@; as @#cyan:" . self::REMOTE . '@;.');

            return null;
         }
         $remote = self::REMOTE;
         $added = true;

         $this->say("@#green:Added the remote@; @#cyan:{$remote}@; → {$this->repository}");
      }
      $this->document['remote'] = $remote;
      $this->document['added'] = $added;
      $shown = $this->clean($remote);

      // ? The releases are code this process will run — never over a transport
      //   anyone on the path can rewrite: an allow-list, so a plaintext helper
      //   git grows tomorrow fails closed. A scheme-less URL is scp-like or a path.
      $URL = $VCS->Remotes->list()[$remote] ?? '';
      if (preg_match('#^([a-z][a-z0-9+.-]*)://#i', $URL, $scheme) === 1
         && in_array(strtolower($scheme[1]), self::TRANSPORTS, true) === false) {
         $this->fail(
            "The releases remote @#cyan:{$shown}@; uses an insecure transport.",
            "Point it at @#cyan:{$this->repository}@; (@#cyan:git remote set-url {$shown} {$this->repository}@;) and run again."
         );

         return null;
      }

      // # The releases — the remote's tags win over stale local ones
      $Output = CLI->Terminal->Output;
      $Stream = $this->verbosity > 0 && $this->json === false
         ? function (string $line) use ($Output): void { $Output->write("   {$line}" . PHP_EOL); }
         : null;
      $fetched = $VCS->Git->fetch($remote, [], $Stream) === 0;
      $this->document['fetched'] = $fetched;
      if ($fetched === false) {
         $this->warn("Could not fetch the releases from @#cyan:{$shown}@;.", 'Using the releases already known locally.');
      }

      // ! Provenance: a release is a tag the canonical remote ADVERTISES. The
      //   fetch corrects the tags it also has, but a tag from a fork, a mirror
      //   or a `git fetch --all` stays local — and would otherwise be a release
      $advertised = $fetched ? $VCS->Tags->probe($remote) : null;
      $releases = $VCS->Tags->list();
      if ($advertised !== null) {
         $releases = array_intersect_key($releases, $advertised);
      }
      elseif ($releases !== []) {
         $this->warn('The releases known locally are unverified.', "They could not be checked against @#cyan:{$shown}@; — a tag from a fork or a mirror would pass for one, so nothing moves until it is reachable.");
      }
      $this->document['verified'] = $advertised !== null;
      if ($releases === []) {
         $this->fail(
            'No release is known to this kit.',
            $fetched ? 'The repository has no tagged release yet.' : 'The remote could not be reached — check the network and run again.'
         );

         return null;
      }

      // # Where the kit stands
      $head = $VCS->Git->resolve('HEAD');
      if ($head === null) {
         $this->fail('The kit has no commit checked out.');

         return null;
      }
      $current = $this->locate($VCS, $releases, $head);
      $this->document['current'] = $this->shape($current);
      $this->document['releases'] = [];
      foreach ($releases as $tag => $release) {
         $this->document['releases'][] = [
            'tag' => $tag,
            'version' => (string) $release['SemVer'],
            'commit' => $release['commit'],
            'current' => $tag === $current['tag'],
         ];
      }

      // :
      return [
         'VCS' => $VCS, 'releases' => $releases, 'current' => $current, 'head' => $head,
         'remote' => $remote, 'verified' => $advertised !== null,
      ];
   }

   /**
    * Whether this process runs inside a container — `Container::check()`
    * over the instance's markers, so a spec can point them at fixtures. Here
    * it is trusted for nothing but the WORDING of a refusal, and only when
    * the kit has no checkout at all.
    */
   protected function check (): bool
   {
      // :
      return Container::check($this->markers);
   }
   /**
    * Where this run stands: `host`, or which image it is inside.
    *
    * The image distinction is STRUCTURAL — a kit nests the framework under
    * `Bootgly/`, so the framework root and the kit root differ there and
    * coincide in the framework image (`TestCommand` reads the same signal). An
    * environment variable would be one `docker run -e` away from wording a
    * refusal wrong. The two roots are read from the properties that hold them,
    * so a layout no checkout can reproduce is still reachable in a test.
    */
   protected function stand (): string
   {
      if ($this->check() === false) {
         return 'host';
      }

      // :
      return rtrim($this->templates, '/') !== rtrim($this->kit, '/')
         ? 'kit'
         : 'framework';
   }

   /**
    * Where the kit stands: the release on HEAD, the nearest one behind it,
    * or the one the framework submodule pins.
    *
    * A kit cloned before the kit was tagged, or generated from the template
    * (a squashed commit no tag reaches), still delivers a definite framework
    * release — its `Bootgly` gitlink — and the kit release of the same name
    * is where it stands.
    *
    * @param VCS $VCS
    * @param array<string,array{SemVer:SemVer,commit:string,annotated:bool}> $releases
    * @param string $head
    *
    * @return array{tag:null|string,SemVer:null|SemVer,commit:string,distance:null|int,source:null|string}
    */
   private function locate (VCS $VCS, array $releases, string $head): array
   {
      // # Exactly on a release
      foreach ($releases as $tag => $release) {
         if ($release['commit'] === $head) {
            return ['tag' => $tag, 'SemVer' => $release['SemVer'], 'commit' => $head, 'distance' => 0, 'source' => 'tag'];
         }
      }

      // # Past a release — version-shaped tags only, or a `nightly` nearer than
      //   the release would hide it
      $described = $VCS->Git->describe('HEAD', ['v[0-9]*', '[0-9]*']);
      if ($described !== null && isSet($releases[$described['tag']]) === true) {
         return [
            'tag' => $described['tag'],
            'SemVer' => $releases[$described['tag']]['SemVer'],
            'commit' => $head,
            'distance' => $described['distance'],
            'source' => 'describe',
         ];
      }

      // # The framework pin
      $state = $VCS->Submodules->inspect(self::FRAMEWORK);
      if ($state['pinned'] !== null && $state['initialized'] === true) {
         $Framework = new VCS("{$this->kit}/" . self::FRAMEWORK, $VCS->Git->binary);
         foreach ($Framework->Tags->list() as $tag => $release) {
            if ($release['commit'] !== $state['pinned']) {
               continue;
            }

            return [
               'tag' => isSet($releases[$tag]) ? $tag : null,
               'SemVer' => $release['SemVer'],
               'commit' => $head,
               'distance' => null,
               'source' => 'pin',
            ];
         }
      }

      // :
      return ['tag' => null, 'SemVer' => null, 'commit' => $head, 'distance' => null, 'source' => null];
   }

   /**
    * Pick the release to move to.
    *
    * @param array<string,array{SemVer:SemVer,commit:string,annotated:bool}> $releases Newest first.
    * @param array{tag:null|string,SemVer:null|SemVer,commit:string,distance:null|int,source:null|string} $current
    * @param null|string $named The release the caller named, if any.
    * @param bool $downgrade
    *
    * @return array{target?:array{tag:string,SemVer:SemVer,commit:string},noop?:string,refusal?:array{string,string}}
    */
   private function select (array $releases, array $current, null|string $named, bool $downgrade): array
   {
      $other = $downgrade ? 'upgrade' : 'downgrade';
      $standing = $current['tag'] ?? ($current['SemVer'] === null ? '' : (string) $current['SemVer']);

      // # A release by name
      if ($named !== null && $named !== '') {
         $shown = $this->clean($named);
         $Wanted = SemVer::parse($named);
         if ($Wanted === null) {
            return ['refusal' => ["@#cyan:{$shown}@; is not a release name.", 'See @#cyan:bootgly kit list@; for the releases this kit can move to.']];
         }

         $target = null;
         foreach ($releases as $tag => $release) {
            if ($release['SemVer']->compare($Wanted) === 0) {
               $target = ['tag' => $tag, 'SemVer' => $release['SemVer'], 'commit' => $release['commit']];

               break;
            }
         }
         if ($target === null) {
            return ['refusal' => ["No release @#cyan:{$shown}@; is known to this kit.", 'See @#cyan:bootgly kit list@; for the releases it can move to.']];
         }

         // ? The verb must match the direction
         if ($current['SemVer'] !== null) {
            $order = $target['SemVer']->compare($current['SemVer']);
            if ($downgrade === false && $order < 0) {
               return ['refusal' => ["@#cyan:{$target['tag']}@; is older than the kit's @#cyan:{$standing}@;.", "Use @#cyan:bootgly kit {$other} {$target['tag']}@; to go back to it."]];
            }
            if ($downgrade === true && $order > 0) {
               return ['refusal' => ["@#cyan:{$target['tag']}@; is newer than the kit's @#cyan:{$standing}@;.", "Use @#cyan:bootgly kit {$other} {$target['tag']}@; to move up to it."]];
            }
         }

         return ['target' => $target];
      }

      // # The previous release
      if ($downgrade === true) {
         if ($current['SemVer'] === null) {
            return ['refusal' => ['The kit is not on a release, so there is no previous one.', 'Name it: @#cyan:bootgly kit downgrade <release>@; (see @#cyan:bootgly kit list@;).']];
         }
         foreach ($releases as $tag => $release) {
            if ($release['SemVer']->compare($current['SemVer']) < 0) {
               return ['target' => ['tag' => $tag, 'SemVer' => $release['SemVer'], 'commit' => $release['commit']]];
            }
         }

         return ['noop' => "@#cyan:{$standing}@; is the earliest release — nothing to downgrade to."];
      }

      // # The newest release
      $tag = (string) array_key_first($releases);
      $Newest = $releases[$tag];
      $target = ['tag' => $tag, 'SemVer' => $Newest['SemVer'], 'commit' => $Newest['commit']];
      if ($current['SemVer'] === null) {
         return ['target' => $target];
      }
      $order = $Newest['SemVer']->compare($current['SemVer']);
      if ($order < 0) {
         return ['noop' => "The kit is on @#cyan:{$standing}@;, newer than every release known — nothing to upgrade to."];
      }
      // ? On the newest already — unless the kit sits past it, or only its pin says so
      if ($order === 0 && $current['distance'] === 0) {
         return ['noop' => "The kit is already on the newest release, @#cyan:{$tag}@;."];
      }

      // :
      return ['target' => $target];
   }

   /**
    * What must not be overwritten: the kit's own changes, the files the
    * release would replace, and the submodules'.
    *
    * `git checkout` refuses to clobber a tracked edit and an untracked file,
    * but it silently overwrites an IGNORED one — and everything the user
    * owns (`projects/`, `storage/`, ...) is ignored. So every path the
    * release carries that is not in the current tree is checked on disk: a
    * file there, tracked or not, ignored or not, is one the release would
    * replace. A dirty project never blocks unless the release carries that
    * very path.
    *
    * @param VCS $VCS
    * @param string $tag The release to move to.
    *
    * @return list<array{what:string,paths:list<string>,fix:string}>
    */
   private function guard (VCS $VCS, string $tag): array
   {
      $reasons = [];

      // # The kit's own files
      $changes = $VCS->Git->inspect();
      $colliding = $this->collide($VCS, $tag);
      // ? An inspection that fails is a blocker, not a clean tree
      if ($changes === null || $colliding === null) {
         return [[
            'what' => 'The kit could not be inspected.',
            'paths' => [],
            'fix' => 'Run @#cyan:git status@; in the kit to see what git says, and run again.',
         ]];
      }

      $tracked = [];
      foreach ($changes as $path => $code) {
         if ($code !== '??') {
            $tracked[] = $path;
         }
      }
      if ($tracked !== []) {
         $reasons[] = [
            'what' => 'The kit has uncommitted changes.',
            'paths' => $tracked,
            'fix' => 'Commit or discard them (@#cyan:git stash@; keeps them aside) and run again.',
         ];
      }

      if ($colliding !== []) {
         $reasons[] = [
            'what' => "Files @#cyan:{$tag}@; would overwrite.",
            'paths' => $colliding,
            'fix' => 'Move them out of the kit and run again.',
         ];
      }

      // # The submodules
      foreach ($VCS->Submodules->list() as $path) {
         $state = $VCS->Submodules->inspect($path);
         if ($state['initialized'] === false) {
            continue;
         }
         $shown = $this->clean($path);

         if ($state['pinned'] !== null && $state['committed'] !== null && $state['pinned'] !== $state['committed']) {
            $reasons[] = [
               'what' => "The pin of @#cyan:{$shown}@; is staged — being replaced by hand.",
               'paths' => [],
               'fix' => "Commit it or unstage it (@#cyan:git reset -- {$shown}@;) and run again.",
            ];
         }
         if ($state['head'] !== null && $state['pinned'] !== null && $state['head'] !== $state['pinned']) {
            $reasons[] = [
               'what' => "@#cyan:{$shown}@; is checked out away from the kit's pin.",
               'paths' => [substr($state['head'], 0, 7) . ' checked out, ' . substr($state['pinned'], 0, 7) . ' pinned'],
               'fix' => "Return it (@#cyan:git submodule update -- {$shown}@;) or commit the new pin, and run again.",
            ];
         }
         if ($state['changes'] === null) {
            $reasons[] = [
               'what' => "@#cyan:{$shown}@; could not be inspected.",
               'paths' => [],
               'fix' => "Run @#cyan:git -C {$shown} status@; to see what git says, and run again.",
            ];
         }
         elseif ($state['changes'] !== []) {
            $reasons[] = [
               'what' => "@#cyan:{$shown}@; has uncommitted changes.",
               'paths' => array_keys($state['changes']),
               'fix' => "Commit or discard them (@#cyan:git -C {$shown} stash@;) and run again.",
            ];
         }
      }

      // :
      return $reasons;
   }

   /**
    * Map the entries a tree carries — every file and gitlink, with its mode.
    *
    * `-z` and no trimming: git C-quotes every non-ASCII path otherwise
    * (`"caf\303\251.json"` exists nowhere), and a path may begin or end
    * with a space. The mode tells a blob (`100644`, `100755`, `120000`)
    * from a gitlink (`160000`): a submodule's checkout is a directory on
    * disk and survives the release that drops it.
    *
    * @param VCS $VCS
    * @param string $reference The tree — `HEAD`, `refs/tags/<tag>`.
    *
    * @return null|array<string,string> Path => mode; null when git could not list it.
    */
   private function map (VCS $VCS, string $reference): null|array
   {
      // ?
      if ($VCS->Git->execute(['ls-tree', '-r', '-z', '--full-tree', $reference]) !== 0) {
         return null;
      }

      // @@ `<mode> <type> <hash>\t<path>`
      $entries = [];
      foreach (explode("\0", $VCS->Git->output) as $line) {
         if (preg_match('/^(\d{6}) \S+ \S+\t(.+)$/s', $line, $matches) === 1) {
            $entries[$matches[2]] = $matches[1];
         }
      }

      // :
      return $entries;
   }

   /**
    * The files a release would replace: what its tree brings that the
    * current tree does not, and that is on disk — the user's, untracked or
    * ignored (`git checkout` overwrites an ignored file without a word).
    *
    * @param VCS $VCS
    * @param string $tag The release to move to.
    *
    * @return null|list<string> The colliding paths; null when a tree could not be read.
    */
   private function collide (VCS $VCS, string $tag): null|array
   {
      $current = $this->map($VCS, 'HEAD');
      $target = $this->map($VCS, "refs/tags/{$tag}");
      // ?
      if ($current === null || $target === null) {
         return null;
      }

      $colliding = [];
      foreach ($target as $entry => $mode) {
         // ! A gitlink is a directory the checkout leaves alone — never a collision
         if ($mode === '160000') {
            continue;
         }
         if (isSet($current[$entry]) === false && file_exists("{$this->kit}/{$entry}") === true) {
            $colliding[] = $entry;
         }
      }

      // :
      return $colliding;
   }

   /**
    * The initialized submodules that sit off the kit's pin — a move that did
    * not complete, whoever left it so.
    *
    * @param VCS $VCS
    *
    * @return list<array{path:string,registered:bool}>
    */
   private function verify (VCS $VCS): array
   {
      $off = [];
      foreach ($VCS->Submodules->list() as $path) {
         $state = $VCS->Submodules->inspect($path);
         if ($state['initialized'] === false || $state['pinned'] === null) {
            continue;
         }
         if ($state['head'] !== $state['pinned']) {
            $off[] = ['path' => $path, 'registered' => $state['registered']];
         }
      }

      // :
      return $off;
   }

   /**
    * A kit already on its release whose submodules never followed: `partial`
    * again, never `noop` — a retry after a failed move must not read as
    * "the update landed".
    *
    * @param VCS $VCS
    *
    * @return bool Always false.
    */
   private function linger (VCS $VCS): bool
   {
      $remedy = $this->remedy($VCS, '');
      $this->document['status'] = 'partial';
      $this->document['reason'] = 'The kit is on its release but its submodules sit off the pin.';
      $this->document['detail'] = $this->strip($remedy);

      $Alert = new Alert(CLI->Terminal->Output);
      $Alert->Type::Failure->set();
      $Alert->message = 'The kit is on its release but its submodules sit off the pin.';
      $this->render($Alert);
      $this->say("   The kit runs a mixed framework — {$remedy}");
      $this->emit();

      // :
      return false;
   }

   /**
    * The way out of a mixed kit, worded for what is actually wrong.
    *
    * A submodule the kit's `.git/config` never registered — a directory the
    * user populated at a path a release later turned into a submodule — is
    * not moved by `git submodule update`: only `--init` registers it, and the
    * directory there is not that submodule.
    *
    * @param VCS $VCS
    * @param string $short The commit to go back to, abbreviated; empty for none.
    *
    * @return string A sentence of markup.
    */
   private function remedy (VCS $VCS, string $short): string
   {
      $unregistered = [];
      foreach ($this->verify($VCS) as $state) {
         if ($state['registered'] === false) {
            $unregistered[] = $this->quote($state['path']);
         }
      }

      $back = $short === '' ? '' : ", or go back with @#cyan:git checkout {$short}@;";
      // ?:
      if ($unregistered !== []) {
         $paths = implode(' ', $unregistered);

         return "the directory at @#cyan:{$paths}@; is not that submodule — move it away and run "
            . "@#cyan:git submodule update --init -- {$paths}@; in the kit{$back}.";
      }

      // :
      return "run @#cyan:git status@; and @#cyan:git submodule update@; in the kit{$back}.";
   }

   /**
    * Does a release predate this command — leaving no `kit upgrade` to come back with?
    *
    * Read from the framework the release pins: a kit moved onto it runs THAT
    * framework, and one without this file has no `kit` verb at all.
    *
    * @param VCS $VCS
    * @param string $tag The release to move to.
    *
    * @return bool True when the framework pinned by `$tag` lacks this command.
    */
   private function predate (VCS $VCS, string $tag): bool
   {
      // ! The framework commit the release pins
      $pin = $VCS->Git->query(['rev-parse', '--verify', '--quiet', "refs/tags/{$tag}:" . self::FRAMEWORK]);
      if ($pin === null || preg_match(self::HASH, $pin) !== 1) {
         return false;
      }
      // ? Only a framework checked out can be read — and the one running always is
      if (file_exists("{$this->kit}/" . self::FRAMEWORK . '/.git') === false) {
         return false;
      }

      $Framework = new VCS("{$this->kit}/" . self::FRAMEWORK, $VCS->Git->binary);
      // ? The commit may not be fetched yet — then nothing can be said
      if ($Framework->Git->resolve($pin) === null) {
         return false;
      }

      // :
      return $Framework->Git->execute(['cat-file', '-e', "{$pin}:Bootgly/commands/KitCommand.php"]) !== 0;
   }

   /**
    * The instances running out of this kit, by project.
    *
    * @return list<string> `Project` or `Project (instance)`.
    */
   protected function scan (): array
   {
      $running = [];
      foreach (array_keys(Projects::read()) as $path) {
         $path = (string) $path;
         foreach (array_keys(States::scan(Projects::encode($path))) as $qualifier) {
            $qualifier = (string) $qualifier;
            $running[] = $qualifier === '' ? $path : "{$path} ({$qualifier})";
         }
      }

      // :
      return $running;
   }

   /**
    * Swap the kit's files: check out the release, then let the submodules follow.
    *
    * This runs out of the very files it replaces, so it is the last step and
    * nothing that comes after it may autoload a class — a half-old, half-new
    * framework is exactly what an update must never execute. Everything the
    * rest of the run touches is therefore made resident FIRST: the agent
    * detection the version footer performs after every command, and the
    * Output markup the closing lines use, rendered once into memory.
    *
    * `git checkout` exits 0 with part of the tree unwritten (a read-only
    * directory, a file held open, a full disk), so its exit status is not
    * the verdict: the tree is re-inspected — `guard()` proved it clean a
    * moment ago, so any tracked change now means the release did not fully
    * land — and so is every submodule after `git submodule update`.
    *
    * @param VCS $VCS
    * @param string $tag The release to move to.
    * @param string $head The commit the kit is leaving.
    *
    * @return string `moved`; `blocked` (a file the release would replace appeared since the
    *                guards ran — nothing changed); `refused` (the checkout was rejected and
    *                nothing changed); `partial-checkout` (the kit's tree did not fully land);
    *                `partial-submodules` (the submodules did not follow).
    */
   private function swap (VCS $VCS, string $tag, string $head): string
   {
      // ? The guards ran before the confirmations — a prompt is unbounded time, and
      //   an instance the user chose to keep running writes into the ignored dirs
      clearstatcache(true);
      $this->late = $this->collide($VCS, $tag);
      $outgoing = $this->map($VCS, 'HEAD');
      $incoming = $this->map($VCS, "refs/tags/{$tag}");
      if ($outgoing === null || $incoming === null) {
         $this->late = null;

         return 'blocked';
      }
      if ($this->late === null || $this->late !== []) {
         return 'blocked';
      }

      // ! Resident before the swap: the agent detection the version footer runs
      //   after every command (the closing lines render through what the plan
      //   lines already loaded — under `--json` nothing renders at all)
      Agent::detect();
      // ! Every existence check from here on must see the disk, not PHP's stat
      //   and realpath caches — the guards stat'ed these paths a moment ago, and
      //   the checkout is about to change what they are. Unconditional hygiene:
      //   no test can tell these calls apart, and none is to be "optimized" away
      clearstatcache(true);

      $Output = CLI->Terminal->Output;
      $Stream = function (string $line) use ($Output): void {
         if ($this->json === false) {
            $Output->write("   {$line}" . PHP_EOL);
         }
      };

      // @ The kit
      $status = $VCS->Git->checkout("refs/tags/{$tag}", $Stream);
      clearstatcache(true);
      // ? git never ran — nothing changed
      if ($status === 126) {
         return 'refused';
      }
      $landed = $VCS->Git->resolve('HEAD');
      $changes = $VCS->Git->inspect();
      $dirty = $changes === null;
      foreach ($changes ?? [] as $code) {
         $dirty = $dirty || $code !== '??';
      }
      // ? Rejected before touching anything — the one outcome that leaves the old tree whole
      if ($status !== 0 && $landed === $head && $dirty === false) {
         return 'refused';
      }
      // ! What the outgoing release carried and the incoming one does not must be
      //   gone: git warns — and exits 0 — when it cannot unlink a file. A file
      //   only: a gitlink's checkout is a directory that legitimately stays, and
      //   so does a directory that took a blob's place
      $leftover = false;
      foreach ($outgoing as $path => $mode) {
         if ($mode === '160000' || isSet($incoming[$path]) === true) {
            continue;
         }
         if (is_file("{$this->kit}/{$path}") === true || is_link("{$this->kit}/{$path}") === true) {
            $leftover = true;

            break;
         }
      }
      if ($landed !== $VCS->Git->resolve("refs/tags/{$tag}") || $dirty === true || $leftover === true) {
         return 'partial-checkout';
      }

      // @ The submodules follow the index
      $status = $VCS->Submodules->update($Stream);
      clearstatcache(true);
      if ($status !== 0) {
         return 'partial-submodules';
      }
      foreach ($VCS->Submodules->list() as $path) {
         $state = $VCS->Submodules->inspect($path);
         if ($state['initialized'] === false) {
            continue;
         }
         if ($state['head'] !== $state['pinned'] || $state['changes'] !== []) {
            return 'partial-submodules';
         }
      }

      // :
      return 'moved';
   }

   // # Output
   /**
    * One release for the JSON document.
    *
    * @param array{tag:null|string,SemVer:null|SemVer,commit:string,distance:null|int,source:null|string} $release
    *
    * @return null|array{tag:null|string,version:null|string,commit:string,distance:null|int,source:null|string}
    */
   private function shape (array $release): null|array
   {
      // ?:
      if ($release['SemVer'] === null) {
         return null;
      }

      // :
      return [
         'tag' => $release['tag'],
         'version' => (string) $release['SemVer'],
         'commit' => $release['commit'],
         'distance' => $release['distance'],
         'source' => $release['source'],
      ];
   }

   /**
    * Write the JSON document — the one output of a `--json` run.
    */
   private function emit (): void
   {
      // ?
      if ($this->json === false) {
         return;
      }

      // ! Never a throw: a malformed byte in a path or a note becomes U+FFFD
      $document = json_encode($this->document, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

      CLI->Terminal->Output->write(($document === false ? '{"command":"kit","status":"unknown"}' : $document) . PHP_EOL);
   }

   /**
    * Render an alert — unless the run is a JSON one.
    */
   private function render (Alert $Alert): void
   {
      if ($this->json === false) {
         $Alert->render();
      }
   }

   /**
    * Write one line of markup — unless the run is a JSON one.
    */
   private function say (string $line): void
   {
      if ($this->json === false) {
         CLI->Terminal->Output->render($line . '@.;');
      }
   }

   /**
    * An attention alert — the verdict, clipped to the terminal; the consequence
    * on its own unclipped line.
    */
   private function warn (string $message, string $detail = ''): void
   {
      $Alert = new Alert(CLI->Terminal->Output);
      $Alert->Type::Attention->set();
      $Alert->message = $message;
      $this->render($Alert);
      if ($detail !== '') {
         $this->say("   {$detail}");
      }
   }

   /**
    * Refuse: a failure alert (or the reason in the JSON document), exit false.
    *
    * The alert clips to the terminal width, so it carries the verdict only;
    * what to do about it goes on the line below, unclipped.
    *
    * @param string $reason The verdict — short.
    * @param string $detail The way out, rendered on its own line.
    * @param bool $quiet The alert was already rendered — record the reason only.
    *
    * @return bool Always false.
    */
   private function fail (string $reason, string $detail = '', bool $quiet = false): bool
   {
      $this->document['status'] = 'refused';
      $this->document['reason'] = $this->strip($reason);
      if ($detail !== '') {
         $this->document['detail'] = $this->strip($detail);
      }

      if ($quiet === false) {
         $Alert = new Alert(CLI->Terminal->Output);
         $Alert->Type::Failure->set();
         $Alert->message = $reason;
         $this->render($Alert);
         if ($detail !== '') {
            $this->say("   {$detail}");
         }
      }
      $this->emit();

      // :
      return false;
   }

   /**
    * Nothing to do: say so, exit true.
    *
    * @param string $reason
    *
    * @return bool Always true.
    */
   private function skip (string $reason): bool
   {
      $this->document['status'] = 'noop';
      $this->document['reason'] = $this->strip($reason);

      $this->say("@.;{$reason}");
      $this->emit();

      // :
      return true;
   }

   /**
    * A path as it goes into a printed command: cleaned, and quoted when a
    * shell would split it.
    */
   private function quote (string $path): string
   {
      $path = $this->clean($path);

      // :
      return preg_match('/\s/', $path) === 1 ? "'" . str_replace("'", "'\\''", $path) . "'" : $path;
   }

   /**
    * Strip the Output markup from a message, for the JSON document.
    */
   private function strip (string $markup): string
   {
      // : `@#cyan:` / `@_:` open a style, `@;` closes it, `@.;` breaks a line, `@---;` rules
      return preg_replace('/@(?:[#_]\w*:|\.+;|-+;|;)/', '', $markup) ?? $markup;
   }
}
