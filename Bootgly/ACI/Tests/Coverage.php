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
use const T_ATTRIBUTE;
use const T_CASE;
use const T_CATCH;
use const T_CLOSE_TAG;
use const T_COMMENT;
use const T_CURLY_OPEN;
use const T_DECLARE;
use const T_DEFAULT;
use const T_DOC_COMMENT;
use const T_DOLLAR_OPEN_CURLY_BRACES;
use const T_ELSE;
use const T_ELSEIF;
use const T_FOR;
use const T_FOREACH;
use const T_IF;
use const T_MATCH;
use const T_OPEN_TAG;
use const T_STRING;
use const T_SWITCH;
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
    * ones whose changed lines hold code. The coverage engine
    * itself is never listed: the runner always loads it. Filled by
    * `stop()`. A gate must not read them as unchanged or uncovered.
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
            $kept = array_intersect_key($lines, $source === false ? $rows : self::locate($source, $rows, $lines));
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

            // ? A changed row holding code — any statement's row, whatever
            //   the analyzer estimates as executable
            [, $members] = self::parse($source);
            $code = false;
            foreach ($members as $rows) {
               if (array_intersect_key($rows, $changed[$file]) !== []) {
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
    * body, a closing `);` — counts as that statement's first executable
    * line, so a driver that marks only one line of a statement still sees
    * it. Lines holding only comments or whitespace, and lines that open a
    * declaration or a block, touch none.
    *
    * A header that runs whether or not its body does (`if (…)`,
    * `case …:`, `foreach (…)`) never stands for a statement of the body:
    * the body's own executable line is taken first, and the line it
    * shares with the header only when it has none (line coverage cannot
    * tell them apart, as in a one-line `if ($a) return;`).
    *
    * @param array<int, true> $changed
    * @param array<int, int> $lines Executable lines of the source.
    *
    * @return array<int, true>
    */
   private static function locate (string $source, array $changed, array $lines): array
   {
      // !
      [$continued, $members, $headers] = self::parse($source);

      // @@
      $touched = [];
      foreach ($changed as $row => $_) {
         if (isset($lines[$row])) {
            $touched[$row] = true;
            continue;
         }

         // ? The statement the row continues
         $owner = $continued[$row] ?? null;
         if ($owner === null) {
            continue;
         }

         // @ Its first executable row that no other header shares, else
         //   its first executable row
         $shared = null;
         foreach ($members[$owner] as $candidate => $_) {
            if (isset($lines[$candidate]) === false) {
               continue;
            }

            $others = $headers[$candidate] ?? [];
            unset($others[$owner]);
            if ($others === []) {
               $shared = null;
               $touched[$candidate] = true;
               break;
            }
            $shared ??= $candidate;
         }
         if ($shared !== null) {
            $touched[$shared] = true;
         }
      }

      // :
      return $touched;
   }

   /**
    * Split a source into statements, as `locate()` reads them.
    *
    * Gives which statement each row's code continues (a row whose first
    * code token starts a statement is not listed), the rows each statement
    * spans, and the rows holding a header that runs whether or not its
    * body does — a control statement's parenthesized header, or a `case`
    * label. `else`, `default:` and goto labels split statements but run
    * nothing of their own.
    *
    * @return array{
    *    0: array<int, int>,
    *    1: array<int, array<int, true>>,
    *    2: array<int, array<int, true>>
    * } `[continued row => statement, statement => rows, row => header statements]`
    */
   private static function parse (string $source): array
   {
      // !
      $ignored = [T_WHITESPACE => true, T_COMMENT => true, T_DOC_COMMENT => true, T_OPEN_TAG => true, T_CLOSE_TAG => true];
      $boundaries = [';' => true, '{' => true, '}' => true, ':' => true];
      // Tokens that carry on the statement a `}` interrupted (a closure argument)
      $resumers = [',' => true, ')' => true, ']' => true, ';' => true, '->' => true, '?->' => true];
      // Statements whose parenthesized header runs whether or not their body does
      $controls = [
         T_CATCH => true, T_DECLARE => true, T_ELSEIF => true, T_FOR => true,
         T_FOREACH => true, T_IF => true, T_SWITCH => true, T_WHILE => true,
      ];
      // Tokens after which a `?` starts a nullable type, never a ternary
      $types = [
         '(' => true, ',' => true, ':' => true, '?:' => true, '|' => true,
         'const' => true, 'private' => true, 'protected' => true, 'public' => true,
         'readonly' => true, 'static' => true, 'var' => true,
      ];
      // Row => statement a code token on it continues
      $continued = [];
      // Statement => rows its tokens span
      $members = [];
      // Row => header statements on it
      $headers = [];
      // Open blocks: the state each interrupted (`false`: a `{$` interpolation)
      $stack = [];
      $id = 0;
      // # Current statement
      $statement = null;
      $first = null;
      $count = 0;
      $header = false;
      // Brackets open in it (`;` and `,` inside them end nothing)
      $depth = 0;
      $ternaries = 0;
      // A `match (…)` whose arms block comes next
      $match = false;
      // # Current block: a `match` arms block (`,` ends an arm)
      $arms = false;
      // A header just closed: `{`, `:` or a braceless body follows
      $opened = false;
      $resume = null;
      $previous = ';';
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

         // ! The token's role: braces of a string interpolation, a `;`
         //   inside brackets (`for (;;)`), a ternary `:` and a nullable `?`
         //   delimit nothing; a header's or a label's `:` and an arm's `,`
         //   end a statement
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
         else if ($text === ',' && $arms && $depth === 0) {
            $mark = ';';
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

         // ? A braceless body after a header is a statement of its own
         if ($opened && $mark !== '{' && $mark !== ':') {
            $statement = null;
            $previous = ';';
         }
         $opened = false;

         // ! The statement this token belongs to
         $resumed = false;
         if ($statement === null && $mark !== '}' && $mark !== '$}') {
            if ($previous === '}' && is_array($resume) && isset($resumers[$mark])) {
               [$statement, $depth, $first, $count, $header, $ternaries] = $resume;
               $resumed = true;
            }
            else {
               $statement = ++$id;
               $first = $kind;
               $count = 0;
               $header = isset($controls[$kind]);
               $depth = 0;
               $ternaries = 0;
               $match = false;
            }
         }

         if ($statement !== null) {
            $count++;

            for ($row = $start; $row <= $end; $row++) {
               $members[$statement][$row] = true;
               if ($header) {
                  $headers[$row][$statement] = true;
               }

               if (isset($continued[$row]) === false
                  && ($row > $start || $resumed
                     || (isset($boundaries[$previous]) === false && isset($boundaries[$mark]) === false))) {
                  $continued[$row] = $statement;
               }
            }
         }

         // @ Transitions
         if ($mark === '(' || $mark === '[' || $kind === T_ATTRIBUTE) {
            $depth++;
         }
         else if ($mark === ')' || $mark === ']') {
            if ($depth > 0) {
               $depth--;
            }
            // ? A control statement's parenthesized header closed
            if ($mark === ')' && $depth === 0 && $header) {
               $opened = true;
            }
         }
         else if ($kind === T_MATCH) {
            $match = true;
         }
         else if ($mark === '?') {
            $ternaries++;
         }
         else if ($mark === '?:' && $ternaries > 0) {
            $ternaries--;
         }
         else if ($kind === T_ELSE) {
            // ? `else` closes its header at once (`else if` starts a header)
            $opened = true;
         }
         else if ($mark === '{$') {
            $stack[] = false;
         }
         else if ($mark === '$}') {
            array_pop($stack);
         }
         else if ($mark === ';') {
            $statement = null;
         }
         else if ($mark === ':') {
            // ? A `case` label runs its comparison: its rows hold a header
            if ($statement !== null && $first === T_CASE) {
               foreach ($members[$statement] as $row => $_) {
                  $headers[$row][$statement] = true;
               }
            }
            $statement = null;
         }
         else if ($mark === '{') {
            $stack[] = [[$statement, $depth, $first, $count, $header, $ternaries], $arms];
            $arms = $match;
            $match = false;
            $statement = null;
         }
         else if ($mark === '}') {
            $frame = array_pop($stack);
            $resume = null;
            if (is_array($frame)) {
               [$resume, $arms] = $frame;
               if ($resume[0] === null) {
                  $resume = null;
               }
            }
            $statement = null;
         }

         $previous = $mark;
      }

      // :
      return [$continued, $members, $headers];
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
