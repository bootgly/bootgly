<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests;


use const BOOTGLY_WORKING_DIR;
use const T_ABSTRACT;
use const T_ATTRIBUTE;
use const T_CASE;
use const T_CATCH;
use const T_CLASS;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CONST;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_CURLY_OPEN;
use const T_DECLARE;
use const T_DEFAULT;
use const T_DNUMBER;
use const T_DO;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_DOUBLE_ARROW;
use const T_DOUBLE_COLON;
use const T_ELSE;
use const T_ELSEIF;
use const T_ENDDECLARE;
use const T_ENDFOR;
use const T_ENDFOREACH;
use const T_ENDIF;
use const T_ENDSWITCH;
use const T_ENDWHILE;
use const T_ENUM;
use const T_FINAL;
use const T_FINALLY;
use const T_FOR;
use const T_FOREACH;
use const T_FUNCTION;
use const T_GLOBAL;
use const T_IF;
use const T_INTERFACE;
use const T_LNUMBER;
use const T_MATCH;
use const T_NAME_FULLY_QUALIFIED;
use const T_NAME_QUALIFIED;
use const T_NAME_RELATIVE;
use const T_NAMESPACE;
use const T_OPEN_TAG;
use const T_PRIVATE;
use const T_PROTECTED;
use const T_PUBLIC;
use const T_READONLY;
use const T_STRING;
use const T_SWITCH;
use const T_TRAIT;
use const T_TRY;
use const T_USE;
use const T_VAR;
use const T_VARIABLE;
use const T_WHILE;
use const T_WHITESPACE;
use function array_intersect_key;
use function array_keys;
use function array_pop;
use function count;
use function explode;
use function extension_loaded;
use function file_get_contents;
use function function_exists;
use function get_included_files;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
use function is_readable;
use function ksort;
use function preg_replace;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function token_get_all;
use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use Bootgly\ACI\Tests\Coverage\Driver;
use Bootgly\ACI\Tests\Coverage\Drivers\Native;
use Bootgly\ACI\Tests\Coverage\Drivers\Native\Analyzer;
use Bootgly\ACI\Tests\Coverage\Drivers\Nothing;


/**
 * Coverage session — owns one Driver and produces Reports.
 *
 *   $Coverage = new Coverage();          // auto-detect driver
 *   $Coverage->start();
 *   // ...run tests...
 *   $Coverage->stop();
 *   echo $Coverage->report('text');
 */
class Coverage
{
   /**
    * Report formats `report()` renders, by name.
    */
   public const array REPORTS = [
      'clover' => Coverage\Reports\Clover::class,
      'html' => Coverage\Reports\HTML::class,
      'text' => Coverage\Reports\Text::class,
   ];
   /**
    * Resource directory names whose PHP files are not code to cover — an
    * unloaded source inside one never joins the report at zero hits.
    */
   public const array RESOURCES = [
      'examples' => true,
      'fixtures' => true,
      'stubs' => true,
      'templates' => true,
      'tests' => true,
   ];

   /**
    * Live hit collector — written to by instrumented user code through
    * the static `hit()` entry point. Drivers may also seed/merge it.
    *
    * Shape: [absolute_file_path => [line_number => hits]]
    *
    * @var array<string, array<int, int>>
    */
   public static array $hits = [];

   // * Config
   /**
    * Active coverage backend used by this session.
    */
   public private(set) Driver $Driver;

   // * Data
   /**
    * Path fragments to include in the report (case-sensitive).
    * When non-empty, only files whose normalized path contains at
    * least one entry are kept.  Empty means "include everything"
    * (minus the always-excluded test scripts).
    *
    * @var array<string>
    */
   public array $includes = [];
   /**
    * Exact SUT file targets to keep in the report.
    *
    * When non-empty, files are kept only when their normalized canonical
    * path matches one of these targets. This keeps reports focused on the
    * subject-under-test even when runtime infrastructure executes.
    *
    * @var array<string>
    */
   public array $targets = [];
   /**
    * Directories measured in full: every PHP source under them that the
    * run never loaded joins the report at zero hits, so a floor counts
    * what no test reached. Their executable lines are estimated by the
    * static analyzer of the Native driver. Sources inside a resource
    * directory below the path (see `RESOURCES`) and sources the
    * `$includes`/`$targets` filters reject stay out; under the Native
    * driver, sources it never instruments go to `$unmeasured`.
    *
    * @var array<string>
    */
   public array $paths = [];
   /**
    * Changed lines to measure, `[canonical_file => [line => true]]`
    * (see `Coverage\Changes`). When set, a changed PHP source no test
    * loaded joins at zero hits (when the filters admit it), then the report
    * keeps only the statements these lines touch and drops every file
    * without one; `null` measures every line. A changed line inside a
    * statement over several lines (an argument, an array element, a
    * heredoc body) touches that statement; a comment or blank line
    * touches none.
    *
    * @var null|array<string, array<int, true>>
    */
   public null|array $changes = null;
   /**
    * Show per-file coverage diff blocks in report formatters that support it.
    */
   public bool $diff = false;

   // * Metadata
   /**
    * PHP sources in the report's scope that the driver cannot measure
    * (paths the Native driver never instruments) — sources a test loaded
    * and sources under `$paths`; when `$changes` is set, only the changed
    * ones whose changed lines hold code (declarations do not), plus any
    * source whose changed code no executable line of the driver stands for
    * (a closure body, a `match` arm, a `case` label with no line of its own
    * — any under Native, a non-constant one otherwise —, a property hook
    * under PCOV). The coverage engine itself is never
    * listed: the runner always loads it. Filled by `stop()`. A gate must
    * not read them as unchanged or uncovered.
    *
    * The Native driver records only sources loaded after `start()`: an
    * in-scope source loaded before it (by the bootstrap) is absent from
    * the report, not listed here.
    *
    * @var array<int,string>
    */
   public private(set) array $unmeasured = [];
   /**
    * Filtered hit map produced by `stop()`.
    *
    * @var array<string, array<int, int>>
    */
   public private(set) array $data = [];


   /**
    * Create a coverage session with an explicit Driver or auto-detected one.
    */
   public function __construct (null|Driver $Driver = null)
   {
      $this->Driver = $Driver ?? self::detect();
   }

   /**
    * Hit recorder — invoked by instrumented source files at runtime.
    * Kept extremely small to minimize per-line overhead.
    */
   public static function hit (string $file, int $line): void
   {
      self::$hits[$file][$line] = (self::$hits[$file][$line] ?? 0) + 1;
   }

   /**
    * Wipe the hit collector.
    */
   public static function reset (): void
   {
      self::$hits = [];
   }

   /**
    * Seed executable-line denominators (zero-hit) from a Universe map.
    *
    * @param array<string, array<int, int>> $map
    */
   public static function seed (array $map): void
   {
      foreach ($map as $file => $lines) {
         foreach ($lines as $line => $_) {
            self::$hits[$file][$line] ??= 0;
         }
      }
   }

   /**
    * Start recording coverage data through the configured Driver.
    */
   public function start (): void
   {
      $this->Driver->start();
   }

   /**
    * Stop the Driver, collect raw hits, and apply include/target filters.
    */
   public function stop (): void
   {
      $this->Driver->stop();
      $raw = $this->Driver->collect();

      $includes = [];
      foreach ($this->includes as $include) {
         $include = str_replace('\\', '/', $include);
         if ($include !== '') {
            $includes[] = $include;
         }
      }

      $targets = [];
      foreach ($this->targets as $target) {
         $targets[self::normalize($target)] = true;
      }

      $matchedTarget = $targets === [];
      if (! $matchedTarget) {
         foreach ($raw as $file => $_) {
            if (isset($targets[self::normalize($file)])) {
               $matchedTarget = true;
               break;
            }
         }
      }

      // Filter the raw hit map:
      //   - Always exclude test-script files (lowercase /tests/ dirs).
      //   - When $includes is set, keep only files matching a scope.
      //   - When $targets is set and present in raw data, keep only exact
      //     target file matches; otherwise keep the package include scope.
      $data = [];
      /** @var array<string, true> $unmeasured */
      $unmeasured = [];
      foreach ($raw as $file => $lines) {
         $canonical = self::normalize($file);

         if (self::accept($canonical, $includes, $targets, $matchedTarget) === false) {
            continue;
         }

         if (! isset($data[$canonical])) {
            $data[$canonical] = [];
         }

         foreach ($lines as $line => $hits) {
            $line = (int) $line;
            $hits = (int) $hits;
            $current = $data[$canonical][$line] ?? 0;
            $data[$canonical][$line] = $hits > $current ? $hits : $current;
         }
      }

      // @ Sources no test loaded join at zero hits
      foreach ($this->paths as $path) {
         $this->expand($path, $data, $unmeasured, $includes, $targets, $matchedTarget);
      }

      // @ Sources loaded during the run that the Native driver never
      //   instruments: in scope but never measured, so they cannot vanish
      //   from a gate. The engine itself — this directory's `Coverage/`,
      //   not a same-named path elsewhere — is left out: the runner always
      //   loads it, whatever the tests load (a measured path or a diff
      //   still reports it).
      if ($this->Driver instanceof Native) {
         $engine = self::normalize(__DIR__) . '/Coverage/';
         foreach (get_included_files() as $file) {
            $canonical = self::normalize($file);
            if (
               isset($data[$canonical])
               || str_ends_with(strtolower($canonical), '.php') === false
               || Native::allow($canonical)
               || str_starts_with($canonical, $engine)
               || self::resource(self::relate($canonical))
               || self::accept($canonical, $includes, $targets, $matchedTarget) === false
            ) {
               continue;
            }

            $unmeasured[$canonical] = true;
         }
      }

      // @ Restrict to the changed lines
      if ($this->changes !== null) {
         $changed = [];
         foreach ($this->changes as $file => $lines) {
            $changed[self::normalize($file)] = $lines;
         }

         // ? A changed source no test loaded is uncovered, not unchanged:
         //   it joins at zero hits instead of vanishing from the gate
         foreach ($changed as $file => $_) {
            if (str_ends_with(strtolower($file), '.php') === false) {
               continue;
            }
            if (self::resource(self::relate($file))) {
               continue;
            }

            $this->admit($file, $data, $unmeasured, $includes, $targets, $matchedTarget);
         }

         foreach ($data as $file => $lines) {
            // ? A changed line that continues a statement counts as the
            //   statement's executable line (see `locate()`)
            $rows = $changed[$file] ?? [];
            $source = $rows !== [] && is_readable($file) ? file_get_contents($file) : false;
            $touched = $rows;
            if ($source !== false) {
               // ? Native never probes a `case` label or arm condition: a
               //   label with no line has nothing that stands for it
               [$touched, $missed] = self::locate($source, $rows, $lines, $this->Driver instanceof Native === false);
               // ? Changed code the driver records no line for (a Native
               //   closure or arm, a body PCOV never saw, an unloaded
               //   source's closure): unmeasured, not unchanged
               if ($missed !== []) {
                  $unmeasured[$file] = true;
               }
            }
            $kept = array_intersect_key($lines, $touched);
            if ($kept === []) {
               unset($data[$file]);
               continue;
            }

            $data[$file] = $kept;
         }

         // ? Only changed sources out of reach matter to a diff, and only
         //   when a changed line holds code (a comment edit does not);
         //   a source that cannot be read stays unmeasured
         foreach ($unmeasured as $file => $_) {
            if (isset($changed[$file]) === false) {
               unset($unmeasured[$file]);
               continue;
            }

            $source = is_readable($file) ? file_get_contents($file) : false;
            if ($source === false) {
               continue;
            }

            // ? A changed row holding code — a row of any statement that is
            //   not a declaration, whatever the analyzer estimates as
            //   executable
            [$members, , , $declarations] = self::parse($source);
            $code = false;
            foreach ($members as $statement => $rows) {
               if (isset($declarations[$statement]) === false && array_intersect_key($rows, $changed[$file]) !== []) {
                  $code = true;
                  break;
               }
            }
            if ($code === false) {
               unset($unmeasured[$file]);
            }
         }
      }

      ksort($data);
      foreach ($data as &$lines) {
         ksort($lines);
      }
      unset($lines);

      $this->data = $data;
      ksort($unmeasured);
      $this->unmeasured = array_keys($unmeasured);
   }

   /**
    * Render the captured hit map using a named report formatter.
    */
   public function report (string $format = 'text'): string
   {
      $class = self::REPORTS[strtolower($format)]
         ?? throw new LogicException("Coverage report not found: {$format}");

      $Report = new $class();
      $Report->diff = $this->diff;

      return $Report->render($this->data);
   }

   /**
    * Auto-detect the best available extension driver: Xdebug (coverage
    * mode), then PCOV, else `Nothing`.
    *
    * The Native driver is opt-in (`--coverage-driver=native`) and never
    * auto-detected: it must start before the sources it measures load and
    * needs `opcache.enable_cli=0`, so a silent pick would report a partial
    * run. A caller that gets `Nothing` back measured nothing and must say
    * so instead of writing a report.
    */
   public static function detect (): Driver
   {
      if (function_exists('xdebug_start_code_coverage')) {
         return new Coverage\Drivers\XDebug();
      }

      if (extension_loaded('xdebug')) {
         throw new LogicException(
            'Xdebug was detected but coverage mode is disabled; '
            . 'set XDEBUG_MODE=coverage (or xdebug.mode=coverage).'
         );
      }

      if (extension_loaded('pcov')) {
         return new Coverage\Drivers\PCOV();
      }

      return new Nothing();
   }

   /**
    * Tell whether a canonical source belongs in the report.
    *
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private static function accept (string $canonical, array $includes, array $targets, bool $matched): bool
   {
      // ? Skip test scripts (e.g. Bootgly/ABI/Code/__Array/tests/1.x.Test.php).
      //   The test-framework source lives under uppercase /Tests/, so this
      //   case-sensitive check does NOT exclude those files. It reads the
      //   path below the working directory: a checkout that itself sits in
      //   a `tests/` directory (a CI workspace named so) keeps its sources.
      $relative = self::relate($canonical);
      if (str_contains("/{$relative}", '/tests/')) {
         return false;
      }

      // ? If an include scope is set, only keep files within that scope.
      if ($includes !== []) {
         $match = false;
         foreach ($includes as $include) {
            if (str_contains($canonical, $include)) {
               $match = true;
               break;
            }
         }
         if ($match === false) {
            return false;
         }
      }

      // ?: If exact SUT targets are set, keep only matched files.
      return ! ($matched && $targets !== [] && ! isset($targets[$canonical]));
   }

   /**
    * Add one PHP source the hit map lacks, with its statically analyzed
    * executable lines at zero hits.
    *
    * @param array<string, array<int, int>> $data
    * @param array<string, true> $unmeasured
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private function admit (string $canonical, array &$data, array &$unmeasured, array $includes, array $targets, bool $matched): void
   {
      // ?
      if (isset($data[$canonical])) {
         return;
      }
      if (self::accept($canonical, $includes, $targets, $matched) === false) {
         return;
      }
      // ? Native never instruments its excluded paths: they would read as
      //   unreached even when they ran — in scope but out of the driver's
      //   reach, so they are reported as unmeasured, never dropped
      if ($this->Driver instanceof Native && Native::allow($canonical) === false) {
         $unmeasured[$canonical] = true;

         return;
      }
      if (is_file($canonical) === false || is_readable($canonical) === false) {
         return;
      }

      $source = file_get_contents($canonical);
      if ($source === false) {
         return;
      }

      // @
      $lines = Analyzer::scan($source);
      if ($lines === []) {
         return;
      }

      ksort($lines);
      $data[$canonical] = $lines;
   }

   /**
    * Add the PHP sources under one directory that the hit map lacks, each
    * with its statically analyzed executable lines at zero hits.
    *
    * Sources inside a resource directory below the path (see `RESOURCES`)
    * are not code to cover and stay out.
    * Subdirectories that cannot be read are skipped.
    *
    * @param array<string, array<int, int>> $data
    * @param array<string, true> $unmeasured
    * @param array<string> $includes
    * @param array<string,bool> $targets
    */
   private function expand (string $path, array &$data, array &$unmeasured, array $includes, array $targets, bool $matched): void
   {
      // ?
      $directory = realpath($path);
      if ($directory === false || is_dir($directory) === false || is_readable($directory) === false) {
         throw new LogicException("Coverage path is not a readable directory: {$path}");
      }
      $root = rtrim(self::normalize($directory), '/');

      // !
      $Files = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::LEAVES_ONLY,
         RecursiveIteratorIterator::CATCH_GET_CHILD
      );

      // @@
      foreach ($Files as $File) {
         /** @var SplFileInfo $File */
         if ($File->isFile() === false || strtolower($File->getExtension()) !== 'php') {
            continue;
         }

         $canonical = self::normalize($File->getPathname());

         if (self::resource(substr($canonical, strlen($root) + 1))) {
            continue;
         }

         $this->admit($canonical, $data, $unmeasured, $includes, $targets, $matched);
      }
   }

   /**
    * Find the executable lines a set of changed lines touches.
    *
    * A changed executable line counts as itself. A changed line that
    * continues a statement — an argument, an array element, a heredoc
    * body, a closing `);` — counts as one of that statement's executable
    * lines, so a driver that marks only one line of a statement still sees
    * it. Lines holding only comments or whitespace, and lines that open a
    * declaration or a block, touch none.
    *
    * The statement's line is chosen by what else runs on it: a line of
    * its own first, then one it shares only with other statements, then
    * one it shares with a header that runs whether or not its body does
    * (`if (…)`, `case …:`, a `match` arm condition) — line coverage cannot
    * tell those apart, as in a one-line `if ($a) return;`. A `switch` or
    * `match` subject with no executable line of its own counts as the
    * first executable line of its block, and — when `$chained` — a `case`
    * label or arm condition of literals and constants with none as its
    * subject's line (PHP may compare those in a jump table there, where
    * PCOV and Xdebug attribute them); any other label with no line is
    * missed.
    *
    * Also gives the changed lines where a statement holding code touches
    * no executable line — code the driver has no line for —, even beside
    * a statement that does.
    *
    * @param array<int, true> $changed
    * @param array<int, int> $lines Executable lines of the source.
    * @param bool $chained Whether a constant label with no line counts as
    * its subject's line (a driver that attributes lines as PHP compiles
    * them: PCOV, Xdebug); otherwise it is missed.
    *
    * @return array{0: array<int, true>, 1: array<int, true>} `[touched, missed]`
    */
   private static function locate (string $source, array $changed, array $lines, bool $chained): array
   {
      // !
      [$members, $headers, $subjects, $declarations, $labels] = self::parse($source);
      // Row => statements with a token on it
      $owners = [];
      foreach ($members as $statement => $rows) {
         foreach ($rows as $row => $_) {
            $owners[$row][$statement] = true;
         }
      }

      // @@
      $touched = [];
      $missed = [];
      foreach ($changed as $row => $_) {
         if (isset($lines[$row])) {
            $touched[$row] = true;
            continue;
         }

         // @@ Each statement with code on the row: its executable row that
         //    the fewest other things share
         foreach ($owners[$row] ?? [] as $owner => $_) {
            $best = null;
            // @@ The statement's line; a `case` label or an arm condition
            //    without one is compared where its `switch`/`match` runs
            //    (a jump table), so it takes its subject's line
            $statement = $owner;
            while ($best === null && $statement !== null) {
               $rank = 3;
               foreach ($members[$statement] as $candidate => $_) {
                  if (isset($lines[$candidate]) === false) {
                     continue;
                  }

                  $others = $owners[$candidate];
                  unset($others[$statement]);
                  $level = 0;
                  if ($others !== []) {
                     $level = array_intersect_key($others, $headers[$candidate] ?? []) === [] ? 1 : 2;
                  }
                  if ($level < $rank) {
                     $best = $candidate;
                     $rank = $level;
                  }
                  if ($rank === 0) {
                     break;
                  }
               }

               // ? A subject with no line: the first executable line of its block
               if ($best === null && isset($subjects[$statement])) {
                  [$open, $close] = $subjects[$statement];
                  for ($candidate = $open; $candidate <= $close; $candidate++) {
                     if (isset($lines[$candidate])) {
                        $best = $candidate;
                        break;
                     }
                  }
               }

               $statement = $chained ? $labels[$statement] ?? null : null;
            }

            if ($best !== null) {
               $touched[$best] = true;
            }
            // ? Code on the row that no executable line stands for
            else if (isset($declarations[$owner]) === false) {
               $missed[$row] = true;
            }
         }
      }

      // :
      return [$touched, $missed];
   }

   /**
    * Split a source into statements, as `locate()` reads them.
    *
    * Gives the rows each statement spans, the rows holding a header that runs whether or not its body
    * does — a control statement's parenthesized header, a `case` label or
    * a `match` arm condition —, the rows each `switch`/`match` block spans
    * (by the statement holding its subject), the statements that hold
    * no code of their own — declarations (`namespace`, `use`, `global`,
    * classes, functions and their signatures, every statement of a
    * class-like body or a trait adaptation, property hooks other than a
    * short `get => …`), `else`, `do`, `try`, a bare `{`, a `default` label
    * or arm condition and goto labels —, and the subject statement of
    * each `case` label and arm condition made only of literals and
    * constants.
    *
    * @return array{
    *    0: array<int, array<int, true>>,
    *    1: array<int, array<int, true>>,
    *    2: array<int, array{0: int, 1: int}>,
    *    3: array<int, true>,
    *    4: array<int, int|null>
    * } `[statement => rows, row => header statements, statement => block rows, declarations, label => subject statement]`
    */
   private static function parse (string $source): array
   {
      // !
      $ignored = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_CLOSE_TAG => true];
      // Tokens that carry on the statement a `}` interrupted (a closure argument)
      $resumers = [',' => true, ')' => true, ']' => true, ';' => true, '->' => true, '?->' => true, 'as' => true];
      // Statements whose parenthesized header runs whether or not their body does
      $controls = [
         T_CATCH => true, T_DECLARE => true, T_ELSEIF => true, T_FOR => true,
         T_FOREACH => true, T_IF => true, T_SWITCH => true, T_WHILE => true,
      ];
      // Statements that hold no code of their own
      $inert = [
         '{' => true, T_ABSTRACT => true, T_ATTRIBUTE => true, T_CLASS => true, T_CONST => true, T_DECLARE => true,
         T_DO => true, T_ELSE => true, T_ENDDECLARE => true, T_ENDFOR => true, T_ENDFOREACH => true,
         T_ENDIF => true, T_ENDSWITCH => true, T_ENDWHILE => true, T_ENUM => true, T_FINAL => true,
         T_FINALLY => true, T_FUNCTION => true, T_GLOBAL => true, T_INTERFACE => true, T_NAMESPACE => true, T_PRIVATE => true,
         T_PROTECTED => true, T_PUBLIC => true, T_READONLY => true, T_TRAIT => true, T_TRY => true,
         T_USE => true, T_VAR => true,
      ];
      // Tokens after which a keyword is a member's name (`A::do()`,
      // `function class ()`), never a keyword
      $names = ['function' => true, '::' => true];
      // Tokens a constant `case` or arm condition is made of
      $constants = [
         ',' => true, '+' => true, '-' => true, ':' => true, ';' => true,
         T_CASE => true, T_CLASS => true, T_CONSTANT_ENCAPSED_STRING => true, T_DEFAULT => true,
         T_DNUMBER => true, T_DOUBLE_ARROW => true, T_DOUBLE_COLON => true, T_LNUMBER => true,
         T_NAME_FULLY_QUALIFIED => true, T_NAME_QUALIFIED => true, T_NAME_RELATIVE => true, T_STRING => true,
      ];
      // Tokens that declare a class-like body (every statement in it declares)
      $classes = [T_CLASS => true, T_ENUM => true, T_INTERFACE => true, T_TRAIT => true];
      // Tokens after which a `?` starts a nullable type, never a ternary
      $types = [
         '(' => true, ',' => true, ':' => true, '?:' => true, ']#' => true,
         'const' => true, 'private' => true, 'protected' => true, 'public' => true,
         'readonly' => true, 'static' => true, 'var' => true,
      ];
      // Statement => rows its tokens span
      $members = [];
      // Row => header statements on it
      $headers = [];
      // Statement holding a `switch`/`match` subject => [first, last] row of its block
      $subjects = [];
      // Statements without code of their own
      $declarations = [];
      // `case` label or arm condition => the statement holding its subject
      $labels = [];
      // Open blocks: the state each interrupted (`false`: a `{$` interpolation)
      /** @var list<false|array{0: array{0: null|int, 1: int, 2: null|int|string, 3: int, 4: bool, 5: int, 6: bool, 7: null|int, 8: bool, 9: array<int, true>}, 1: bool, 2: bool, 3: null|int, 4: int, 5: null|int, 6: bool, 7: bool}> $stack */
      $stack = [];
      // Open alternative-syntax switches: [statement, row, enclosing `$cases`, enclosing `$within`]
      $switches = [];
      // Bracket depths at which an attribute opened
      $attributes = [];
      $id = 0;
      // # Current statement
      $statement = null;
      $first = null;
      $count = 0;
      $header = false;
      // Brackets open in it (`;` and `,` inside them end nothing)
      $depth = 0;
      $ternaries = 0;
      // A `match` arm condition (its `=>` ends it)
      $condition = false;
      // A `match (…)` whose arms block comes next (`$pending`: until its `(`)
      $match = false;
      $pending = false;
      // Depth of the class-like keyword it declares (its block at that depth
      // holds declarations); `$classing` until the next token (`class:` is
      // a named argument)
      $classy = null;
      $classing = false;
      // Every token is a constant (a `case` or arm condition PHP may compare
      // in a jump table at its subject)
      $constant = true;
      // It declares a property (its block holds hooks)
      $property = false;
      // Depths of its `function` keywords whose body block has not opened
      // (a closure as a property default holds code, not hooks)
      $functions = [];
      // A property hook (`get`/`set`): it declares unless a `=>` gives it code
      $hook = false;
      // # Current block
      // A `match` arms block (`,` ends an arm)
      $arms = false;
      // A `switch` block (`case …;` is a label too)
      $cases = false;
      // The statement holding the subject of the enclosing `switch`/`match`
      $within = null;
      // A class-like body or a trait adaptation: every statement declares
      $body = false;
      // A property's hooks
      $hooks = false;
      // A header just closed: `{`, `:` or a braceless body follows
      $opened = false;
      $resume = null;
      $previous = ';';
      $before = ';';
      $line = 1;

      // @@ Which statement each row's code belongs to
      foreach (token_get_all($source) as $token) {
         $kind = is_array($token) ? $token[0] : $token;
         $text = is_array($token) ? $token[1] : $token;
         $start = is_array($token) ? $token[2] : $line;
         $end = $start + substr_count($text, "\n");
         $line = $end;
         if (is_array($token) && isset($ignored[$token[0]])) {
            if ($token[0] === T_OPEN_TAG || $token[0] === T_CLOSE_TAG) {
               $previous = ';';
               $statement = null;
               $opened = false;
            }

            continue;
         }
         // ? A keyword naming a member is a name (`&` after `function`
         //   declares by reference); `A::$x` stays a variable
         if (
            is_int($kind) && $kind !== T_VARIABLE
            && (isset($names[strtolower($previous)]) || ($previous === '&' && strtolower($before) === 'function'))
         ) {
            $kind = T_STRING;
         }

         // ! The token's role: braces of a string interpolation, a `;`
         //   inside brackets (`for (;;)`), a ternary `:` and a nullable `?`
         //   delimit nothing; a header's or a label's `:`, an arm's `,` and
         //   an arm condition's `=>` end a statement
         $mark = $text;
         if ($kind === T_CURLY_OPEN || $kind === T_DOLLAR_OPEN_CURLY_BRACES) {
            $mark = '{$';
         }
         else if ($text === '}' && $stack !== [] && $stack[count($stack) - 1] === false) {
            $mark = '$}';
         }
         else if ($text === ';' && $depth > 0) {
            $mark = '(;';
         }
         else if (
            $text === ',' && $arms
            && ($statement === null && $previous === '}' && is_array($resume)
               ? $resume[1] === 0 && $resume[6] === false
               : $depth === 0 && $condition === false)
         ) {
            $mark = ';';
         }
         else if ($kind === T_DOUBLE_ARROW && $condition && $depth === 0) {
            $mark = '=>|';
         }
         else if ($text === '?' && isset($types[strtolower($previous)])) {
            $mark = '?type';
         }
         else if ($text === ':') {
            $label = $opened
               || ($statement !== null && $depth === 0 && $ternaries === 0
                  && ($first === T_CASE || $first === T_DEFAULT || ($count === 1 && $first === T_STRING)));
            $mark = $label ? ':' : '?:';
         }

         // ? `match` not followed by `(` names something (`match: 1`)
         if ($pending) {
            $pending = false;
            $match = $match && $mark === '(';
         }
         // ? `class`/`interface`/`trait`/`enum` followed by `:` is a named argument
         if ($classing) {
            $classing = false;
            if ($text === ':') {
               $classy = null;
            }
         }

         // ? A braceless body after a header is a statement of its own; a
         //   `;` alone is an empty one (`declare(strict_types=1);`)
         if ($opened && $mark !== '{' && $mark !== ':') {
            $statement = null;
            $previous = ';';
            if ($mark === ';') {
               $opened = false;
               $before = ';';
               continue;
            }
         }
         $opened = false;

         // ! The statement this token belongs to
         if ($statement === null && $mark !== '}' && $mark !== '$}') {
            if ($previous === '}' && is_array($resume) && isset($resumers[$mark])) {
               [$statement, $depth, $first, $count, $header, $ternaries, $condition, $classy, $property, $functions] = $resume;
               // ? A statement holding a block is no constant
               $constant = false;
            }
            else {
               $statement = ++$id;
               $first = $kind;
               $count = 0;
               $header = isset($controls[$kind]);
               $depth = 0;
               $ternaries = 0;
               $condition = $arms && $previous !== '=>|';
               $match = false;
               $classy = null;
               $property = false;
               $constant = true;
               $functions = [];
               $hook = $hooks;
               if ($body || $hook || isset($inert[$kind]) || ($kind === T_CASE && $cases === false)) {
                  $declarations[$statement] = true;
               }
            }
         }

         // ? A block's `}` closes it: it is no part of a last `match` arm
         //   left without a trailing comma
         if ($statement !== null && $mark !== '}') {
            $count++;
            $constant = $constant && isset($constants[$kind]);

            for ($row = $start; $row <= $end; $row++) {
               $members[$statement][$row] = true;
               if ($header) {
                  $headers[$row][$statement] = true;
               }
            }
         }

         // @ Transitions
         if ($kind === T_ATTRIBUTE) {
            $attributes[] = $depth;
            $depth++;
         }
         else if ($mark === '(' || $mark === '[') {
            $depth++;
         }
         else if ($mark === ')' || $mark === ']') {
            if ($depth > 0) {
               $depth--;
            }
            // ? An attribute closed: a `?` after it starts a type
            if ($mark === ']' && $attributes !== [] && $attributes[count($attributes) - 1] === $depth) {
               array_pop($attributes);
               $mark = ']#';
            }
            // ? A control statement's parenthesized header closed
            if ($mark === ')' && $depth === 0 && $header) {
               $opened = true;
            }
         }
         else if ($kind === T_MATCH) {
            $match = true;
            $pending = true;
         }
         else if (isset($classes[$kind])) {
            $classy = $depth;
            $classing = true;
         }
         else if ($kind === T_FUNCTION) {
            $functions[$depth] = true;
         }
         else if ($kind === T_VARIABLE && $depth === 0 && $statement !== null && isset($declarations[$statement])) {
            $property = true;
         }
         else if ($kind === T_DOUBLE_ARROW && $hook && $depth === 0 && $statement !== null) {
            // ? A short hook (`get => …`) holds code
            unset($declarations[$statement]);
            $hook = false;
         }
         else if ($mark === '?') {
            $ternaries++;
         }
         else if ($mark === '?:' && $ternaries > 0) {
            $ternaries--;
         }
         // ? Inside brackets they name an argument (`f(do: 1)`)
         else if (($kind === T_ELSE || $kind === T_DO) && $depth === 0) {
            // ? `else` and `do` close their header at once (`else if`
            //   starts a header)
            $opened = true;
         }
         else if ($kind === T_ENDSWITCH && $depth === 0 && $switches !== []) {
            [$subject, $open, $cases, $within] = array_pop($switches);
            $subjects[$subject] = [$open, $end];
         }
         else if ($mark === '{$') {
            $stack[] = false;
         }
         else if ($mark === '$}') {
            array_pop($stack);
         }
         else if ($mark === ';' || $mark === ':' || $mark === '=>|') {
            // ? A `case` label (`case …:` or `case …;` in a switch) and an
            //   arm condition run their comparison: their rows hold a header
            if ($statement !== null
               && (($first === T_CASE && $cases && $mark !== '=>|')
                  || ($mark === '=>|' && $first !== T_DEFAULT))) {
               foreach ($members[$statement] as $row => $_) {
                  $headers[$row][$statement] = true;
               }
               // ? Only a constant one may be compared in a jump table at
               //   the subject; any other is compared on its own
               if ($constant) {
                  $labels[$statement] = $within;
               }
            }
            // ? A label and a `default` arm hold no code of their own
            if ($statement !== null && ($first === T_DEFAULT || ($mark === ':' && $first === T_STRING))) {
               $declarations[$statement] = true;
            }
            // ? An alternative-syntax switch opens its cases
            if ($statement !== null && $mark === ':' && $first === T_SWITCH) {
               $switches[] = [$statement, $end, $cases, $within];
               $cases = true;
               $within = $statement;
            }
            $statement = null;
         }
         else if ($mark === '{') {
            $subject = $first === T_SWITCH || $match ? $statement : null;
            // ? A class-like keyword's own block opens its body; a block
            //   nested in its header (a closure argument) leaves it pending
            $opening = $classy === $depth;
            if ($opening) {
               $classy = null;
            }
            // ? A `function` keyword's own block is its body
            $closure = isset($functions[$depth]);
            unset($functions[$depth]);
            $stack[] = [
               [$statement, $depth, $first, $count, $header, $ternaries, $condition, $classy, $property, $functions],
               $arms, $cases, $subject, $end, $within, $body, $hooks,
            ];
            $arms = $match;
            $cases = $first === T_SWITCH && $statement !== null;
            $within = $subject;
            // ? A class-like or a trait adaptation block declares; a
            //   property's block holds its hooks
            $body = $statement !== null && ($opening || $first === T_USE);
            $hooks = $statement !== null && $property && $closure === false;
            $match = false;
            $statement = null;
         }
         else if ($mark === '}') {
            $frame = array_pop($stack);
            $resume = null;
            if (is_array($frame)) {
               [$resume, $arms, $cases, $subject, $open, $within, $body, $hooks] = $frame;
               if ($subject !== null) {
                  $subjects[$subject] = [$open, $end];
               }
               if ($resume[0] === null) {
                  $resume = null;
               }
            }
            $statement = null;
         }

         $before = $previous;
         $previous = $mark;
      }

      // :
      return [$members, $headers, $subjects, $declarations, $labels];
   }

   /**
    * Give a canonical source path relative to the working directory, or
    * unchanged when it lies outside it.
    */
   private static function relate (string $canonical): string
   {
      // !
      $working = rtrim(self::normalize(BOOTGLY_WORKING_DIR), '/');

      // :
      return str_starts_with($canonical, "{$working}/")
         ? substr($canonical, strlen($working) + 1)
         : $canonical;
   }

   /**
    * Tell whether a relative source path sits inside a resource directory
    * (one of `RESOURCES`), whose files are not code to cover.
    */
   private static function resource (string $relative): bool
   {
      // !
      $segments = explode('/', $relative);
      array_pop($segments);

      // @@
      foreach ($segments as $segment) {
         if (isset(self::RESOURCES[$segment])) {
            return true;
         }
      }

      // :
      return false;
   }

   /**
    * Normalize a file path used as a coverage-map key.
    */
   private static function normalize (string $file): string
   {
      $resolved = realpath($file);
      $path = $resolved !== false ? $resolved : $file;
      $path = str_replace('\\', '/', $path);

      return preg_replace('#(?<!:)/{2,}#', '/', $path) ?? $path;
   }
}
