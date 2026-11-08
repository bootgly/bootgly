<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage;


use function explode;
use function fclose;
use function getenv;
use function is_dir;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function realpath;
use function rtrim;
use function str_contains;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function stream_get_contents;
use function stripcslashes;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function trim;
use InvalidArgumentException;
use RuntimeException;


/**
 * Changed lines of a git range — the lines a coverage diff measures.
 *
 * Runs `git diff --unified=0` for the range (no shell: the range is one
 * argument) and keeps, per PHP file, the line numbers the range added or
 * modified on its new side. Deleted files and pure deletions contribute
 * nothing.
 *
 * Coverage measures the checked-out work tree, so the range must end at
 * it (`origin/main...HEAD` on a clean checkout of HEAD): a range whose new
 * side is another commit names lines and files the run does not hold.
 *
 *   $Changes = new Changes('origin/main...HEAD', BOOTGLY_WORKING_DIR);
 *   $lines = $Changes->fetch(); // [canonical_file => [line => true]]
 */
final class Changes
{
   // * Config
   /**
    * Git range (or a single revision) handed to `git diff`, e.g.
    * `origin/main...HEAD`, `HEAD~3..HEAD` or `main`.
    */
   public private(set) string $range;
   /**
    * Directory inside the git work tree the range is read from.
    */
   public private(set) string $directory;

   // * Metadata
   /**
    * Changed lines of the last `fetch()`, keyed by canonical file path.
    *
    * @var array<string,array<int,true>>
    */
   public private(set) array $lines = [];


   /**
    * Describe a git range to read changed lines from.
    *
    * @throws InvalidArgumentException When the range is empty, starts with
    *    `-` (it would be read as a git option) or holds a control character.
    */
   public function __construct (string $range, string $directory)
   {
      // ?
      if ($range === '' || str_starts_with($range, '-')) {
         throw new InvalidArgumentException(
            "Coverage diff range must be a git revision range, got '{$range}'."
         );
      }
      if (preg_match('/[\x00-\x20\x7F]/', $range) === 1) {
         throw new InvalidArgumentException(
            'Coverage diff range must not contain whitespace or control characters.'
         );
      }

      // * Config
      $this->range = $range;
      $this->directory = $directory;
   }

   /**
    * Read the range's changed lines from git.
    *
    * @return array<string,array<int,true>> `[canonical_file => [line => true]]`
    *
    * @throws RuntimeException When git is unavailable, the directory is not
    *    inside a work tree, git rejects the range, the range spans a
    *    merge (a combined diff), or it changes a PHP file the work tree
    *    does not hold.
    */
   public function fetch (): array
   {
      // ?
      if (is_dir($this->directory) === false) {
         throw new RuntimeException("Coverage diff directory not found: {$this->directory}");
      }

      // ! The work-tree root: `git diff` prints paths relative to it
      $root = rtrim($this->execute(['rev-parse', '--show-toplevel']), "\r\n");

      // ? A range over a merge (`<merge>^!`) asks for a combined diff, which
      //   leaves out files taken cleanly from one side — refuse it instead
      //   of measuring less than the merge changed
      if (str_contains($this->range, '...') === false) {
         $revisions = $this->execute(['rev-parse', '--revs-only', $this->range, '--']);
         if (substr_count("\n{$revisions}", "\n^") > 1) {
            throw new RuntimeException(
               "Coverage diff: '{$this->range}' spans a merge (a combined diff); "
               . 'use the first-parent range instead, e.g. <merge>^1..<merge>.'
            );
         }
      }

      // @
      $diff = $this->execute([
         '-c', 'core.quotePath=false',
         // ? Paths relative to the work-tree root, whatever the user config
         '-c', 'diff.relative=false',
         // ? Every row of a hunk is read, so blank context rows stay ' '
         '-c', 'diff.suppressBlankEmpty=false',
         // ? Renames only, never copies: a copy would print just the lines
         //   it differs by, not the whole new file
         '-c', 'diff.renames=true',
         'diff',
         '--unified=0',
         // ? Hunks even for content git would call binary (a NUL byte, a
         //   `-diff`/`binary` attribute): a changed source never goes unseen
         '--text',
         '--no-color',
         '--no-ext-diff',
         '--no-textconv',
         '--src-prefix=a/',
         '--dst-prefix=b/',
         $this->range,
         '--',
         // ? PHP sources only (`.php` in any case), from the root whatever
         //   the directory: with `--text`, an asset would otherwise come out
         //   whole in the diff
         ':(top,glob,icase)**/*.php',
      ]);

      // * Metadata
      $this->lines = self::parse($diff, $root);

      // :
      return $this->lines;
   }

   /**
    * Parse `git diff --unified=0` output into changed new-side lines.
    *
    * Paths are resolved against `$root` (`realpath()`), so the keys match a
    * coverage map's canonical paths. A missing non-PHP file is skipped; a
    * missing PHP file throws, since its changed lines would go unmeasured.
    *
    * @return array<string,array<int,true>> `[canonical_file => [line => true]]`
    *
    * @throws RuntimeException On a combined (merge) diff, or a changed PHP
    *    file not found under `$root`.
    */
   public static function parse (string $diff, string $root): array
   {
      // !
      $root = rtrim(str_replace('\\', '/', $root), '/');
      $lines = [];
      $file = null;
      $header = false;
      // New-side number of the next hunk row
      $line = 0;

      // @@
      foreach (explode("\n", str_replace("\r\n", "\n", $diff)) as $row) {
         // # A file header — the only rows not prefixed inside a hunk
         if (str_starts_with($row, 'diff --git ')) {
            $header = true;
            $file = null;
            continue;
         }
         // ? A combined diff (merge) has another row grammar
         if (str_starts_with($row, 'diff --cc ') || str_starts_with($row, 'diff --combined ')) {
            throw new RuntimeException(
               'Coverage diff: a combined (merge) diff cannot be measured; use a first-parent range.'
            );
         }

         // # A hunk: `@@ -a[,b] +c[,d] @@` — only its start is trusted; the
         //   rows below say which lines changed (context rows may appear
         //   whatever --unified asked for: GIT_DIFF_OPTS, inter-hunk context)
         if (preg_match('/^@@ -\d+(?:,\d+)? \+(\d+)(?:,\d+)? @@/', $row, $match) === 1) {
            $header = false;
            $line = (int) $match[1];
            continue;
         }

         if ($header) {
            if (str_starts_with($row, '+++ ') === false) {
               continue;
            }

            $path = substr($row, 4);

            // ? Quoted path (git escapes unusual bytes C-style)
            if (strlen($path) >= 2 && $path[0] === '"' && $path[strlen($path) - 1] === '"') {
               $path = stripcslashes(substr($path, 1, -1));
            }
            // ? Unquoted path with a space: git appends one TAB
            else if (str_ends_with($path, "\t")) {
               $path = substr($path, 0, -1);
            }

            $file = null;
            if (str_starts_with($path, 'b/')) {
               $relative = substr($path, 2);
               $resolved = realpath("{$root}/{$relative}");
               if ($resolved !== false) {
                  $file = str_replace('\\', '/', $resolved);
               }
               // ? A changed PHP source the work tree does not hold: the
               //   range does not end at it (or git read another root) —
               //   fail instead of measuring less than the range changed
               else if (str_ends_with(strtolower($relative), '.php')) {
                  throw new RuntimeException(
                     "Coverage diff: '{$relative}' is changed by the range but not found under {$root}; "
                     . 'the range must end at the checked-out work tree.'
                  );
               }
            }

            continue;
         }

         // # A hunk row — an empty row is a blank context line
         //   (`diff.suppressBlankEmpty`) or the end of the output
         if ($row === '') {
            $line++;
            continue;
         }

         // ? `-` (removed) and `\` (no newline at end of file) take no
         //   new-side line
         if ($row[0] === '+') {
            if ($file !== null) {
               $lines[$file][$line] = true;
            }
            $line++;
         }
         else if ($row[0] === ' ') {
            $line++;
         }
      }

      // :
      return $lines;
   }

   /**
    * Run one git command in the configured directory and return its stdout.
    *
    * @param array<int,string> $arguments
    *
    * @throws RuntimeException When git cannot run or exits non-zero.
    */
   private function execute (array $arguments): string
   {
      // !
      $command = ['git', '-C', $this->directory, ...$arguments];
      $descriptors = [
         0 => ['pipe', 'r'],
         1 => ['pipe', 'w'],
         2 => ['pipe', 'w'],
      ];

      // @
      // ! Environment overrides that rewrite the diff are dropped — the
      //   pathspec ones too: a literal pathspec would match no file at all;
      //   and the repository-location ones: `-C` names the repository
      $environment = getenv();
      unset(
         $environment['GIT_COMMON_DIR'],
         $environment['GIT_DIR'],
         $environment['GIT_INDEX_FILE'],
         $environment['GIT_WORK_TREE'],
         $environment['GIT_DIFF_OPTS'],
         $environment['GIT_EXTERNAL_DIFF'],
         $environment['GIT_GLOB_PATHSPECS'],
         $environment['GIT_ICASE_PATHSPECS'],
         $environment['GIT_LITERAL_PATHSPECS'],
         $environment['GIT_NOGLOB_PATHSPECS']
      );

      $process = proc_open($command, $descriptors, $pipes, null, $environment);
      if (is_resource($process) === false) {
         throw new RuntimeException('Coverage diff could not run git.');
      }

      /** @var array<int,resource> $pipes */
      fclose($pipes[0]);
      $output = (string) stream_get_contents($pipes[1]);
      $error = (string) stream_get_contents($pipes[2]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $status = proc_close($process);

      // ?
      if ($status !== 0) {
         $reason = trim($error) !== '' ? trim($error) : "exit status {$status}";
         throw new RuntimeException("Coverage diff: git failed for '{$this->range}': {$reason}");
      }

      // :
      return $output;
   }
}
