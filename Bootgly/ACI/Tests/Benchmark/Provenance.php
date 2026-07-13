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


use function fclose;
use function getenv;
use function is_dir;
use function is_resource;
use function preg_match;
use function proc_close;
use function proc_open;
use function realpath;
use function stream_get_contents;
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
   /**
    * Collect framework and benchmark-suite provenance as `.marks` config keys.
    *
    * @return array<string,string>
    */
   public static function collect (string $frameworkPath, string $benchmarksPath): array
   {
      return [
         ...self::inspect(
            prefix: 'framework',
            path: $frameworkPath,
            SHAFallback: getenv('BOOTGLY_FRAMEWORK_SHA'),
            dirtyFallback: getenv('BOOTGLY_FRAMEWORK_DIRTY'),
         ),
         ...self::inspect(
            prefix: 'benchmarks',
            path: $benchmarksPath,
            SHAFallback: getenv('BOOTGLY_BENCHMARKS_SHA'),
            dirtyFallback: getenv('BOOTGLY_BENCHMARKS_DIRTY'),
         ),
      ];
   }

   /**
    * Inspect one repository.
    *
    * Dirty means any staged, unstaged, untracked, or dirty-submodule state.
    * Invalid or unavailable fallback values are reported explicitly as
    * `unknown`; they can never inject arbitrary text into a `.marks` header.
    *
    * @param string|false|null $SHAFallback
    * @param string|false|null $dirtyFallback
    *
    * @return array<string,string>
    */
   public static function inspect (
      string $prefix,
      string $path,
      string|false|null $SHAFallback = null,
      string|false|null $dirtyFallback = null,
   ): array
   {
      $SHA = null;
      $dirty = null;
      $path = realpath($path) ?: $path;

      if (is_dir($path)) {
         $head = self::execute($path, ['rev-parse', '--verify', 'HEAD']);
         $SHA = self::normalize($head, 'sha');

         $status = self::execute($path, [
            'status',
            '--porcelain=v1',
            '--untracked-files=normal',
            '--ignore-submodules=none',
         ]);
         if ($status !== null) {
            $dirty = trim($status) === '' ? 'false' : 'true';
         }
      }

      $SHA ??= self::normalize($SHAFallback, 'sha');
      $dirty ??= self::normalize($dirtyFallback, 'dirty');

      return [
         "{$prefix}-sha" => $SHA ?? 'unknown',
         "{$prefix}-dirty" => $dirty ?? 'unknown',
      ];
   }

   /**
    * Run Git without a shell so repository paths cannot alter the command.
    *
    * @param array<int,string> $arguments
    */
   private static function execute (string $path, array $arguments): ?string
   {
      $process = @proc_open(
         ['git', '-C', $path, ...$arguments],
         [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
         ],
         $pipes,
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

      return match ($value) {
         '1', 'true', 'yes', 'dirty' => 'true',
         '0', 'false', 'no', 'clean' => 'false',
         default => null,
      };
   }
}
