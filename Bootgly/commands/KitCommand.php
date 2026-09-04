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
use const PHP_EOL;
use function array_intersect_key;
use function array_key_first;
use function array_keys;
use function array_map;
use function array_slice;
use function clearstatcache;
use function copy;
use function explode;
use function file_exists;
use function getenv;
use function getmypid;
use function implode;
use function in_array;
use function is_dir;
use function is_file;
use function is_link;
use function json_encode;
use function mkdir;
use function preg_match;
use function preg_replace;
use function realpath;
use function rename;
use function rmdir;
use function rtrim;
use function str_pad;
use function str_replace;
use function strlen;
use function strtolower;
use function substr;
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
         'description' => 'Lay down the kit\'s resource directories (projects, scripts, storage)',
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
      'The resource directories to lay down — the default and, today, the only set (boot)' => ['--resources'],
   ];
   // # Kit
   /** The kit root — the launcher's directory. */
   protected string $kit = BOOTGLY_WORKING_DIR;
   /** Where the releases come from. */
   protected string $repository = self::REPOSITORY;
   /** Where `boot` takes the resource templates from — the framework checkout. */
   protected string $templates = BOOTGLY_ROOT_DIR;
   /** @var array<int,string> Files a container runtime leaves behind — Docker, then Podman. */
   protected array $markers = ['/.dockerenv', '/run/.containerenv'];

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
    * `projects create` and `import` run this on a fresh kit by themselves.
    *
    * @param array<string,bool|int|string> $options
    *
    * @return bool
    */
   public function boot (array $options = []): bool
   {
      // ? Refuse a flag this verb does not take
      if ($this->admit(['resources'], $options) === false) {
         return false;
      }

      $Output = CLI->Terminal->Output;
      $kit = rtrim($this->kit, '/') ?: '/';

      $Output->render('@.;@#green:Booting resource directories...@;@.;');

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
      if (is_dir("{$kit}/scripts") === false) {
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
      if (is_dir("{$kit}/storage") === false) {
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
      if (is_file($registry) === false) {
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
      }

      $Output->render('@#green:OK@;@.;');
      $Output->write(PHP_EOL);

      // :
      return true;
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

      $Entries = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::CHILD_FIRST
      );
      foreach ($Entries as $Entry) {
         /** @var SplFileInfo $Entry */
         $Entry->isDir() === true && $Entry->isLink() === false ? @rmdir($Entry->getPathname()) : @unlink($Entry->getPathname());
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
         return false;
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
    * Whether this process runs inside a container.
    *
    * The image sets `BOOTGLY_DOCKER`; the markers cover an image built
    * elsewhere. None of it is trusted for anything but the WORDING of a
    * refusal, and only when the kit has no checkout at all.
    */
   protected function check (): bool
   {
      if ((string) getenv('BOOTGLY_DOCKER') !== '') {
         return true;
      }

      foreach ($this->markers as $marker) {
         if (file_exists($marker) === true) {
            return true;
         }
      }

      // :
      return false;
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

   /**
    * Clean text that came from outside — a tag annotation, a path, a remote
    * name, the caller's own argument — before it enters a rendered line or
    * the JSON document.
    *
    * Control characters, C0 and C1 alike, would drive the terminal (title,
    * colours, erased lines); an `@` that could open or close Output markup —
    * one not followed by a letter or a digit (`@#`, `@;`, `@.`, `@:`, `@@`,
    * `@*`, `@\\`), or one right after `*`, `~`, `_`, `-` (the closers) — would
    * drive it and goes; a plain `@` between word characters, legal in a path
    * and in a ref, stays; a byte that is not UTF-8 would make the JSON
    * encoder throw.
    * Line breaks go too, unless the text is a multi-line note.
    *
    * @param string $text
    * @param bool $breaks Keep line feeds.
    *
    * @return string
    */
   private function clean (string $text, bool $breaks = false): string
   {
      // ! C0, C1, and the zero-width / bidi format characters that disguise a path
      $invisible = '\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{2064}\x{2066}-\x{206F}\x{FEFF}';
      $controls = $breaks
         ? "/[\\x00-\\x09\\x0B-\\x1F\\x7F\\x{80}-\\x{9F}{$invisible}]/u"
         : "/[\\x00-\\x1F\\x7F\\x{80}-\\x{9F}{$invisible}]/u";
      $cleaned = preg_replace($controls, '', $text);
      // ? Not UTF-8 at all: keep printable ASCII (and the break) only
      if ($cleaned === null) {
         $cleaned = preg_replace($breaks ? '/[^\x0A\x20-\x7E]/' : '/[^\x20-\x7E]/', '?', $text) ?? '';
      }

      // :
      return preg_replace('/(?<=[*~_-])@|@(?![\p{L}\p{N}])/u', '', $cleaned) ?? str_replace('@', '', $cleaned);
   }
}
