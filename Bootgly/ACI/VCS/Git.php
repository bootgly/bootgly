<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\VCS;


use const PATH_SEPARATOR;
use function count;
use function explode;
use function fclose;
use function fgets;
use function getenv;
use function is_executable;
use function is_file;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function realpath;
use function rtrim;
use function str_replace;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;
use Closure;
use RuntimeException;


/**
 * One git working tree, driven through the `git` binary.
 *
 * Every call is one child process, started without a shell: the arguments go
 * to `proc_open` as a list, so a tag name, a path or a URL is never parsed
 * as shell syntax. The child inherits the caller's environment (credentials,
 * SSH agent, config) minus the `GIT_DIR` family — a hook or a nested tool
 * could otherwise point every command at a repository other than `$path`.
 * Both streams come back through one pipe, in order, and the last run's
 * combined output and exit status stay readable on the instance.
 */
final class Git
{
   /**
    * The environment that would point a command at a repository other than `$path`.
    *
    * Only the LOCATION is scrubbed. Configuration — `GIT_CONFIG_*`, the
    * global and system files, `GIT_EXEC_PATH` — is the user's own, the same
    * channel as their `.gitconfig` (credential helpers, `safe.directory`, a CI
    * `GIT_CONFIG_GLOBAL`), and it is honoured as such: a same-user environment
    * is no boundary, and breaking a legitimate setup to pretend it is one
    * would cost real installs their fetch.
    */
   private const array REDIRECTIONS = [
      'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY',
      'GIT_ALTERNATE_OBJECT_DIRECTORIES', 'GIT_COMMON_DIR', 'GIT_NAMESPACE', 'GIT_PREFIX',
   ];
   /** The network deadline every transfer runs under: under 1 KB/s for 20 s is a dead remote. */
   public const array DEADLINE = ['-c', 'http.lowSpeedLimit=1000', '-c', 'http.lowSpeedTime=20'];

   // * Config
   /** The working tree — absolute, no trailing separator. */
   public private(set) string $path;
   /** The `git` binary — absolute. */
   public private(set) string $binary;

   // * Metadata
   /** Combined stdout + stderr of the last `execute()`. */
   public private(set) string $output = '';
   /** Exit status of the last `execute()`. */
   public private(set) int $status = 0;


   /**
    * Bind a working tree.
    *
    * @param string $path The working tree.
    * @param null|string $binary The `git` binary; looked up on `PATH` when omitted.
    *
    * @throws RuntimeException When no `git` binary can be found.
    */
   public function __construct (string $path, null|string $binary = null)
   {
      // * Config
      $this->path = $path === '/' ? '/' : rtrim($path, '/');
      $this->binary = $binary ?? self::locate()
         ?? throw new RuntimeException('git was not found on PATH');
   }

   /**
    * Find the `git` binary on `PATH` — absolute entries only.
    *
    * @return null|string The absolute path, or null when no entry has an executable `git`.
    */
   public static function locate (): null|string
   {
      $PATH = getenv('PATH');
      $PATH = $PATH === false || $PATH === '' ? '/usr/local/bin:/usr/bin:/bin' : $PATH;

      // @@
      foreach (explode(PATH_SEPARATOR, $PATH) as $directory) {
         // ? An empty or relative entry means "the current directory" — never that
         if (str_starts_with($directory, '/') === false) {
            continue;
         }

         $candidate = rtrim($directory, '/') . '/git';
         if (is_file($candidate) === true && is_executable($candidate) === true) {
            return $candidate;
         }
      }

      // :
      return null;
   }

   /**
    * Run one git command in the working tree.
    *
    * @param list<string> $arguments The command line after `git`.
    * @param null|Closure $Callback Receives each output line as it arrives, without its break.
    *
    * @return int The exit status — `126` when the process could not start.
    */
   public function execute (array $arguments, null|Closure $Callback = null): int
   {
      // ! The caller's environment, minus what would redirect the command
      $environment = getenv();
      foreach (self::REDIRECTIONS as $variable) {
         unset($environment[$variable]);
      }
      // ! Messages in one language, and no prompt a headless run would hang on —
      //   neither the terminal's nor an askpass helper's (a GUI dialog nobody is
      //   there to answer): the helpers leave the environment — an empty value
      //   would not reach the child at all — and ssh is told never to ask
      $environment['LC_ALL'] = 'C';
      $environment['GIT_TERMINAL_PROMPT'] = '0';
      unset($environment['GIT_ASKPASS'], $environment['SSH_ASKPASS']);
      $environment['SSH_ASKPASS_REQUIRE'] = 'never';

      $this->output = '';

      // @ One pipe for both streams — nothing to drain out of order
      $pipes = [];
      $process = @proc_open(
         [$this->binary, ...$arguments],
         // @phpstan-ignore-next-line — a `redirect` spec takes the target descriptor as an int
         [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['redirect', 1],
         ],
         $pipes,
         $this->path,
         $environment
      );
      // ?
      if (is_resource($process) === false) {
         return $this->status = 126;
      }

      // @@ Line by line, as it arrives — a carriage return is progress
      //   repainting, which has no place in a captured or nested output
      while (($line = fgets($pipes[1])) !== false) {
         $this->output .= $line;

         if ($Callback !== null) {
            $Callback(str_replace("\r", '', rtrim($line, "\n")));
         }
      }

      fclose($pipes[1]);

      // :
      return $this->status = proc_close($process);
   }

   /**
    * Run one git command and read its output — null when it fails.
    *
    * @param list<string> $arguments The command line after `git`.
    *
    * @return null|string The trimmed output on exit status 0, null otherwise.
    */
   public function query (array $arguments): null|string
   {
      // ?:
      if ($this->execute($arguments) !== 0) {
         return null;
      }

      // :
      return trim($this->output);
   }

   /**
    * Check that `$path` is the top of a git working tree.
    *
    * A subdirectory of a repository, a bare repository or a plain directory
    * all fail: the commands this class runs assume the tree's own root.
    */
   public function check (): bool
   {
      $top = $this->query(['rev-parse', '--show-toplevel']);

      // ?:
      if ($top === null || $top === '') {
         return false;
      }

      // :
      return realpath($top) === realpath($this->path);
   }

   /**
    * Resolve a reference to the commit it names.
    *
    * @param string $reference A commit, tag, branch or expression (`HEAD`, `refs/tags/v1.0.0`, `HEAD~2`).
    *
    * @return null|string The full commit hash, or null when the reference does not resolve to a commit.
    */
   public function resolve (string $reference): null|string
   {
      $commit = $this->query(['rev-parse', '--verify', '--quiet', "{$reference}^{commit}"]);

      // ?:
      if ($commit === null || preg_match('/^[0-9a-f]{40,64}$/', $commit) !== 1) {
         return null;
      }

      // :
      return $commit;
   }

   /**
    * Describe a commit by the nearest tag reachable from it.
    *
    * @param string $commit The commit to describe (`HEAD` by default).
    * @param list<string> $patterns Globs the tag must match (`--match`); any tag when empty.
    *
    * @return null|array{tag:string,distance:int} The tag and how many commits `$commit`
    *                                             is past it; null when no tag is reachable.
    */
   public function describe (string $commit = 'HEAD', array $patterns = []): null|array
   {
      $arguments = ['describe', '--tags', '--long'];
      foreach ($patterns as $pattern) {
         $arguments[] = '--match';
         $arguments[] = $pattern;
      }
      $arguments[] = $commit;

      $described = $this->query($arguments);

      // ?: `<tag>-<distance>-g<hash>`, split from the right — a tag may carry hyphens
      if ($described === null || preg_match('/^(.+)-(\d+)-g[0-9a-f]+$/', $described, $matches) !== 1) {
         return null;
      }

      // :
      return ['tag' => $matches[1], 'distance' => (int) $matches[2]];
   }

   /**
    * Inspect the working tree for changes: tracked edits, staged changes,
    * untracked files — submodules excluded, they are inspected on their own.
    *
    * @return null|array<string,string> Path => the two-letter porcelain status (`XY`),
    *                                   `??` for an untracked file; null when git could
    *                                   not report (never "clean").
    */
   public function inspect (): null|array
   {
      $status = $this->execute([
         'status', '--porcelain=v1', '-z', '--untracked-files=normal', '--ignore-submodules=all',
      ]);
      // ?
      if ($status !== 0) {
         return null;
      }

      // @@ `-z`: entries end in NUL, and a rename carries its origin as one more NUL-terminated field
      $changes = [];
      $entries = explode("\0", $this->output);
      for ($index = 0, $count = count($entries); $index < $count; $index++) {
         $entry = $entries[$index];
         if (strlen($entry) < 4) {
            continue;
         }

         $code = substr($entry, 0, 2);
         $changes[substr($entry, 3)] = $code;

         if ($code[0] === 'R' || $code[0] === 'C') {
            $index++;
         }
      }

      // :
      return $changes;
   }

   /**
    * Fetch from a remote — by default only its tags, never recursing into submodules.
    *
    * @param string $remote The remote name or URL.
    * @param list<string> $refspecs What to fetch; every tag when empty.
    * @param null|Closure $Callback Receives each output line as it arrives.
    *
    * @return int The exit status.
    */
   public function fetch (string $remote, array $refspecs = [], null|Closure $Callback = null): int
   {
      // ! An explicit refspec: no branch of the remote is ever pulled in as a
      //   side effect. Forced: the remote is the source of truth for a tag, so a
      //   release retagged upstream replaces the stale local one instead of
      //   failing the whole fetch as "would clobber existing tag"
      if ($refspecs === []) {
         $refspecs = ['+refs/tags/*:refs/tags/*'];
      }

      // ! A deadline: a blackholed remote must fail, not hang a headless run forever
      return $this->execute([...self::DEADLINE, 'fetch', '--no-recurse-submodules', $remote, ...$refspecs], $Callback);
   }

   /**
    * Check out a reference — a tag by its full `refs/tags/` name, so a
    * branch sharing the name can never be picked instead.
    *
    * @param string $reference The reference to check out.
    * @param null|Closure $Callback Receives each output line as it arrives.
    *
    * @return int The exit status.
    */
   public function checkout (string $reference, null|Closure $Callback = null): int
   {
      return $this->execute(
         ['-c', 'advice.detachedHead=false', 'checkout', '--quiet', $reference],
         $Callback
      );
   }
}
