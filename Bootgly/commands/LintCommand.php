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
use function array_slice;
use function basename;
use function bin2hex;
use function chmod;
use function constant;
use function count;
use function defined;
use function dirname;
use function fclose;
use function fopen;
use function function_exists;
use function fwrite;
use function getcwd;
use function implode;
use function is_array;
use function is_dir;
use function is_file;
use function is_writable;
use function json_encode;
use function lstat;
use function proc_close;
use function proc_open;
use function random_bytes;
use function realpath;
use function rename;
use function rtrim;
use function sort;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function substr;
use function ucfirst;
use function unlink;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

use const Bootgly\CLI;
use Bootgly\ABI\Syntax\Analyzers;
use Bootgly\ABI\Syntax\Analyzers\Result;
use Bootgly\ABI\Syntax\Builtins;
use Bootgly\ABI\Syntax\Imports;
use Bootgly\ABI\Syntax\Methods;
use Bootgly\ABI\Syntax\Nullables;
use Bootgly\ABI\Syntax\Promotions;
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
    * The submodules — one per style rule, each behind an `Analyzers` facade.
    * `fixable` says whether the facade also formats: `--fix` and `--dry-run`
    * are honored only there; a check-only submodule reports and writes nothing.
    *
    * @var array<string,array{description:string,facade:class-string<Analyzers>,fixable:bool,arguments:array<string,string>}>
    */
   public array $arguments = [ // @phpstan-ignore property.phpDocType
      'imports' => [
         'description' => 'Lint import code style for use statements',
         'facade'      => Imports::class,
         'fixable'     => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'nullables' => [
         'description' => 'Lint nullable shorthands (?T) in parameter, return and property types',
         'facade'      => Nullables::class,
         'fixable'     => true,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'promotions' => [
         'description' => 'Lint constructor property promotion (check-only)',
         'facade'      => Promotions::class,
         'fixable'     => false,
         'arguments'   => [
            '[path]' => 'File or directory path (default: Bootgly/; in a kit: the current directory under projects/)'
         ]
      ],
      'methods' => [
         'description' => 'Lint multi-word (camelCase) method names (check-only)',
         'facade'      => Methods::class,
         'fixable'     => false,
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


   public function run (array $arguments = [], array $options = []): bool
   {
      // @ Route subcommand
      $submodule = $arguments[0] ?? null;

      if ( $submodule === null || !isset($this->arguments[$submodule]) ) {
         return $this->help($arguments);
      }

      return $this->lint(
         $submodule,
         array_slice($arguments, 1),
         $options
      );
   }

   // # Lint
   /**
    * @param array<int,string> $arguments
    * @param array<string,mixed> $options
    */
   private function lint (string $submodule, array $arguments, array $options): bool
   {
      $Output = CLI->Terminal->Output;

      // ! Agent detection
      $Agent = Agent::detect();

      // ! Path — in a kit it follows the working directory; elsewhere the
      //   working base, with the framework source as the default
      $path = $arguments[0] ?? null;
      $kit = Workspaces::detect() === Workspaces::Kit;

      if ($kit === true) {
         $path = $this->resolve($path);
      }
      else if ($path === null) {
         $path = BOOTGLY_WORKING_DIR . 'Bootgly/';
      }
      else if (!str_starts_with($path, '/')) {
         $path = BOOTGLY_WORKING_DIR . $path;
      }

      // ! Submodule
      /** @var array{description:string,facade:class-string<Analyzers>,fixable:bool,arguments:array<string,string>} $meta */
      $meta = $this->arguments[$submodule];
      $fixable = $meta['fixable'];

      // ! Options
      $fix = isset($options['fix']);
      $dryRun = isset($options['dry-run']);

      // ? A check-only submodule has no formatter: say so, then run the check
      $refused = $fixable === false && ($fix || $dryRun);
      if ($refused) {
         $fix = false;
         $dryRun = false;
      }

      // ? A kit has no default outside projects/, and `--fix` never rewrites
      //   a pinned tree — the kit's next update would refuse it dirty
      $message = match (true) {
         $path === null && getcwd() === false
            => 'The working directory no longer exists — pass an absolute path.',
         $path === null
            => 'No default path outside projects/ in a kit — pass a path, or run it from a project directory (projects/<Name>/).',
         $fix === true && $this->overlap($path) === true
            => '--fix never rewrites a pinned tree — Bootgly/, Console/ and Web/ are read-only submodules of the kit.',
         default => null
      };

      if ($message !== null) {
         return $this->refuse($Agent, $submodule, $fixable, $fix ? 'fix' : ($dryRun ? 'dry-run' : 'check'), $message);
      }
      /** @var string $path */

      // @ Collect PHP files
      $files = $this->collect($path);

      // ? Nothing to scan: an existing path without PHP files passes, a path
      //   that is not there fails — a gate must never go green on a typo
      if (count($files) === 0) {
         $missing = is_dir($path) === false && is_file($path) === false;
         $relative = str_replace(BOOTGLY_WORKING_DIR, '', $path);

         if ($Agent->detected) {
            echo json_encode([
               'result'    => $missing ? 'failed' : 'passed',
               'submodule' => $submodule,
               'fixable'   => $fixable,
               'agent'     => $Agent->name,
               'mode'      => $fix ? 'fix' : ($dryRun ? 'dry-run' : 'check'),
               'message'   => $missing ? "Path not found: {$relative}" : "No PHP files found in: {$relative}",
               'files'     => ['scanned' => 0, 'failed' => 0, 'fixed' => 0, 'skipped' => 0],
               'issues'    => ['total' => 0, 'unresolved' => 0],
               'report'    => [],
               'skipped'   => [],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;
         }
         else if ($missing) {
            $Output->render("@.;@#Red: Path not found: {$relative} @;@..;");
         }
         else {
            $Output->render("@.;@#Yellow: No PHP files found in: {$relative} @;@..;");
         }

         return $missing === false;
      }

      // @ Section title (human output)
      $title = 'Lint > ' . ucfirst($submodule);
      if (!$Agent->detected) {
         $Output->render("@.;@#Cyan: {$title} @;@.;");
         $Output->render("@#Black: ─────────────────────────────────────── @;@.;");

         if ($refused) {
            $Output->render(
               "@#Yellow: ! {$submodule} is check-only: --fix and --dry-run are ignored, nothing is written @;@.;"
            );
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
      /** @var array<int,array{file:string,notice:string}> Files an analyzer declined, and why */
      $skipped = [];

      /** @var array<int,array{file:string,issues:array<int,array{type:string,symbol:string,kind:string,line:int,message:string}>,fixed:bool}> */
      $report = [];

      // * imports — the only submodule that resolves names against PHP's builtins
      if ($submodule === 'imports') {
         Builtins::load();
      }

      $Facade = new $meta['facade'];

      foreach ($files as $file) {
         // ? Collected as a regular file, read as one: a name that became a
         //   link (or anything else) since is not read — content must never
         //   flow into the tree through a name someone planted
         $entry = @lstat($file);
         if ($entry === false || ((int) $entry['mode'] & 0170000) !== 0100000) {
            $relativePath = str_replace(BOOTGLY_WORKING_DIR, '', $file);
            $skipped[] = ['file' => $relativePath, 'notice' => 'No longer a regular file — not analyzed'];
            if (!$Agent->detected) {
               $Output->render("@.;@#White: {$relativePath} @;\n  @#Yellow: ! @; No longer a regular file — not analyzed\n");
            }

            continue;
         }
         $mode = (int) $entry['mode'] & 0o777;

         $Result = $Facade->analyze($file);

         // ? Declined — said out loud, counted apart, never green by silence;
         //   whatever issues came with the notice are still reported below
         if ($Result->notice !== null) {
            $relativePath = str_replace(BOOTGLY_WORKING_DIR, '', $file);
            $skipped[] = ['file' => $relativePath, 'notice' => $Result->notice];
            if (!$Agent->detected) {
               $Output->render("@.;@#White: {$relativePath} @;\n  @#Yellow: ! @; {$Result->notice}\n");
            }
         }

         if ($Result->failed === false) {
            continue;
         }

         $totalFiles++;
         $issueCount = count($Result->issues);
         $totalIssues += $issueCount;
         // ! What the entry reports: what was found — or, after a rewrite that
         //   left issues, what remains
         $reported = $Result->issues;

         $relativePath = str_replace(BOOTGLY_WORKING_DIR, '', $file);
         $fixed = false;

         // @ Display (human output)
         if (!$Agent->detected) {
            $Output->render("@.;@#White: {$relativePath} @;\n");

            foreach ($Result->issues as $Issue) {
               $Output->render("  @#Red: ✗ @; Line {$Issue->line}: {$Issue->message}\n");
            }
         }

         // ? A block the formatter refuses to rewrite is not fixable — saying otherwise
         //   would report a fix that never happened; a declined file is reported, never rewritten
         $rewritable = $Result->notice === null;
         foreach ($Result->issues as $Issue) {
            if ($Issue->type === 'comment_in_imports') {
               $rewritable = false;
               break;
            }
         }

         // @ Fix
         if (($fix || $dryRun) && $rewritable === false) {
            if (!$Agent->detected) {
               $Output->render(
                  "@#Yellow:   ! Left untouched — the import block carries a comment @;\n\n"
               );
            }
         }
         else if ($fix || $dryRun) {
            $corrected = $this->format($Facade, $Result);

            if ($corrected === null) {
               if (!$Agent->detected) {
                  $Output->render("@#Red:   ✗ No formatter for this result — skipped @;\n\n");
               }
            }
            else if ($dryRun) {
               if (!$Agent->detected) {
                  $Output->render("@#Cyan:   [dry-run] Would fix {$issueCount} issue(s) @;\n\n");
               }
            }
            else if ($corrected === $Result->source) {
               // ? The formatter had nothing to rewrite: the issues stay
               if (!$Agent->detected) {
                  $Output->render("@#Yellow:   ! Left untouched — nothing to rewrite for these issues @;\n\n");
               }
            }
            else if (is_writable($file) === false || is_writable(dirname($file)) === false) {
               // ? Not ours to write — the file, or the directory the sibling
               //   temporary must land in: the issues stay, the run goes on
               if (!$Agent->detected) {
                  $Output->render("@#Yellow:   ! Left untouched — the file or its directory is not writable @;\n\n");
               }
            }
            else if (($valid = $this->validate($corrected)) !== true) {
               if (!$Agent->detected) {
                  $Output->render($valid === null
                     ? "@#Red:   ✗ Could not validate the fix (no php -l available) — skipped @;\n\n"
                     : "@#Red:   ✗ Fix produced invalid PHP — skipped @;\n\n"
                  );
               }
            }
            else if ($this->replace($file, $corrected, $mode) === false) {
               // ? The rewrite went to a sibling temporary and was discarded:
               //   the file on disk is byte-identical to what was analyzed
               if (!$Agent->detected) {
                  $Output->render("@#Yellow:   ! Left untouched — the rewrite could not replace the file @;\n\n");
               }
            }
            else {
               // ! Fixed means RESOLVED: what was written is analyzed again,
               //   and only a clean file counts — what remains is reported
               //   as it stands now, not as it was; what was fixed, as it was
               $Result = $Facade->analyze($file);
               $fixed = $Result->failed === false;
               if ($fixed) {
                  $fixedFiles++;
               }
               else {
                  $reported = $Result->issues;
                  $issueCount = count($reported);
               }
               if (!$Agent->detected) {
                  $Output->render($fixed
                     ? "@#Green:   ✓ Fixed {$issueCount} issue(s) @;\n\n"
                     : "@#Yellow:   ! Rewritten, but {$issueCount} issue(s) remain @;\n\n"
                  );
               }
            }
         }

         if ($fixed === false) {
            $unresolved += $issueCount;
         }

         // @ Collect report entry
         if ($Agent->detected) {
            $issueEntries = [];
            foreach ($reported as $Issue) {
               $issueEntries[] = [
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

      // @ Output
      if ($Agent->detected) {
         // @ JSON output for AI agents
         echo json_encode([
            'result'    => $unresolved === 0 ? 'passed' : 'failed',
            'submodule' => $submodule,
            'fixable'   => $fixable,
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
            ],
            'report'  => $report,
            'skipped' => $skipped,
         ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

         return $unresolved === 0;
      }

      // @ Summary (human output)
      $Output->render("\n");
      if ($totalIssues === 0) {
         $Output->render("@#Green: ✓ No {$submodule} issues found. @;@.;");
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

      $Output->render("@#Black: ─────────────────────────────────────── @;@..;");

      if ($skipped !== []) {
         $declined = count($skipped);
         $Output->render("@#Yellow: ! {$declined} file(s) not linted — see the notices above. @;@.;");
      }

      // : Green only when nothing is left — in every mode
      return $unresolved === 0;
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
      if ($Facade instanceof Nullables) {
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
            /** @var array{description: string, fixable: bool, arguments: array<string,string>}|string $value */
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
         $Fieldset->content = 'bootgly lint @#Black: <submodule> @;@.;';
         $Fieldset->content .= 'bootgly lint @#Black: <submodule> [path] @;@.;';
         $Fieldset->content .= 'bootgly lint @#Black: <submodule> [path] --fix @;';
         $Fieldset->render();

         // # Examples — a kit runs them from a project directory
         if (Workspaces::detect() === Workspaces::Kit) {
            $examples = '@#Black:cd projects/<Name>@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint imports@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint imports Models/ --fix@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint nullables --dry-run@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint promotions@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint methods Controllers/@;';
         }
         else {
            $examples = '@#Black:bootgly lint imports@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint imports Bootgly/ABI/ --fix@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint nullables --dry-run@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint nullables app/ --fix@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint promotions@;' . PHP_EOL;
            $examples .= '@#Black:bootgly lint methods app/@;';
         }
         $Fieldset = new Fieldset($Output);
         $Fieldset->title = '@#green: Lint examples @;';
         $Fieldset->content = $examples;
         $Fieldset->render();
      }
      else if ( isSet($this->arguments[$arguments[0]]) ) {
         // @ Show usage for a valid submodule
         $submodule = $arguments[0];
         /** @var array{description: string, fixable: bool, arguments: array<string,string>} $meta */
         $meta = $this->arguments[$submodule];
         $fixable = $meta['fixable'];

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

         // # Usage — a check-only submodule has no --fix / --dry-run
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
   private function overlap (string $path): bool
   {
      $target = realpath($path);

      // ?: Not there — the scan reports the missing path itself
      if ($target === false) {
         return false;
      }
      $target = rtrim($target, '/') . '/';

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

      // @@ Inside a pinned tree, or holding one
      foreach ($trees as $tree) {
         $pinned = realpath($tree);
         if ($pinned === false) {
            continue;
         }
         $pinned = rtrim($pinned, '/') . '/';

         // ?: Inside it
         if (str_starts_with($target, $pinned)) {
            return true;
         }
         // ?: Holding it — unless the scan skips it anyway (a `vendor/` between
         //   them: a framework installed through Composer)
         if (str_starts_with($pinned, $target) && str_contains('/' . substr($pinned, strlen($target)), '/vendor/') === false) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Refuse the run before anything is scanned, saying why — as the agent
    * JSON document or as a human alert.
    *
    * @param Agent $Agent The detected agent, if any
    * @param string $submodule The submodule that was asked for
    * @param bool $fixable Whether the submodule formats
    * @param string $mode `check`, `fix` or `dry-run`
    * @param string $message Why the run is refused
    *
    * @return false
    */
   private function refuse (Agent $Agent, string $submodule, bool $fixable, string $mode, string $message): false
   {
      if ($Agent->detected) {
         echo json_encode([
            'result'    => 'failed',
            'submodule' => $submodule,
            'fixable'   => $fixable,
            'agent'     => $Agent->name,
            'mode'      => $mode,
            'message'   => $message,
            'files'     => ['scanned' => 0, 'failed' => 0, 'fixed' => 0, 'skipped' => 0],
            'issues'    => ['total' => 0, 'unresolved' => 0],
            'report'    => [],
            'skipped'   => [],
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
    *
    * @return array<int,string>
    */
   private function collect (string $path): array
   {
      if (is_file($path)) {
         return [$path];
      }

      if (!is_dir($path)) {
         return [];
      }

      $files = [];
      /** @var RecursiveIteratorIterator<RecursiveDirectoryIterator> $iterator */
      $iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($path)
      );

      $root = rtrim($path, '/') . '/';
      /** @var \SplFileInfo $file */
      foreach ($iterator as $file) {
         // ? A link is scanned where it points, never through the name that
         //   points — `--fix` must never write outside the tree it was given
         if ($file->isLink()) {
            continue;
         }
         if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $pathname = $file->getPathname();

            // @ Skip vendor, examples and vs INSIDE the scanned path — tests
            //   are code and are linted too; what the caller named is scanned
            //   whatever its ancestors are called
            $relative = '/' . substr($pathname, strlen($root));
            if (str_contains($relative, '/vendor/')
               || str_contains($relative, '/examples/')
               || str_contains($relative, '/vs/')
            ) {
               continue;
            }

            $files[] = $pathname;
         }
      }

      sort($files);

      return $files;
   }

   /**
    * Replace a file's content atomically — through a sibling temporary and a
    * rename, never through the name that was collected.
    *
    * A name inside the scanned tree may have become a link since collection
    * (`--fix` must never write outside the tree it was given), and a write
    * may stop short (a full disk): the temporary is created exclusively next
    * to the file, written whole, given the file's mode and renamed over it —
    * a rename replaces the NAME, so a planted link is discarded, not
    * followed, and a failed write leaves the original byte-identical. The
    * new inode keeps the file's mode only: a hard link to the old inode
    * keeps the old content, setuid/setgid/sticky bits and ACLs are not
    * carried over — a source file needs none — the new inode belongs to
    * whoever runs the fix (root rewrites to root), and a DIRECTORY swapped
    * inside the tree mid-run is still resolved at the rename, which no
    * portable call can pin.
    *
    * @param string $file The file to replace
    * @param string $source Its new content
    * @param int $mode The mode the file had when it was read — the new inode's
    *
    * @return bool Whether the file now carries the content
    */
   private function replace (string $file, string $source, int $mode): bool
   {
      $temporary = null;

      try {
         $temporary = dirname($file) . '/.' . basename($file) . '.' . bin2hex(random_bytes(8));
         $handle = fopen($temporary, 'x');
         if ($handle === false) {
            return false;
         }

         // ! The file's mode first — before a byte lands in the temporary, so
         //   a private source is never readable by others for an instant
         chmod($temporary, $mode);

         $written = fwrite($handle, $source);
         $closed = fclose($handle);
         if ($written !== strlen($source) || $closed === false) {
            unlink($temporary);
            return false;
         }

         if (rename($temporary, $file) === false) {
            unlink($temporary);
            return false;
         }
      }
      catch (Throwable) {
         if ($temporary !== null && is_file($temporary)) {
            unlink($temporary);
         }
         return false;
      }

      // :
      return true;
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
