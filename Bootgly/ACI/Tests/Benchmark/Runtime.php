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


use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const PHP_BINARY;
use const PHP_DEBUG;
use const PHP_INT_SIZE;
use const PHP_OS_FAMILY;
use const PHP_SAPI;
use const PHP_VERSION;
use const PHP_ZTS;
use const SORT_STRING;
use function array_filter;
use function array_flip;
use function array_intersect_key;
use function array_key_exists;
use function array_map;
use function array_values;
use function count;
use function explode;
use function function_exists;
use function get_loaded_extensions;
use function hash;
use function ini_get;
use function ini_get_all;
use function is_array;
use function is_string;
use function json_encode;
use function ksort;
use function max;
use function opcache_get_status;
use function php_ini_loaded_file;
use function php_ini_scanned_files;
use function phpversion;
use function realpath;


/**
 * Describe and replay the PHP runtime used by a supervised benchmark.
 */
final class Runtime
{
   /**
    * Performance-sensitive directives that must survive the supervisor re-exec.
    *
    * The complete post-bootstrap runtime is fingerprinted separately. A caller
    * override outside this list is therefore rejected if it produces a mismatch.
    *
    * @var array<int,string>
    */
   private const DIRECTIVES = [
      'assert.exception',
      'default_socket_timeout',
      'fiber.stack_size',
      'max_execution_time',
      'memory_limit',
      'opcache.enable_cli',
      'opcache.file_update_protection',
      'opcache.jit',
      'opcache.jit_buffer_size',
      'opcache.max_accelerated_files',
      'opcache.memory_consumption',
      'opcache.optimization_level',
      'opcache.revalidate_freq',
      'opcache.validate_timestamps',
      'pcre.jit',
      'realpath_cache_size',
      'realpath_cache_ttl',
      'zend.assertions',
   ];

   /**
    * Inspect stable, effective runtime state after the framework bootstrap.
    *
    * @return array<string,mixed>
    */
   public static function inspect (): array
   {
      $extensions = [];
      foreach (get_loaded_extensions() as $extension) {
         $extensions[$extension] = phpversion($extension) ?: null;
      }
      ksort($extensions, SORT_STRING);

      $directives = ini_get_all(null, true);
      $directives = is_array($directives) ? $directives : [];
      ksort($directives, SORT_STRING);

      $scanned = php_ini_scanned_files();
      $scanned = is_string($scanned)
         ? array_values(array_filter(
            array_map('trim', explode(',', $scanned)),
            static fn (string $file): bool => $file !== '',
         ))
         : [];

      $jit = null;
      if (function_exists('opcache_get_status')) {
         $status = opcache_get_status(false);
         $state = is_array($status) ? ($status['jit'] ?? null) : null;
         if (is_array($state)) {
            $jit = array_intersect_key($state, array_flip([
               'enabled',
               'on',
               'kind',
               'opt_level',
               'opt_flags',
               'buffer_size',
            ]));
         }
      }

      $binary = realpath(PHP_BINARY);

      return [
         'binary' => $binary !== false ? $binary : PHP_BINARY,
         'version' => PHP_VERSION,
         'sapi' => PHP_SAPI,
         'os_family' => PHP_OS_FAMILY,
         'integer_size' => PHP_INT_SIZE,
         'thread_safe' => PHP_ZTS === 1,
         'debug' => PHP_DEBUG === 1,
         'ini_file' => php_ini_loaded_file() ?: null,
         'ini_scanned' => $scanned,
         'directives' => $directives,
         'extensions' => $extensions,
         'jit' => $jit,
      ];
   }

   /**
    * Fingerprint the complete inspected runtime deterministically.
    */
   public static function fingerprint (): string
   {
      $JSON = json_encode(self::inspect(), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

      return hash('sha256', $JSON);
   }

   /**
    * Export a persistence-safe runtime description for the run manifest.
    *
    * The supervisor compares the complete raw state in memory through
    * fingerprint(). Persist only the performance directive allowlist: arbitrary
    * PHP/extension directives can contain credentials or connection DSNs (for
    * example session.save_path) even when their names do not say "password".
    *
    * @return array<string,mixed>
    */
   public static function export (): array
   {
      $runtime = self::inspect();
      $directives = is_array($runtime['directives'] ?? null)
         ? $runtime['directives']
         : [];
      $allowed = [];
      foreach (self::DIRECTIVES as $name) {
         if (array_key_exists($name, $directives)) {
            $allowed[$name] = $directives[$name];
         }
      }

      $runtime['directives'] = $allowed;
      $runtime['directives_policy'] = [
         'schema' => 'performance-allowlist/v1',
         'persisted' => count($allowed),
         'omitted' => max(0, count($directives) - count($allowed)),
         'complete_state_comparison' => 'in-memory-fingerprint-only',
      ];

      return $runtime;
   }

   /**
    * Replay the loaded INI and effective performance directives in a child CLI.
    *
    * @return array<int,string> Arguments inserted between PHP_BINARY and script.
    */
   public static function replay (): array
   {
      $arguments = [];
      $settings = ini_get_all(null, true);
      $settings = is_array($settings) ? $settings : [];
      $ini = php_ini_loaded_file();
      $scanned = php_ini_scanned_files();
      $hasScanned = is_string($scanned) && trim($scanned) !== '';
      if ($ini === false && $hasScanned === false) {
         $arguments[] = '-n';
      }
      elseif ($ini !== false) {
         $arguments[] = '-c';
         $arguments[] = $ini;
      }

      foreach (self::DIRECTIVES as $name) {
         $value = ini_get($name);
         $setting = $settings[$name] ?? null;
         if (
            $value === false
            || (
               is_array($setting)
               && ($setting['global_value'] ?? null) === null
               && ($setting['local_value'] ?? null) === null
            )
         ) {
            continue;
         }

         $arguments[] = '-d';
         $arguments[] = "{$name}={$value}";
      }

      return $arguments;
   }
}
