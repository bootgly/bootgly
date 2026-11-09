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
use const BOOTGLY_WORKING_DIR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const PHP_BINARY;
use const PHP_EOL;
use function array_map;
use function array_slice;
use function basename;
use function constant;
use function count;
use function defined;
use function dirname;
use function explode;
use function fclose;
use function function_exists;
use function fwrite;
use function getcwd;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_writable;
use function json_encode;
use function lstat;
use function proc_close;
use function proc_open;
use function realpath;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strrpos;
use function substr;
use function ucfirst;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ABI\IO\FS\File;
use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Issue;
use Bootgly\ABI\Syntax\Analyzers\Result;
use Bootgly\ABI\Syntax\Analyzers\Rules;
use Bootgly\ABI\Syntax\Builtins;
use Bootgly\ABI\Syntax\Casing;
use Bootgly\ABI\Syntax\Imports;
use Bootgly\ABI\Syntax\Lint;
use Bootgly\ABI\Syntax\Lint\Scope;
use Bootgly\ABI\Syntax\Methods;
use Bootgly\ABI\Syntax\Nullables;
use Bootgly\ABI\Syntax\Promotions;
use Bootgly\ABI\Syntax\Sections;
use Bootgly\ABI\Syntax\Spacing;
use Bootgly\API\Environment\Agent;
use Bootgly\API\Environment\Workspaces;
use Bootgly\API\Projects;
use Bootgly\CLI\Command;
use Bootgly\CLI\UI\Base\Fieldset;
use Bootgly\CLI\UI\Components\Alert;


class LintCommand extends Command
{
   // * Config
   public int $group = 1;

   // * Data
   public string $name = 'lint';
   public string $description = 'Lint and fix code style violations';

   /**
    * The subcommands: `check` runs the rules a `bootgly.Lint.php` enables;
    * the per-rule subcommands are deprecated wrappers over it, kept through
    * 1.x, each restricted to its own rule.
    *
    * @var array<string,array{description:string,deprecated:bool,arguments:array<string,string>}>
    */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'check' => [
         'description' => 'Check the rules bootgly.Lint.php enables (none: imports, nullables, promotions, methods)',
         'deprecated'  => false,
         'arguments'   => [
            '[path]' => 'File or directory inside the configured paths (default: every configured path; no bootgly.Lint.php: Bootgly/, in a kit the current directory under projects/)'
         ]
      ],
      'imports' => [
         'description' => 'Deprecated — use `bootgly lint check` (import rule only)',
         'deprecated'  => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'nullables' => [
         'description' => 'Deprecated — use `bootgly lint check` (nullable rule only)',
         'deprecated'  => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'promotions' => [
         'description' => 'Deprecated — use `bootgly lint check` (promotion rule only)',
         'deprecated'  => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'methods' => [
         'description' => 'Deprecated — use `bootgly lint check` (method naming rule only)',
         'deprecated'  => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
   ];

   /** @var array<string,array<string>> */
   public array $options = [
      // Global options
      'Increase the verbosity of the command' => ['-v', '-vv', '-vvv'],
      'Show help information' => ['--help', '-h'],
      // Local options
      'Auto-fix violations' => ['--fix'],
      'Show changes without writing' => ['--dry-run'],
   ];
   // # Pinning
   /** The running framework's root — a kit pins it as the `Bootgly/` submodule. */
   protected string $framework = BOOTGLY_ROOT_BASE;

   // * Metadata
   /**
    * The analyzer behind each rule, and whether it also formats — `--fix`
    * and `--dry-run` act only on the fixable rules.
    *
    * @var array<string,array{class-string<Analyzers>,bool}>
    */
   private const array FACADES = [
      'imports'    => [Imports::class, true],
      'nullables'  => [Nullables::class, true],
      'promotions' => [Promotions::class, false],
      'methods'    => [Methods::class, false],
      'spacing'    => [Spacing::class, true],
      'casing'     => [Casing::class, false],
      'sections'   => [Sections::class, false],
   ];


   public function run (array $arguments = [], array $options = []): bool
   {
      // @ Route subcommand
      $submodule = $arguments[0] ?? null;

      if ( $submodule === null || !isset($this->arguments[$submodule]) ) {
         return $this->help($arguments);
      }

      // : `check`, or a deprecated wrapper restricted to its rule
      return $this->check(
         array_slice($arguments, 1),
         $options,
         $submodule === 'check' ? null : Rules::from($submodule)
      );
   }

   // # Check
   /**
    * Lint the configured trees with the configured rules — or, from a
    * deprecated subcommand, with that one rule.
    *
    * @param array<int,string> $arguments
    * @param array<string,mixed> $options
    * @param null|Rules $only The rule of a deprecated subcommand, null for `check`
    */
   private function check (array $arguments, array $options, null|Rules $only): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Agent detection
      $Agent = Agent::detect();

      // ! Options
      $fix = isset($options['fix']);
      $dryRun = isset($options['dry-run']);
      $mode = $fix ? 'fix' : ($dryRun ? 'dry-run' : 'check');

      // ! What the agent document opens with — the legacy shape for a wrapper
      $head = $only !== null
         ? [
            'submodule'  => $only->value,
            'fixable'    => self::FACADES[$only->value][1],
            'deprecated' => 'use bootgly lint check',
         ]
         : ['command' => 'check'];

      // ! Configuration — the nearest bootgly.Lint.php, or the defaults; a
      //   broken file fails the run, it never falls back
      $kit = Workspaces::detect() === Workspaces::Kit;
      $cwd = getcwd();
      $file = Lint::discover($cwd !== false ? $cwd : BOOTGLY_WORKING_DIR, BOOTGLY_WORKING_DIR);
      $Lint = null;
      $base = null;
      if ($file !== null) {
         $relativeFile = str_replace(BOOTGLY_WORKING_DIR, '', $file);
         try {
            $Lint = Lint::load($file);
         }
         catch (Throwable $Throwable) {
            return $this->refuse($Agent, $head, $mode, "Malformed {$relativeFile}: {$Throwable->getMessage()}");
         }
         $base = dirname($file);
      }

      // ! Rules
      /** @var array<int,Rules> $rules */
      $rules = $only !== null
         ? [$only]
         : ($Lint !== null ? $Lint->rules : [Rules::Imports, Rules::Nullables, Rules::Promotions, Rules::Methods]);
      if ($only === null) {
         $head['rules'] = array_map(static fn (Rules $Rule): string => $Rule->value, $rules);
         $head['config'] = $file !== null ? str_replace(BOOTGLY_WORKING_DIR, '', $file) : null;
      }
      $fixable = false;
      foreach ($rules as $Rule) {
         $fixable = $fixable || self::FACADES[$Rule->value][1];
      }

      // ? Only check-only rules: say so, then run the check
      $refused = $fixable === false && ($fix || $dryRun);
      if ($refused) {
         $fix = false;
         $dryRun = false;
         $mode = 'check';
      }

      // ! Path — in a kit it follows the working directory; elsewhere the
      //   working base, with the framework source as the default
      $path = $arguments[0] ?? null;
      if ($kit === true) {
         $path = $this->resolve($path);
      }
      else if ($path === null) {
         $path = ($Lint !== null && $only === null) ? null : BOOTGLY_WORKING_DIR . 'Bootgly/';
      }
      else if (!str_starts_with($path, '/')) {
         // # Under a bootgly.Lint.php, found from the working directory, a
         //   relative path is read from there too
         $path = ($Lint !== null && $only === null && $cwd !== false)
            ? "{$cwd}/{$path}"
            : BOOTGLY_WORKING_DIR . $path;
      }
      // # In a kit the resolved path narrows too: no path inside projects/ is
      //   the project the caller stands in, never every project the
      //   configuration holds — unless it stands where the configuration
      //   lies, which is the full run (dead exemptions included)
      $narrowed = ($arguments[0] ?? null) !== null
         || ($kit === true && $path !== null && realpath($path) !== realpath((string) $base));

      // ! Targets — the configured paths (narrowed by the path given) for
      //   `check` under a bootgly.Lint.php; the path itself otherwise
      $targets = [];
      $message = null;
      if ($Lint !== null && $only === null) {
         [$targets, $message] = $this->narrow($Lint, (string) $base, $narrowed ? $path : null);
      }
      else if ($path !== null) {
         // # A deprecated wrapper under a bootgly.Lint.php takes its path as
         //   given, spelled as the configuration spells it — the excludes and
         //   the exemptions must match it whatever `..` or link it went through
         $targets = [$Lint !== null ? $this->spell($Lint, (string) $base, $path) : $path];
      }

      // ? A kit has no default outside projects/, and `--fix` never rewrites
      //   a pinned tree — the kit's next update would refuse it dirty
      $message ??= match (true) {
         $targets === [] && $cwd === false
            => 'The working directory no longer exists — pass an absolute path.',
         $targets === []
            => 'No default path outside projects/ in a kit — pass a path, or run it from a project directory (projects/<Name>/).',
         default => null
      };
      if ($message === null && $fix === true) {
         foreach ($targets as $target) {
            if ($this->overlap($target, $Lint, $base) === true) {
               $message = '--fix never rewrites a pinned tree — Bootgly/, Console/ and Web/ are read-only submodules of the kit.';
               break;
            }
         }
      }
      if ($message !== null) {
         return $this->refuse($Agent, $head, $mode, $message);
      }

      // @ Collect PHP files — each with the real directory a rewrite must
      //   still land in: the tree it was collected from
      /** @var array<string,null|string> $files file => scope */
      $files = [];
      $missing = [];
      /** @var array<string,true> $links */
      $links = [];
      foreach ($targets as $target) {
         if (is_dir($target) === false && is_file($target) === false) {
            $missing[] = str_replace(BOOTGLY_WORKING_DIR, '', $target);
            continue;
         }
         // ! A file named through a link: refused at the scan, said as such
         if (is_link($target) && is_file($target)) {
            $links[$target] = true;
         }
         $scope = realpath(is_dir($target) ? $target : dirname($target));
         $scope = ($scope === false) ? null : rtrim($scope, '/');
         // ! The built-in skips (vendor/, examples/, vs/) hold wherever the
         //   configuration does not govern: no file, or a target outside its directory
         $defaults = $Lint === null || $this->relate(rtrim($target, '/') . '/x', $base) === null;
         foreach ($this->collect($target, $defaults) as $collected) {
            // ? Excluded by the configuration
            $local = $this->relate($collected, $base);
            if ($Lint !== null && $local !== null && $Lint->exclude($local)) {
               continue;
            }
            $files[$collected] ??= $scope;
         }
      }

      // ? Nothing to scan: an existing path without PHP files passes, a path
      //   that is not there fails — a gate must never go green on a typo
      if ($files === [] || $missing !== []) {
         $relative = implode(', ', $missing !== [] ? $missing : array_map(
            static fn (string $target): string => str_replace(BOOTGLY_WORKING_DIR, '', $target),
            $targets
         ));
         $notFound = $missing !== [];
         // ! No file checked: on a full check, no exemption matched one
         $stale = ($Lint !== null && $notFound === false)
            ? $this->stale($Lint, $rules, [], [], $only === null && $narrowed === false)
            : [];
         $failed = $notFound || $stale !== [];

         if ($Agent->detected) {
            echo json_encode($head + [
               'result'    => $failed ? 'failed' : 'passed',
               'agent'     => $Agent->name,
               'mode'      => $mode,
               'message'   => $notFound ? "Path not found: {$relative}" : "No PHP files found in: {$relative}",
               'files'     => ['scanned' => 0, 'failed' => 0, 'fixed' => 0, 'skipped' => 0],
               'issues'    => ['total' => 0, 'unresolved' => 0, 'exempted' => 0],
               'report'    => [],
               'skipped'   => [],
               'exemptions' => ['applied' => 0, 'stale' => $stale],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
         }
         else if ($notFound) {
            $Output->render("@.;@#Red: Path not found: {$relative} @;@..;");
         }
         else {
            $Output->render("@.;@#Yellow: No PHP files found in: {$relative} @;@..;");
            foreach ($stale as $entry) {
               $symbol = $entry['symbol'] !== null ? " {$entry['symbol']}" : '';
               $Output->render("@#Red: ✗ Stale exemption: {$entry['rule']} {$entry['path']}{$symbol} — {$entry['why']} @;@.;");
            }
         }

         return $failed === false;
      }

      // @ Section title (human output)
      if (!$Agent->detected) {
         $title = $only !== null
            ? 'Lint > ' . ucfirst($only->value)
            : 'Lint > Check (' . implode(', ', array_map(static fn (Rules $Rule): string => $Rule->value, $rules)) . ')';
         $Output->render("@.;@#Cyan: {$title} @;@.;");
         $Output->render("@#Black: ─────────────────────────────────────── @;@.;");

         if ($only !== null) {
            $Output->render("@#Yellow: ! `lint {$only->value}` is deprecated — use `bootgly lint check` @;@.;");
         }
         if ($file !== null) {
            $relativeFile = str_replace(BOOTGLY_WORKING_DIR, '', $file);
            $Output->render("@#Black: Config: {$relativeFile} @;@.;");
         }
         if ($refused) {
            $subject = $only !== null ? "{$only->value} is" : 'every enabled rule is';
            $Output->render(
               "@#Yellow: ! {$subject} check-only: --fix and --dry-run are ignored, nothing is written @;@.;"
            );
         }
      }

      // ! Analyzers — one per rule; imports resolves names against PHP's builtins
      /** @var array<string,Analyzers> $Facades */
      $Facades = [];
      foreach ($rules as $Rule) {
         $class = self::FACADES[$Rule->value][0];
         $Facades[$Rule->value] = new $class;
         if ($Rule === Rules::Imports) {
            Builtins::load();
         }
      }

      // @ Analyze
      $totalIssues = 0;
      $totalFiles = 0;
      $fixedFiles = 0;
      // ! What is still there when the run ends — a file left untouched by
      //   --fix (a comment in its import block, a rewrite that would not
      //   parse), a --dry-run or a plain check keeps its issues unresolved
      $unresolved = 0;
      // ! Exemptions: the ones a checked file fell under, the ones that waived something
      /** @var array<int,true> */
      $judged = [];
      /** @var array<int,true> */
      $applied = [];
      $exempted = 0;
      // ! The trees --fix never rewrites, whatever the paths and excludes say
      $pins = ($fix || $dryRun) ? $this->pin() : [];
      /** @var array<int,array{file:string,notice:string}> Files an analyzer declined, and why */
      $skipped = [];

      /** @var array<int,array{file:string,issues:array<int,array{type:string,symbol:string,kind:string,line:int,message:string}>,fixed:bool}> */
      $report = [];

      foreach ($files as $file => $scope) {
         $relativePath = str_replace(BOOTGLY_WORKING_DIR, '', $file);

         // ? Collected as a regular file, read as one: a name that became a
         //   link (or anything else) since is not read — content must never
         //   flow into the tree through a name someone planted
         $entry = @lstat($file);
         if ($entry === false || ((int) $entry['mode'] & 0170000) !== 0100000) {
            $notice = isset($links[$file])
               ? 'A link — not analyzed (name its target)'
               : 'No longer a regular file — not analyzed';
            $skipped[] = ['file' => $relativePath, 'notice' => $notice];
            if (!$Agent->detected) {
               $Output->render("@.;@#White: {$relativePath} @;\n  @#Yellow: ! @; {$notice}\n");
            }

            continue;
         }
         $permissions = (int) $entry['mode'] & 0o777;

         // ? A name the rewrite would not land on as collected is neither read nor
         //   written: a backslash is a separator elsewhere (normalized, it would
         //   climb out of the tree), and a directory swapped since collection
         //   resolves outside the scanned tree
         $directory = realpath(dirname($file));
         $directory = ($directory === false) ? null : rtrim($directory, '/');
         $notice = match (true) {
            str_contains(substr($file, (int) strrpos($file, '/')), '\\')
               => 'Its name carries a backslash — not analyzed',
            $scope === null || $directory === null
               || str_starts_with("{$directory}/", "{$scope}/") === false
               => 'Its directory no longer resolves inside the scanned tree — not analyzed',
            default => null
         };
         if ($notice !== null) {
            $skipped[] = ['file' => $relativePath, 'notice' => $notice];
            if (!$Agent->detected) {
               $Output->render("@.;@#White: {$relativePath} @;\n  @#Yellow: ! @; {$notice}\n");
            }

            continue;
         }

         // ! Where the file sits for the configuration — exemption globs match there
         $local = $Lint !== null ? $this->relate($file, $base) : null;

         // @ Every rule, its issues minus what an exemption waives
         /** @var array<string,array{Result,array<int,Issue>}> $Analyses */
         $Analyses = [];
         $found = 0;
         foreach ($rules as $Rule) {
            $Result = $Facades[$Rule->value]->analyze($file);

            // ? Declined — said out loud, counted apart, never green by silence;
            //   whatever issues came with the notice are still reported below
            if ($Result->notice !== null) {
               $skipped[] = ['file' => $relativePath, 'notice' => $Result->notice];
               if (!$Agent->detected) {
                  $Output->render("@.;@#White: {$relativePath} @;\n  @#Yellow: ! @; {$Result->notice}\n");
               }
            }

            $kept = $this->exempt($Lint, $Rule, $local, $Result, $judged, $applied, $exempted);
            $Analyses[$Rule->value] = [$Result, $kept];
            $found += count($kept);
         }

         if ($found === 0) {
            continue;
         }

         $totalFiles++;
         $totalIssues += $found;

         // @ Display (human output)
         if (!$Agent->detected) {
            $Output->render("@.;@#White: {$relativePath} @;\n");

            foreach ($Analyses as $value => [, $kept]) {
               $label = $only !== null ? '' : "[{$value}] ";
               foreach ($kept as $Issue) {
                  $Output->render("  @#Red: ✗ @; Line {$Issue->line}: {$label}{$Issue->message}\n");
               }
            }
         }

         // @ Fix — rule by rule, each on the file as the previous one left it
         $rewritten = false;
         if ($fix || $dryRun) {
            foreach ($rules as $Rule) {
               [$Result, $kept] = $Analyses[$Rule->value];
               $Facade = $Facades[$Rule->value];
               if (self::FACADES[$Rule->value][1] === false || $kept === []) {
                  continue;
               }
               $prefix = $only !== null ? '' : "[{$Rule->value}] ";

               // ! After an earlier rewrite, this rule reads the file as it is now
               if ($rewritten) {
                  $Result = $Facade->analyze($file);
                  $kept = $this->exempt($Lint, $Rule, $local, $Result, $judged, $applied, $exempted, false);
                  if ($kept === []) {
                     continue;
                  }
               }

               // ? A block the formatter refuses to rewrite is not fixable — saying
               //   otherwise would report a fix that never happened; a declined
               //   file is reported, never rewritten; an exempted import is part
               //   of the one block the import formatter rewrites whole
               $reason = null;
               if ($Result->notice !== null) {
                  $reason = 'the analyzer declined the file';
               }
               if ($directory !== null) {
                  foreach ($pins as $pinned) {
                     if (str_starts_with("{$directory}/", $pinned)) {
                        $reason = 'it sits in a pinned tree';
                        break;
                     }
                  }
               }
               foreach ($kept as $Issue) {
                  if ($Issue->type === 'comment_in_imports') {
                     $reason = 'the import block carries a comment';
                     break;
                  }
               }
               if ($Rule === Rules::Imports && count($kept) !== count($Result->issues)) {
                  $reason = 'an exemption covers part of the import block';
               }
               if ($reason !== null) {
                  if (!$Agent->detected) {
                     $Output->render("@#Yellow:   ! {$prefix}Left untouched — {$reason} @;\n\n");
                  }
                  continue;
               }

               $issueCount = count($kept);
               if ($dryRun) {
                  if (!$Agent->detected) {
                     $Output->render("@#Cyan:   [dry-run] {$prefix}Would fix {$issueCount} issue(s) @;\n\n");
                  }
                  continue;
               }

               // ! Only what is not waived is rewritten
               $corrected = $this->format($Facade, $Rule === Rules::Imports
                  ? $Result
                  : new Result(file: $Result->file, source: $Result->source, issues: $kept)
               );

               if ($corrected === null) {
                  if (!$Agent->detected) {
                     $Output->render("@#Red:   ✗ {$prefix}No formatter for this result — skipped @;\n\n");
                  }
               }
               else if ($corrected === $Result->source) {
                  // ? The formatter had nothing to rewrite: the issues stay
                  if (!$Agent->detected) {
                     $Output->render("@#Yellow:   ! {$prefix}Left untouched — nothing to rewrite for these issues @;\n\n");
                  }
               }
               else if (is_writable($file) === false || is_writable(dirname($file)) === false) {
                  // ? Not ours to write — the file, or the directory the sibling
                  //   temporary must land in: the issues stay, the run goes on
                  if (!$Agent->detected) {
                     $Output->render("@#Yellow:   ! {$prefix}Left untouched — the file or its directory is not writable @;\n\n");
                  }
               }
               else if (($valid = $this->validate($corrected)) !== true) {
                  if (!$Agent->detected) {
                     $Output->render($valid === null
                        ? "@#Red:   ✗ {$prefix}Could not validate the fix (no php -l available) — skipped @;\n\n"
                        : "@#Red:   ✗ {$prefix}Fix produced invalid PHP — skipped @;\n\n"
                     );
                  }
               }
               else if (new File($file, (string) $scope)->replace($corrected, $permissions) === false) {
                  // ? The rewrite went to a sibling temporary and was discarded:
                  //   the file on disk is byte-identical to what was analyzed.
                  //   The scanned tree is the jail, judged when the file is
                  //   written: a directory swapped for a link out of the tree
                  //   since the check above (while `php -l` validated the fix)
                  //   is refused, never written through.
                  //   A rename replaces the NAME: a name that became a link since
                  //   collection is discarded, never written through. The new
                  //   inode keeps the mode read at collection only — no
                  //   setuid/setgid/sticky bits, ACLs or old owner, and a hard
                  //   link to the old inode keeps the old content
                  if (!$Agent->detected) {
                     $Output->render("@#Yellow:   ! {$prefix}Left untouched — the rewrite could not replace the file @;\n\n");
                  }
               }
               else {
                  $rewritten = true;
               }
            }
         }

         // ! Fixed means RESOLVED: what was written is analyzed again, and only
         //   a clean file counts — what remains is reported as it stands now,
         //   not as it was; what was fixed, as it was
         $fixed = false;
         /** @var array<int,array{string,Issue}> $reported */
         $reported = [];
         if ($rewritten) {
            foreach ($rules as $Rule) {
               $Result = $Facades[$Rule->value]->analyze($file);
               foreach ($this->exempt($Lint, $Rule, $local, $Result, $judged, $applied, $exempted, false) as $Issue) {
                  $reported[] = [$Rule->value, $Issue];
               }
            }
            $fixed = $reported === [];
            if ($fixed) {
               $fixedFiles++;
               foreach ($Analyses as $value => [, $kept]) {
                  foreach ($kept as $Issue) {
                     $reported[] = [$value, $Issue];
                  }
               }
            }
            if (!$Agent->detected) {
               $remaining = $fixed ? 0 : count($reported);
               $Output->render($fixed
                  ? "@#Green:   ✓ Fixed {$found} issue(s) @;\n\n"
                  : "@#Yellow:   ! Rewritten, but {$remaining} issue(s) remain @;\n\n"
               );
            }
         }
         else {
            foreach ($Analyses as $value => [, $kept]) {
               foreach ($kept as $Issue) {
                  $reported[] = [$value, $Issue];
               }
            }
         }

         if ($fixed === false) {
            $unresolved += count($reported);
         }

         // @ Collect report entry
         if ($Agent->detected) {
            $issueEntries = [];
            foreach ($reported as [$value, $Issue]) {
               $issueEntries[] = [
                  'rule'    => $value,
                  'type'    => $Issue->type,
                  'symbol'  => $Issue->symbol,
                  'kind'    => $Issue->kind,
                  'line'    => $Issue->line,
                  'message' => $Issue->message,
               ];
            }

            $report[] = [
               'file'   => $relativePath,
               'issues' => $issueEntries,
               'fixed'  => $fixed,
            ];
         }
      }

      // @ Stale exemptions
      $stale = $Lint !== null ? $this->stale($Lint, $rules, $judged, $applied, $only === null && $narrowed === false) : [];
      $passed = $unresolved === 0 && $stale === [];

      // @ Output
      if ($Agent->detected) {
         // @ JSON output for AI agents
         echo json_encode($head + [
            'result'    => $passed ? 'passed' : 'failed',
            'agent'     => $Agent->name,
            'mode'      => $fix ? 'fix' : ($dryRun ? 'dry-run' : 'check'),
            'files'     => [
               'scanned' => count($files),
               'failed'  => $totalFiles,
               'fixed'   => $fixedFiles,
               'skipped' => count($skipped),
            ],
            'issues' => [
               'total'      => $totalIssues,
               'unresolved' => $unresolved,
               'exempted'   => $exempted,
            ],
            'report'  => $report,
            'skipped' => $skipped,
            'exemptions' => [
               'applied' => count($applied),
               'stale'   => $stale,
            ],
         ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

         return $passed;
      }

      // @ Summary (human output)
      $Output->render("\n");
      if ($totalIssues === 0) {
         $subject = $only !== null ? $only->value : 'lint';
         $Output->render("@#Green: ✓ No {$subject} issues found. @;@.;");
      }
      else {
         $Output->render("@#Yellow: Found {$totalIssues} issue(s) in {$totalFiles} file(s). @;@.;");

         if ($fixedFiles > 0) {
            $Output->render("@#Green: ✓ Fixed {$fixedFiles} file(s). @;@.;");
         }
         if ($unresolved > 0 && ($fix || $dryRun)) {
            $Output->render("@#Yellow: ! {$unresolved} issue(s) remain. @;@.;");
         }
      }
      if ($exempted > 0) {
         $Output->render("@#Black: {$exempted} issue(s) waived by exemptions. @;@.;");
      }
      foreach ($stale as $entry) {
         $symbol = $entry['symbol'] !== null ? " {$entry['symbol']}" : '';
         $Output->render("@#Red: ✗ Stale exemption: {$entry['rule']} {$entry['path']}{$symbol} — {$entry['why']} @;@.;");
      }

      $Output->render("@#Black: ─────────────────────────────────────── @;@..;");

      if ($skipped !== []) {
         $declined = count($skipped);
         $Output->render("@#Yellow: ! {$declined} file(s) not linted — see the notices above. @;@.;");
      }

      // : Green only when nothing is left — in every mode
      return $passed;
   }

   /**
    * List the stale exemptions of the rules run: judged and waiving nothing;
    * on a full `check`, also the ones no checked file fell under (a glob that
    * matches nothing).
    *
    * @param Lint $Lint
    * @param array<int,Rules> $rules The rules run
    * @param array<int,true> $judged Exemptions a checked file fell under, by index
    * @param array<int,true> $applied Exemptions that waived an issue, by index
    * @param bool $full Whether every configured path was checked
    *
    * @return array<int,array{rule:string,path:string,symbol:null|string,reason:string,why:string}>
    */
   private function stale (Lint $Lint, array $rules, array $judged, array $applied, bool $full): array
   {
      $stale = [];
      foreach ($Lint->Exemptions as $index => $Exemption) {
         if (in_array($Exemption->rule, $rules, true) === false || isset($applied[$index])) {
            continue;
         }
         $why = match (true) {
            isset($judged[$index]) => 'waives nothing in the files it matches',
            $full => 'matches no checked file',
            default => null
         };
         if ($why === null) {
            continue;
         }
         $stale[] = [
            'rule'   => $Exemption->rule->value,
            'path'   => $Exemption->path,
            'symbol' => $Exemption->symbol,
            'reason' => $Exemption->reason,
            'why'    => $why,
         ];
      }

      return $stale;
   }

   /**
    * Remove from a result's issues what the configuration's exemptions waive,
    * recording which exemptions a file fell under and which waived something.
    *
    * @param null|Lint $Lint The configuration, null for the defaults
    * @param Rules $Rule The rule that produced the result
    * @param null|string $local The file relative to the configuration's directory, null outside it
    * @param Result $Result
    * @param array<int,true> $judged Exemptions a checked file fell under, by index
    * @param array<int,true> $applied Exemptions that waived an issue, by index
    * @param int $exempted How many issues were waived
    * @param bool $count Whether the waived issues are counted (false on a re-analysis)
    *
    * @return array<int,Issue> The issues left
    */
   private function exempt (
      null|Lint $Lint,
      Rules $Rule,
      null|string $local,
      Result $Result,
      array &$judged,
      array &$applied,
      int &$exempted,
      bool $count = true
   ): array
   {
      // ?: Nothing can be waived
      if ($Lint === null || $local === null) {
         return $Result->issues;
      }

      // ! The exemptions this file falls under were judged, issues or not
      $Selected = $Lint->select($Rule, $local);
      foreach ($Selected as $index => $Exemption) {
         $judged[$index] = true;
      }
      // ?: Nothing to waive
      if ($Selected === [] || $Result->issues === []) {
         return $Result->issues;
      }

      // ! The enclosing class of each line — only when a symbol names one
      $Scope = null;
      foreach ($Selected as $Exemption) {
         if ($Exemption->symbol !== null && str_contains($Exemption->symbol, '::')) {
            $Scope ??= new Scope($Result->source);
         }
      }

      // @@
      $kept = [];
      foreach ($Result->issues as $Issue) {
         $class = $Scope !== null ? $Scope->find($Issue->line) : '';
         $waived = false;
         foreach ($Selected as $index => $Exemption) {
            if ($Exemption->cover($Issue, $class)) {
               $applied[$index] = true;
               $waived = true;
               break;
            }
         }
         if ($waived) {
            if ($count) {
               $exempted++;
            }
            continue;
         }
         $kept[] = $Issue;
      }

      return $kept;
   }

   /**
    * Narrow the configured paths by the path given: the configured trees
    * inside it, or the path itself when it lies inside a configured tree.
    *
    * @param Lint $Lint
    * @param string $base The configuration's directory
    * @param null|string $path The absolute path given, null for every configured path
    *
    * @return array{array<int,string>,null|string} The targets, and why there are none
    */
   private function narrow (Lint $Lint, string $base, null|string $path): array
   {
      // ! The configured trees, absolute
      $configured = [];
      foreach ($Lint->paths as $entry) {
         $entry = $this->normalize($entry);
         $configured[] = $entry === '' ? $base : "{$base}/{$entry}";
      }

      // ?: No path — every configured tree
      if ($path === null) {
         return [$configured, null];
      }

      // ? A path that is not there is a typo, never an empty pass
      $target = realpath($path);
      if ($target === false) {
         $relative = str_replace(BOOTGLY_WORKING_DIR, '', $path);

         return [[], "Path not found: {$relative}"];
      }
      $target = rtrim($target, '/');

      // @@ Inside a configured tree, or holding some — always answered as
      //   the configured spelling, never through a link, so the excludes and
      //   the exemptions match the narrowed files as they match a full run
      $targets = [];
      foreach ($configured as $tree) {
         $real = realpath($tree);
         if ($real === false) {
            continue;
         }
         $real = rtrim($real, '/');
         if ($target === $real || str_starts_with("{$target}/", "{$real}/")) {
            return [[$tree . substr($target, strlen($real))], null];
         }
         if (str_starts_with("{$real}/", "{$target}/")) {
            $targets[] = $tree;
         }
      }

      // :
      if ($targets === []) {
         $relative = str_replace(BOOTGLY_WORKING_DIR, '', $path);

         return [[], "Path outside the configured paths: {$relative}"];
      }

      return [$targets, null];
   }

   /**
    * Spell a path the way the configuration does: inside a configured tree,
    * through that tree's spelling; anywhere else, its real path. A file keeps
    * its name (a file link is never resolved through; a directory link is,
    * so its files meet the excludes), and a path that is not
    * there is returned as given — the scan reports it missing.
    *
    * @param Lint $Lint
    * @param string $base The configuration's directory (a real path)
    * @param string $path An absolute path
    *
    * @return string
    */
   private function spell (Lint $Lint, string $base, string $path): string
   {
      // ! A file keeps its own name — only its directory is spelled: a file
      //   link is still a link when the scan meets it, and is refused there;
      //   a directory (linked or not) resolves, so its files meet the excludes
      $leaf = '';
      $directory = $path;
      if (is_dir($path) === false && (is_link($path) || is_file($path))) {
         $leaf = '/' . basename($path);
         $directory = dirname($path);
      }

      // ?: Not there
      $target = realpath($directory);
      if ($target === false) {
         return $path;
      }
      $target = rtrim($target, '/');

      // @@ Inside a configured tree
      foreach ($Lint->paths as $entry) {
         $entry = $this->normalize($entry);
         $tree = $entry === '' ? $base : "{$base}/{$entry}";
         $real = realpath($tree);
         if ($real === false) {
            continue;
         }
         $real = rtrim($real, '/');
         if ($target === $real || str_starts_with("{$target}/", "{$real}/")) {
            return $tree . substr($target, strlen($real)) . $leaf;
         }
      }

      // :
      return "{$target}{$leaf}";
   }

   /**
    * A file's path relative to the configuration's directory.
    *
    * @param string $file An absolute file path
    * @param null|string $base The configuration's directory
    *
    * @return null|string The relative path, `/`-separated, or null outside the directory
    */
   private function relate (string $file, null|string $base): null|string
   {
      // ?: No configuration
      if ($base === null) {
         return null;
      }

      // ! As written, without `.` segments or doubled separators — every
      //   target is spelled from the configuration's directory
      $local = $this->normalize($file);
      if (str_starts_with($local, "{$base}/") === false) {
         return null;
      }

      // :
      return substr($local, strlen($base) + 1);
   }

   /**
    * Drop the `.` segments and doubled separators of a path, lexically — what
    * a configuration pattern is matched against never carries them.
    *
    * @param string $path
    *
    * @return string The path without `.` segments ('' for the current directory)
    */
   private function normalize (string $path): string
   {
      $absolute = str_starts_with($path, '/');
      $segments = [];
      foreach (explode('/', $path) as $segment) {
         if ($segment === '' || $segment === '.') {
            continue;
         }
         $segments[] = $segment;
      }
      $normalized = implode('/', $segments);

      return $absolute ? "/{$normalized}" : $normalized;
   }

   /**
    * Rewrite a failed result through the facade that produced it.
    *
    * Only the fixable facades format, and each formats its own analyzer's
    * result — the imports formatter reads the import block only the imports
    * result carries — so the pairing is checked here instead of assumed.
    *
    * @return null|string The corrected source, or null when the facade cannot format this result
    */
   private function format (Analyzers $Facade, Result $Result): null|string
   {
      if ($Facade instanceof Imports && $Result instanceof Imports\Analyzer\Result) {
         return $Facade->format($Result);
      }
      if ($Facade instanceof Nullables || $Facade instanceof Spacing) {
         return $Facade->format($Result);
      }

      return null;
   }

   // # Help
   /**
    * @param array<int,string> $arguments
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
            /** @var array{description: string, deprecated: bool, arguments: array<string,string>}|string $value */
            $description = is_array($value) ? $value['description'] : $value;
            $label = $name;
            $content .= '@#Yellow:' . $name . '@;';
            $content .= str_pad('', 10 - strlen($label)) . '  ' . $description . PHP_EOL;
         }
         $content = rtrim($content);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint arguments @;';
         $Fieldset->content = $content;
         $Fieldset->render();

         // # Options
         $optContent = '';
         foreach ($this->options as $desc => $flags) {
            $flagStr = implode(', ', $flags);
            $optContent .= '@#Yellow:' . $flagStr . '@;';
            $optContent .= str_pad('', 14 - strlen($flagStr)) . '  ' . $desc . PHP_EOL;
         }
         $optContent = rtrim($optContent);
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#Cyan: Lint options @;';
         $Fieldset->content = $optContent;
         $Fieldset->render();

         // # Usage
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Lint usage @;';
         $Fieldset->content = 'bootgly lint check @#Black: [path] @;@.;';
         $Fieldset->content .= 'bootgly lint check @#Black: [path] --fix @;@.;';
         $Fieldset->content .= 'bootgly lint check @#Black: [path] --dry-run @;@.;';
         $Fieldset->content .= '@#Black:Rules, paths, excludes and exemptions come from bootgly.Lint.php (nearest one up from the current directory).@;';
         $Fieldset->render();

         // # Examples — a kit runs them from a project directory
         if (Workspaces::detect() === Workspaces::Kit) {
            $examples = '@#Black:cd projects/<Name>@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint check@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint check Models/ --fix@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint check --dry-run@;';
         }
         else {
            $examples = '@#Black:bootgly lint check@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint check Bootgly/ABI/ --fix@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint check --dry-run@;';
         }
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Lint examples @;';
         $Fieldset->content = $examples;
         $Fieldset->render();
      }
      else if ( isSet($this->arguments[$arguments[0]]) ) {
         // @ Show usage for a valid subcommand
         $submodule = $arguments[0];
         /** @var array{description: string, deprecated: bool, arguments: array<string,string>} $meta */
         $meta = $this->arguments[$submodule];
         $fixable = $meta['deprecated'] === false || self::FACADES[$submodule][1];

         $Output->write(PHP_EOL);
         $Output->render("@#Black: {$meta['description']}@;@.;");

         // @ Show arguments if any
         if ( !empty($meta['arguments']) ) {
            $argLines = '';
            foreach ($meta['arguments'] as $arg => $argDesc) {
               $argLines .= '@#cyan:' . str_pad($arg, 9) . '@; ' . $argDesc . PHP_EOL;
            }
            $argLines = rtrim($argLines);

            $Fieldset = new Fieldset($Output);
            $Fieldset->title = '@#Cyan: Lint ' . $submodule . ' arguments @;';
            $Fieldset->content = $argLines;
            $Fieldset->render();
         }

         // # Usage — a check-only rule has no --fix / --dry-run
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = "@#Cyan: Lint {$submodule} usage @;";
         $Fieldset->content = "bootgly lint {$submodule} @#Black: [path] @;";
         if ($fixable) {
            $Fieldset->content .= PHP_EOL . "bootgly lint {$submodule} @#Black: [path] --fix @;"
               . PHP_EOL . "bootgly lint {$submodule} @#Black: --dry-run @;";
         }
         $Fieldset->render();

         // # Example
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = "@#Cyan: Lint {$submodule} example @;";
         $sample = Workspaces::detect() === Workspaces::Kit ? 'Models/' : 'Bootgly/ABI/';
         $Fieldset->content = "@#Black:bootgly lint {$submodule}@;" . PHP_EOL
            . "@#Black:bootgly lint {$submodule} {$sample}@;";
         if ($fixable) {
            $Fieldset->content .= PHP_EOL . "@#Black:bootgly lint {$submodule} --fix@;";
         }
         $Fieldset->render();
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

   /**
    * Resolve a path inside a kit: a relative path follows the working
    * directory, and no path means the directory under projects/ the caller
    * stands in — there is no default anywhere else.
    *
    * @param null|string $path The path argument, as given
    *
    * @return null|string The absolute path, or null when there is no default
    */
   private function resolve (null|string $path): null|string
   {
      $cwd = getcwd();

      // ?: The working directory is gone — only an absolute path resolves
      if ($cwd === false) {
         return $path !== null && str_starts_with($path, '/') ? $path : null;
      }

      // ?: An explicit path — absolute as given, relative to the caller
      if ($path !== null) {
         return str_starts_with($path, '/') ? $path : "{$cwd}/{$path}";
      }

      // ! Where the caller stands, against the kit's projects/
      $projects = realpath(Projects::CONSUMER_DIR);
      $here = realpath($cwd);

      // ?: Outside projects/ — no default
      if ($projects === false || $here === false || str_starts_with("{$here}/", "{$projects}/") === false) {
         return null;
      }

      // :
      return $here;
   }

   /**
    * Tell whether a path is, holds or lies inside a pinned tree — the trees
    * `--fix` never rewrites. In a kit: its `Bootgly/`, `Console/` and `Web/`
    * submodules, plus the framework and platforms the launcher runs. In the
    * framework checkout: the framework itself when a kit pins it as a
    * submodule (its own launcher, reached from inside `<kit>/Bootgly/`).
    *
    * @param string $path An absolute path
    *
    * @return bool
    */
   private function overlap (string $path, null|Lint $Lint = null, null|string $base = null): bool
   {
      $target = realpath($path);

      // ?: Not there — the scan reports the missing path itself
      if ($target === false) {
         return false;
      }
      $target = rtrim($target, '/') . '/';

      // @@ Inside a pinned tree, or holding one
      foreach ($this->pin() as $pinned) {
         // ?: Inside it
         if (str_starts_with($target, $pinned)) {
            return true;
         }
         // ?: Holding it — unless the scan skips it anyway: without a
         //   configuration, a `vendor/` between them (a framework installed
         //   through Composer); under one, a tree its excludes cover whole
         //   (a pattern that matches the directory AND what lies deep in it —
         //   `Bootgly/*` matches the first only). The write itself is guarded
         //   again, file by file
         if (str_starts_with($pinned, $target)) {
            if ($Lint === null) {
               if (str_contains('/' . substr($pinned, strlen($target)), '/vendor/') === false) {
                  return true;
               }
               continue;
            }
            $directory = rtrim($pinned, '/');
            $local = ($base !== null && str_starts_with($directory, "{$base}/"))
               ? substr($directory, strlen($base) + 1) . '/'
               : null;
            if ($local === null || $Lint->exclude($local) === false || $Lint->exclude("{$local}a/b/c.php") === false) {
               return true;
            }
         }
      }

      // :
      return false;
   }

   /**
    * List the pinned trees of this workspace — the read-only submodules
    * `--fix` must never rewrite — as real paths ending in `/`.
    *
    * @return array<int,string>
    */
   private function pin (): array
   {
      // ! The pinned trees of this workspace
      $Workspace = Workspaces::detect();
      $trees = [];
      if ($Workspace === Workspaces::Kit) {
         $trees = [
            BOOTGLY_WORKING_DIR . 'Bootgly',
            BOOTGLY_WORKING_DIR . 'Console',
            BOOTGLY_WORKING_DIR . 'Web',
            $this->framework,
         ];
         foreach (['CONSOLE_ROOT_BASE', 'WEB_ROOT_BASE'] as $root) {
            if (defined($root) === true) {
               $trees[] = (string) constant($root);
            }
         }
      }
      else if ($Workspace === Workspaces::Author) {
         // # A submodule (a `.git` file) of a kit (`.gitmodules` + launcher
         //   above): the framework and the platforms beside it are pinned
         $kit = dirname($this->framework);
         if (is_file("{$this->framework}/.git") && is_file("{$kit}/.gitmodules") && is_file("{$kit}/bootgly")) {
            $trees = [$this->framework, "{$kit}/Console", "{$kit}/Web"];
         }
      }

      // @@ As real paths
      $pinned = [];
      foreach ($trees as $tree) {
         $real = realpath($tree);
         if ($real !== false) {
            $pinned[] = rtrim($real, '/') . '/';
         }
      }

      // :
      return $pinned;
   }

   /**
    * Refuse the run before anything is scanned, saying why — as the agent
    * JSON document or as a human alert.
    *
    * @param Agent $Agent The detected agent, if any
    * @param array<string,mixed> $head What the agent document opens with — the subcommand and its shape
    * @param string $mode `check`, `fix` or `dry-run`
    * @param string $message Why the run is refused
    *
    * @return false
    */
   private function refuse (Agent $Agent, array $head, string $mode, string $message): false
   {
      if ($Agent->detected) {
         echo json_encode($head + [
            'result'    => 'failed',
            'agent'     => $Agent->name,
            'mode'      => $mode,
            'message'   => $message,
            'files'     => ['scanned' => 0, 'failed' => 0, 'fixed' => 0, 'skipped' => 0],
            'issues'    => ['total' => 0, 'unresolved' => 0, 'exempted' => 0],
            'report'    => [],
            'skipped'   => [],
            'exemptions' => ['applied' => 0, 'stale' => []],
         ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
      }
      else {
         CLI->Terminal->Output->render("@.;@#Red: {$message} @;@..;");
      }

      return false;
   }

   /**
    * Collect all PHP files from a path.
    *
    * @param string $path File or directory path
    * @param bool $defaults Whether the built-in skips apply (no bootgly.Lint.php):
    *                       `vendor/`, `examples/` and `vs/` inside the scanned path
    *
    * @return array<int,string>
    */
   private function collect (string $path, bool $defaults = true): array
   {
      if (is_file($path)) {
         return [$path];
      }

      if (!is_dir($path)) {
         return [];
      }

      $files = [];
      /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $Iterator */
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($path)
      );

      $root = rtrim($path, '/') . '/';
      /** @var \SplFileInfo $file */
      foreach ($Iterator as $file) {
         // ? A link is scanned where it points, never through the name that
         //   points — `--fix` must never write outside the tree it was given
         if ($file->isLink()) {
            continue;
         }
         if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $pathname = $file->getPathname();

            // @ Without a bootgly.Lint.php, skip vendor, examples and vs INSIDE
            //   the scanned path — tests are code and are linted too; what the
            //   caller named is scanned whatever its ancestors are called. A
            //   configuration says what it excludes instead
            $relative = '/' . substr($pathname, strlen($root));
            if ($defaults && (str_contains($relative, '/vendor/')
               || str_contains($relative, '/examples/')
               || str_contains($relative, '/vs/')
            )) {
               continue;
            }

            $files[] = $pathname;
         }
      }

      sort($files);

      return $files;
   }

   /**
    * Validate PHP syntax of source code using php -l.
    *
    * @param string $source PHP source code to validate
    *
    * @return null|bool True if syntax is valid, false if not — null when nothing could check it
    */
   private function validate (string $source): null|bool
   {
      // ? No way to validate: never write what nobody checked
      if (function_exists('proc_open') === false) {
         return null;
      }
      try {
         $process = proc_open(
            [PHP_BINARY, '-l'],
            [
               0 => ['pipe', 'r'],
               1 => ['pipe', 'w'],
               2 => ['pipe', 'w'],
            ],
            $pipes
         );
      }
      catch (Throwable) {
         return null;
      }
      if ($process === false) {
         return null;
      }

      fwrite($pipes[0], $source);
      fclose($pipes[0]);

      $output = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      fclose($pipes[2]);

      $exitCode = proc_close($process);

      return $exitCode === 0 && str_contains($output !== false ? $output : '', 'No syntax errors');
   }
}
