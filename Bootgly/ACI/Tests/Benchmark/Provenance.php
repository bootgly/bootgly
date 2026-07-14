<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Benchmark;


use const SORT_STRING;
use function array_keys;
use function array_pop;
use function count;
use function dirname;
use function explode;
use function fclose;
use function feof;
use function fopen;
use function fread;
use function getenv;
use function hash;
use function hash_final;
use function hash_init;
use function hash_update;
use function hash_update_stream;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function ksort;
use function lstat;
use function pack;
use function preg_match;
use function proc_close;
use function proc_open;
use function readlink;
use function realpath;
use function rtrim;
use function sort;
use function str_starts_with;
use function stream_get_contents;
use function strlen;
use function strtolower;
use function trim;


/**
 * Collect source-tree provenance for reproducible benchmark artifacts.
 *
 * Git is authoritative when the repository metadata is available. Environment
 * overrides are fallbacks for packaged sources (notably Docker images whose
 * build context intentionally excludes `.git`).
 */
final class Provenance
{
   private const string SOURCE_IDENTITY_VERSION = 'raw-delta-manifest-v1';
   private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

   /**
    * Collect framework and benchmark-suite provenance as `.marks` config keys.
    *
    * @return array<string,string>
    */
   public static function collect (string $frameworkPath, string $benchmarksPath): array
   {
      return [
         'source-identity-version' => self::SOURCE_IDENTITY_VERSION,
         ...self::inspect(
            prefix: 'framework',
            path: $frameworkPath,
            ascend: false,
            SHAFallback: getenv('BOOTGLY_FRAMEWORK_SHA'),
            dirtyFallback: getenv('BOOTGLY_FRAMEWORK_DIRTY'),
            trackedFallback: getenv('BOOTGLY_FRAMEWORK_TRACKED_DIFF_SHA256'),
            untrackedFallback: getenv('BOOTGLY_FRAMEWORK_UNTRACKED_MANIFEST_SHA256'),
         ),
         ...self::inspect(
            prefix: 'benchmarks',
            path: $benchmarksPath,
            SHAFallback: getenv('BOOTGLY_BENCHMARKS_SHA'),
            dirtyFallback: getenv('BOOTGLY_BENCHMARKS_DIRTY'),
            trackedFallback: getenv('BOOTGLY_BENCHMARKS_TRACKED_DIFF_SHA256'),
            untrackedFallback: getenv('BOOTGLY_BENCHMARKS_UNTRACKED_MANIFEST_SHA256'),
         ),
      ];
   }

   /**
    * Validate one complete two-repository identity before a benchmark result is
    * accepted. Unknown, partial, or contradictory tuples are not attributable.
    *
    * @param array<string,mixed> $metadata
    */
   public static function validate (array $metadata): bool
   {
      if (($metadata['source-identity-version'] ?? null) !== self::SOURCE_IDENTITY_VERSION) {
         return false;
      }

      foreach (['framework', 'benchmarks'] as $prefix) {
         $SHAValue = $metadata["{$prefix}-sha"] ?? null;
         $dirtyValue = $metadata["{$prefix}-dirty"] ?? null;
         $trackedValue = $metadata["{$prefix}-tracked-diff-sha256"] ?? null;
         $untrackedValue = $metadata["{$prefix}-untracked-manifest-sha256"] ?? null;

         $SHA = is_string($SHAValue) ? self::normalize($SHAValue, 'sha') : null;
         $dirty = is_string($dirtyValue) ? self::normalize($dirtyValue, 'dirty') : null;
         $tracked = is_string($trackedValue) ? self::normalize($trackedValue, 'sha256') : null;
         $untracked = is_string($untrackedValue) ? self::normalize($untrackedValue, 'sha256') : null;

         if (
            $SHA === null
            || $dirty === null
            || $tracked === null
            || $untracked === null
            || (
               $dirty === 'false'
               && ($tracked !== self::EMPTY_SHA256 || $untracked !== self::EMPTY_SHA256)
            )
         ) {
            return false;
         }
      }

      return true;
   }

   /**
    * Confirm that two complete round-boundary captures identify the same source.
    *
    * @param array<string,mixed> $before
    * @param array<string,mixed> $after
    */
   public static function confirm (array $before, array $after): bool
   {
      return self::validate($before) && self::validate($after) && $before === $after;
   }

   /**
    * Inspect one repository.
    *
    * Dirty reports a staged index delta, physical tracked-byte/executable-mode
    * delta, or non-ignored untracked input. When source is stable, the HEAD SHA
    * plus both SHA-256 values identify those physical source inputs without
    * serializing patches, paths, or file content into benchmark output. Git
    * clean/smudge and EOL filters are bypassed. Ignored files, dependencies,
    * environment, and external runtime state remain outside this identity.
    *
    * A submodule, unmerged index, skip-worktree/assume-unchanged entry, special
    * untracked file, or detected source mutation during capture fails closed:
    * both fingerprints and dirty state become `unknown`. Invalid or unavailable
    * fallback values are also reported explicitly as `unknown`; they can never
    * inject arbitrary text into a `.marks` header.
    *
    * @param string|false|null $SHAFallback
    * @param string|false|null $dirtyFallback
    * @param string|false|null $trackedFallback
    * @param string|false|null $untrackedFallback
    * @param bool $ascend Allow a benchmark case directory to resolve its suite root.
    *
    * @return array<string,string>
    */
   public static function inspect (
      string $prefix,
      string $path,
      string|false|null $SHAFallback = null,
      string|false|null $dirtyFallback = null,
      string|false|null $trackedFallback = null,
      string|false|null $untrackedFallback = null,
      bool $ascend = true,
   ): array
   {
      $SHA = null;
      $dirty = null;
      $tracked = null;
      $untracked = null;
      $path = realpath($path) ?: $path;
      $local = false;

      if (is_dir($path)) {
         $repository = self::locate($path, $ascend);
         $local = $repository !== null;
         // ! inspect() may receive one benchmark-case subdirectory. Resolve the
         //   repository root so dirt elsewhere in the suite is not omitted.
         $root = self::execute($path, ['rev-parse', '--show-toplevel']);
         $root = $root === null ? null : rtrim($root, "\r\n");
         $root = $root === null ? null : (realpath($root) ?: $root);

         if (
            ($repository !== null && $root !== $repository)
            || ($ascend === false && $root !== $path)
         ) {
            $root = null;
         }

         if ($root !== null && is_dir($root)) {
            $local = true;
            $head = self::execute($root, ['rev-parse', '--verify', 'HEAD']);
            $SHA = self::normalize($head, 'sha');

            if ($SHA !== null) {
               $initialSHA = $SHA;
               // ! Two identical raw captures reduce the risk of publishing a
               //   fingerprint assembled while source bytes were changing.
               $first = self::fingerprint($root, $SHA);
               $second = self::fingerprint($root, $SHA);
               $final = self::normalize(
                  self::execute($root, ['rev-parse', '--verify', 'HEAD']),
                  'sha'
               );

               if ($final !== null) {
                  $SHA = $final;
               }

               if (
                  $first !== null && $first === $second
                  && $initialSHA === $final
               ) {
                  [$tracked, $untracked, $dirty] = $first;
               }
            }
         }
      }

      // ! A local Git checkout is authoritative. Never hide an unstable or
      //   unsupported live state behind packaged-image fallback metadata.
      if ($local === false) {
         $SHA ??= self::normalize($SHAFallback, 'sha');
         $dirty ??= self::normalize($dirtyFallback, 'dirty');
         $tracked ??= self::normalize($trackedFallback, 'sha256');
         $untracked ??= self::normalize($untrackedFallback, 'sha256');

         // ! Packaged metadata is one identity tuple. Never combine a valid
         //   fragment with missing companions.
         if ($SHA === null || $dirty === null || $tracked === null || $untracked === null) {
            $tracked = null;
            $untracked = null;
         }
      }

      return [
         "{$prefix}-sha" => $SHA ?? 'unknown',
         "{$prefix}-dirty" => $dirty ?? 'unknown',
         "{$prefix}-tracked-diff-sha256" => $tracked ?? 'unknown',
         "{$prefix}-untracked-manifest-sha256" => $untracked ?? 'unknown',
      ];
   }

   /** Locate a Git marker without relying on the Git executable. */
   private static function locate (string $path, bool $ascend): ?string
   {
      $candidate = $path;
      while (true) {
         if (@lstat("{$candidate}/.git") !== false) {
            return $candidate;
         }
         if ($ascend === false) {
            return null;
         }

         $parent = dirname($candidate);
         if ($parent === $candidate) {
            return null;
         }
         $candidate = $parent;
      }
   }

   /**
    * Fingerprint one stable Git-visible working tree without retaining raw
    * patch, path, or source bytes.
    *
    * @return null|array{0:string,1:string,2:string}
    */
   private static function fingerprint (string $path, string $SHA): ?array
   {
      if (self::normalize(self::execute($path, ['rev-parse', '--verify', 'HEAD']), 'sha') !== $SHA) {
         return null;
      }

      // ? Unmerged entries do not describe one executable final tree.
      $unmerged = self::execute($path, ['ls-files', '--unmerged', '-z']);
      if ($unmerged === null || $unmerged !== '') {
         return null;
      }

      // ? Hidden index promises and submodules fail closed because neither can
      //   be represented as one exact raw worktree delta by this format.
      if (self::check($path) === false) {
         return null;
      }

      $comparison = self::compare($path, $SHA);
      if ($comparison === null) {
         return null;
      }
      [$tracked, $staged] = $comparison;

      $paths = self::execute($path, [
         'ls-files',
         '--others',
         '--exclude-standard',
         '--full-name',
         '-z',
      ]);
      if ($paths === null) {
         return null;
      }

      $untracked = self::catalog($path, $paths);
      if ($untracked === null) {
         return null;
      }

      $dirty = $staged || $tracked !== self::EMPTY_SHA256 || $untracked !== self::EMPTY_SHA256
         ? 'true'
         : 'false';

      return self::normalize(self::execute($path, ['rev-parse', '--verify', 'HEAD']), 'sha') === $SHA
         ? [$tracked, $untracked, $dirty]
         : null;
   }

   /** Reject hidden index promises and unsupported submodule worktrees. */
   private static function check (string $path): bool
   {
      $flags = self::execute($path, ['ls-files', '-v', '-z']);
      if ($flags === null) {
         return false;
      }
      foreach (explode("\0", $flags) as $entry) {
         if ($entry === '') {
            continue;
         }

         $flag = $entry[0];
         if ($flag === 'S' || ($flag >= 'a' && $flag <= 'z')) {
            return false;
         }
      }

      $staged = self::execute($path, ['ls-files', '--stage', '-z']);
      if ($staged === null) {
         return false;
      }

      foreach (explode("\0", $staged) as $entry) {
         if ($entry !== '' && str_starts_with($entry, '160000 ')) {
            return false;
         }
      }

      return true;
   }

   /**
    * Hash a sorted delta of physical tracked bytes and executable modes against
    * the HEAD tree. Blob object IDs are calculated directly, without Git clean,
    * text, EOL, or external-diff filters.
    *
    * @return null|array{0:string,1:bool}
    */
   private static function compare (string $path, string $SHA): ?array
   {
      $format = strlen($SHA) === 40 ? 'sha1' : 'sha256';

      $tree = self::execute($path, ['ls-tree', '-r', '-z', '--full-tree', 'HEAD']);
      $staged = self::execute($path, ['ls-files', '--stage', '-z']);
      if ($tree === null || $staged === null) {
         return null;
      }

      /** @var array<string,array{mode:string,OID:string}> $head */
      $head = [];
      foreach (explode("\0", $tree) as $entry) {
         if ($entry === '') {
            continue;
         }
         if (
            preg_match(
               '/\A(100644|100755|120000|160000) (?:blob|commit) ([0-9a-f]{40}|[0-9a-f]{64})\t(.*)\z/sD',
               $entry,
               $matches
            ) !== 1
         ) {
            return null;
         }

         $head[$matches[3]] = ['mode' => $matches[1], 'OID' => $matches[2]];
      }

      /** @var array<string,array{mode:string,OID:string}> $index */
      $index = [];
      foreach (explode("\0", $staged) as $entry) {
         if ($entry === '') {
            continue;
         }
         if (
            preg_match(
               '/\A(100644|100755|120000|160000) ([0-9a-f]{40}|[0-9a-f]{64}) 0\t(.*)\z/sD',
               $entry,
               $matches
            ) !== 1
         ) {
            return null;
         }

         $index[$matches[3]] = ['mode' => $matches[1], 'OID' => $matches[2]];
      }

      $paths = [];
      foreach ($head as $relative => $_) {
         $paths[$relative] = true;
      }
      foreach ($index as $relative => $_) {
         $paths[$relative] = true;
      }
      ksort($head, SORT_STRING);
      ksort($index, SORT_STRING);
      $staged = $head !== $index;
      $paths = array_keys($paths);
      sort($paths, SORT_STRING);

      $Context = hash_init('sha256');
      foreach ($paths as $relative) {
         if (self::contain($path, $relative) === false) {
            return null;
         }

         $base = $head[$relative] ?? null;
         $file = "{$path}/{$relative}";
         $before = @lstat($file);
         $OID = null;

         if ($before === false) {
            if ($base === null) {
               continue;
            }

            $mode = '000000';
            $size = 0;
            $content = hash('sha256', '', true);
         }
         else if (is_link($file)) {
            $target = @readlink($file);
            if ($target === false) {
               return null;
            }

            $mode = '120000';
            $size = strlen($target);
            $content = hash('sha256', $target, true);
            $OID = hash($format, "blob {$size}\0{$target}");
         }
         else if (is_file($file)) {
            $stream = @fopen($file, 'rb');
            if ($stream === false) {
               return null;
            }

            $mode = ($before['mode'] & 0111) !== 0 ? '100755' : '100644';
            $size = $before['size'];
            $FileContext = hash_init('sha256');
            $ObjectContext = hash_init($format);
            hash_update($ObjectContext, "blob {$size}\0");
            $read = 0;

            while (feof($stream) === false) {
               $chunk = fread($stream, 1048576);
               if ($chunk === false || ($chunk === '' && feof($stream) === false)) {
                  fclose($stream);
                  return null;
               }

               $read += strlen($chunk);
               hash_update($FileContext, $chunk);
               hash_update($ObjectContext, $chunk);
            }
            fclose($stream);

            if ($read !== $size) {
               return null;
            }

            $content = hash_final($FileContext, true);
            $OID = hash_final($ObjectContext);
         }
         else if (is_dir($file)) {
            // ! Raw recursive submodule identity is not implemented. Returning
            //   unknown is safer than treating a matching gitlink as exact bytes.
            if (($index[$relative]['mode'] ?? $base['mode'] ?? null) === '160000') {
               return null;
            }

            if ($base === null) {
               continue;
            }
            $mode = '000000';
            $size = 0;
            $content = hash('sha256', '', true);
         }
         else {
            return null;
         }

         $after = @lstat($file);
         if (
            self::contain($path, $relative) === false // @phpstan-ignore identical.alwaysFalse (TOCTOU recheck)
            || ($before === false && $after !== false)
            || (
               $before !== false
               && (
                  $after === false
                  || $before['dev'] !== $after['dev']
                  || $before['ino'] !== $after['ino']
                  || $before['mode'] !== $after['mode']
                  || $before['size'] !== $after['size']
                  || $before['mtime'] !== $after['mtime']
                  || $before['ctime'] !== $after['ctime']
               )
            )
         ) {
            return null;
         }

         if ($base !== null && $base['mode'] === $mode && $base['OID'] === $OID) {
            continue;
         }

         $size = (string) $size;
         hash_update($Context, "\x01");
         hash_update($Context, pack('N', strlen($relative)));
         hash_update($Context, $relative);
         hash_update($Context, $mode);
         hash_update($Context, pack('N', strlen($size)));
         hash_update($Context, $size);
         hash_update($Context, $content);
      }

      return [hash_final($Context), $staged];
   }

   /** Reject tracked paths whose existing parent chain is not physical directories. */
   private static function contain (string $path, string $relative): bool
   {
      $parts = explode('/', $relative);
      array_pop($parts);
      $parent = $path;

      foreach ($parts as $part) {
         if ($part === '' || $part === '.' || $part === '..') {
            return false;
         }

         $parent .= "/{$part}";
         $state = @lstat($parent);
         if ($state === false) {
            return true;
         }
         if (($state['mode'] & 0170000) !== 0040000) {
            return false;
         }
      }

      return true;
   }

   /**
    * Hash a sorted, length-delimited manifest of non-ignored untracked inputs.
    * Regular content is streamed; symlinks hash their target text and are never
    * followed. Git-canonical modes make host-only permission bits irrelevant.
    */
   private static function catalog (string $path, string $listed): ?string
   {
      if ($listed === '') {
         return self::EMPTY_SHA256;
      }

      $paths = explode("\0", $listed);
      if ($paths[count($paths) - 1] === '') {
         array_pop($paths);
      }
      sort($paths, SORT_STRING);

      $Context = hash_init('sha256');
      foreach ($paths as $relative) {
         if ($relative === '') {
            return null;
         }

         $file = "{$path}/{$relative}";
         $before = @lstat($file);
         if ($before === false) {
            return null;
         }

         if (is_link($file)) {
            $target = @readlink($file);
            if ($target === false) {
               return null;
            }

            $mode = '120000';
            $size = strlen($target);
            $content = hash('sha256', $target, true);
         }
         else if (is_file($file)) {
            $stream = @fopen($file, 'rb');
            if ($stream === false) {
               return null;
            }

            $FileContext = hash_init('sha256');
            $read = hash_update_stream($FileContext, $stream);
            fclose($stream);
            if ($read !== $before['size']) {
               return null;
            }

            $mode = ($before['mode'] & 0111) !== 0 ? '100755' : '100644';
            $size = $before['size'];
            $content = hash_final($FileContext, true);
         }
         else {
            // ! FIFOs, sockets, devices, and embedded repository directories
            //   cannot be read into a stable source manifest without side effects.
            return null;
         }

         $after = @lstat($file);
         if (
            $after === false
            || $before['dev'] !== $after['dev']
            || $before['ino'] !== $after['ino']
            || $before['mode'] !== $after['mode']
            || $before['size'] !== $after['size']
            || $before['mtime'] !== $after['mtime']
            || $before['ctime'] !== $after['ctime']
         ) {
            return null;
         }

         $size = (string) $size;
         // ! Record v1: version, path length+bytes, canonical mode, decimal
         //   size length+bytes, and raw 32-byte SHA-256 content digest.
         hash_update($Context, "\x01");
         hash_update($Context, pack('N', strlen($relative)));
         hash_update($Context, $relative);
         hash_update($Context, $mode);
         hash_update($Context, pack('N', strlen($size)));
         hash_update($Context, $size);
         hash_update($Context, $content);
      }

      return hash_final($Context);
   }

   /**
    * Run Git without a shell so repository paths cannot alter the command.
    *
    * @param array<int,string> $arguments
    */
   private static function execute (string $path, array $arguments): ?string
   {
      $process = @proc_open(
         [
            'git',
            '-c', 'core.fsmonitor=false',
            '-c', 'core.hooksPath=/dev/null',
            '-C', $path,
            ...$arguments,
         ],
         [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ],
         $pipes,
         null,
         self::sanitize(),
      );

      if (is_resource($process) === false) {
         return null;
      }

      fclose($pipes[0]);
      $STDOUT = stream_get_contents($pipes[1]);
      fclose($pipes[1]);
      stream_get_contents($pipes[2]);
      fclose($pipes[2]);

      return proc_close($process) === 0 && $STDOUT !== false ? $STDOUT : null;
   }

   /**
    * Remove ambient Git process overrides while preserving the host PATH.
    *
    * @return array<string,string>
    */
   private static function sanitize (): array
   {
      /** @var array<string,string> $environment */
      $environment = (array) getenv();

      foreach (array_keys($environment) as $name) {
         if (str_starts_with($name, 'GIT_')) {
            unset($environment[$name]);
         }
      }
      $environment['GIT_NO_REPLACE_OBJECTS'] = '1';
      $environment['GIT_OPTIONAL_LOCKS'] = '0';

      return $environment;
   }

   private static function normalize (string|false|null $value, string $type): ?string
   {
      if ($value === false || $value === null) {
         return null;
      }

      $value = strtolower(trim($value));

      // ? Git SHA-1 is 40 hex characters; SHA-256 repositories use 64.
      if ($type === 'sha') {
         return preg_match('/\A(?:[0-9a-f]{40}|[0-9a-f]{64})\z/D', $value) === 1
            ? $value
            : null;
      }

      if ($type === 'sha256') {
         return preg_match('/\A[0-9a-f]{64}\z/D', $value) === 1
            ? $value
            : null;
      }

      return match ($value) {
         '1', 'true', 'yes', 'dirty' => 'true',
         '0', 'false', 'no', 'clean' => 'false',
         default => null,
      };
   }
}
