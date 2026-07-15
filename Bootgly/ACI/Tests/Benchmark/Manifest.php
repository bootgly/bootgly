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


use const DIRECTORY_SEPARATOR;
use const FILE_IGNORE_NEW_LINES;
use const FILE_SKIP_EMPTY_LINES;
use const JSON_PRETTY_PRINT;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const SORT_STRING;
use function array_keys;
use function array_pad;
use function array_unique;
use function array_values;
use function count;
use function explode;
use function file;
use function file_get_contents;
use function function_exists;
use function getenv;
use function gethostname;
use function gmdate;
use function hash_file;
use function hrtime;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function php_uname;
use function preg_match;
use function round;
use function shell_exec;
use function sort;
use function sprintf;
use function str_ends_with;
use function str_replace;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function usort;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use stdClass;


/**
 * Build the versioned, integrity-indexed record for one benchmark invocation.
 */
final class Manifest
{
   private const ENVIRONMENT = [
      'AI_AGENT',
      'BENCHMARK_FORMAT',
      'BENCHMARK_LOAD_SET',
      'BENCHMARK_RUNNER',
      'BOOTGLY_CLIENT_PROFILE',
      'BOOTGLY_PROFILE',
      'BOOTGLY_PROFILE_EVENT',
      'BOOTGLY_PROFILE_PERIOD',
      'BOOTGLY_WORKERS',
   ];

   private readonly float $started;
   private readonly int $startedMonotonic;
   /** @var array<int,string> */
   private readonly array $arguments;
   /** @var array<string,mixed> */
   private array $selection = [];


   /** @param array<int,string> $arguments */
   public function __construct (
      private readonly Artifacts $Artifacts,
      private readonly string $caseName,
      array $arguments,
      private readonly string $workingDirectory,
   )
   {
      $this->started = microtime(true);
      $this->startedMonotonic = hrtime(true);
      $this->arguments = $this->redact($arguments);
   }

   /** @param array<string,mixed> $selection */
   public function select (array $selection): void
   {
      $this->selection = $selection;
   }

   /**
    * Atomically publish the final run manifest.
    */
   public function finish (int $exit, null|stdClass $Document = null): string
   {
      $finished = microtime(true);
      $finishedMonotonic = hrtime(true);
      [$artifacts, $processes, $unpublished] = $this->collect();
      $selection = $Document instanceof stdClass && isset($Document->rounds)
         ? $this->summarize($Document)
         : $this->selection;

      $environment = [];
      foreach (self::ENVIRONMENT as $name) {
         $value = getenv($name);
         if ($value !== false) {
            $environment[$name] = $value;
         }
      }

      $cpuModel = null;
      $cpuInfo = @file('/proc/cpuinfo', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach (is_array($cpuInfo) ? $cpuInfo : [] as $line) {
         if (str_starts_with($line, 'model name')) {
            [, $cpuModel] = array_pad(explode(':', $line, 2), 2, null);
            $cpuModel = is_string($cpuModel) ? trim($cpuModel) : null;
            break;
         }
      }

      $affinity = null;
      $processStatus = @file('/proc/self/status', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
      foreach (is_array($processStatus) ? $processStatus : [] as $line) {
         if (str_starts_with($line, 'Cpus_allowed_list:')) {
            $affinity = trim(substr($line, strlen('Cpus_allowed_list:')));
            break;
         }
      }

      $manifest = [
         'schema' => 'bootgly.benchmark-run/v1',
         'run' => [
            'id' => $this->Artifacts->ID,
            'directory' => $this->Artifacts->relativeDirectory,
            'path_base' => $this->Artifacts->pathBase,
            'working_directory' => $this->workingDirectory,
            'started_at' => $this->format($this->started),
            'finished_at' => $this->format($finished),
            'duration_ms' => round(($finishedMonotonic - $this->startedMonotonic) / 1_000_000, 3),
            'exit_code' => $exit,
            'publication_complete' => $unpublished === [],
            'unpublished_staging' => $unpublished,
         ],
         'command' => [
            'argv' => $this->arguments,
            'environment' => $environment === [] ? new stdClass : $environment,
            'random_seed' => null,
            'random_seed_status' => 'not-configured',
         ],
         'selection' => $selection === [] ? new stdClass : $selection,
         'runtime' => Runtime::export(),
         'host' => [
            'hostname' => gethostname() ?: null,
            'kernel' => php_uname(),
            'cpu_model' => $cpuModel,
            'cpu_count' => function_exists('shell_exec')
               ? (int) trim((string) @shell_exec('nproc 2>/dev/null'))
               : null,
            'cpu_affinity' => $affinity,
         ],
         'processes' => $processes,
         'artifacts' => $artifacts,
         'integrity' => [
            'algorithm' => 'sha256',
            'payload_count' => count($artifacts),
            'manifest_self_hash' => 'excluded-to-avoid-self-reference',
         ],
      ];

      $JSON = json_encode(
         $manifest,
         JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
      ) . "\n";

      return $this->Artifacts->write('manifest.json', $JSON);
   }

   /**
    * Extract selection/configuration fields without duplicating result payloads.
    *
    * @return array<string,mixed>
    */
   private function summarize (stdClass $Document): array
   {
      $data = json_decode(
         json_encode($Document, JSON_THROW_ON_ERROR),
         true,
         512,
         JSON_THROW_ON_ERROR,
      );
      if (!is_array($data)) {
         return [];
      }

      $opponents = [];
      $loads = [];
      $roundOptions = [];
      foreach ($data['rounds'] ?? [] as $round) {
         if (!is_array($round)) {
            continue;
         }
         $roundOptions[] = $round['options'] ?? [];
         foreach (($round['results'] ?? []) as $opponent => $results) {
            $opponents[(string) $opponent] = true;
            foreach (is_array($results) ? array_keys($results) : [] as $load) {
               $loads[(string) $load] = true;
            }
         }
      }

      return [
         'case' => $data['case'] ?? $this->caseName,
         'metric' => $data['metric'] ?? null,
         'config' => $data['config'] ?? new stdClass,
         'sweep' => $data['sweep'] ?? new stdClass,
         'round_options' => $roundOptions,
         'opponents' => array_keys($opponents),
         'loads' => array_keys($loads),
      ];
   }

   /**
    * Collect run-owned payload hashes plus child process terminal records.
    *
    * @return array{0:array<int,array<string,int|string|null>>,1:array<int,array<string,mixed>>,2:array<int,string>}
    */
   private function collect (): array
   {
      $artifacts = [];
      $processes = [];
      $unpublished = [];
      $Iterator = new RecursiveIteratorIterator(
         new RecursiveDirectoryIterator($this->Artifacts->directory, FilesystemIterator::SKIP_DOTS),
         RecursiveIteratorIterator::SELF_FIRST,
      );

      foreach ($Iterator as $File) {
         $relative = str_replace(
            DIRECTORY_SEPARATOR,
            '/',
            substr($File->getPathname(), strlen($this->Artifacts->directory) + 1)
         );
         if (preg_match('#(?:^|/)(?:[^/]*\.capture(?:-|/|$)|[^/]*\.tmp(?:-|$)|\.supervisor\.claim)#', $relative) === 1) {
            $unpublished[] = $this->Artifacts->relate($relative);
         }

         if (!$File->isFile() || $File->isLink()) {
            continue;
         }
         if ($relative === 'manifest.json') {
            continue;
         }

         $path = $this->Artifacts->relate($relative);
         $hash = hash_file('sha256', $File->getPathname());
         $artifacts[] = [
            'path' => $path,
            'bytes' => $File->getSize(),
            'sha256' => is_string($hash) ? $hash : null,
         ];

         $name = $File->getFilename();
         if ($name === 'status.json' || str_ends_with($name, '.failure.json')) {
            $status = json_decode((string) file_get_contents($File->getPathname()), true);
            if (is_array($status)) {
               if (isset($status['command']) && is_array($status['command'])) {
                  $status['command'] = $this->redact($status['command']);
               }
               $processes[] = ['artifact' => $path, 'status' => $status];
            }
         }
      }

      usort($artifacts, static fn (array $a, array $b): int => $a['path'] <=> $b['path']);
      usort($processes, static fn (array $a, array $b): int => $a['artifact'] <=> $b['artifact']);
      $unpublished = array_values(array_unique($unpublished));
      sort($unpublished, SORT_STRING);

      return [$artifacts, $processes, $unpublished];
   }

   /** @param array<int,string> $arguments @return array<int,string> */
   private function redact (array $arguments): array
   {
      $redacted = [];
      $hideNext = false;
      foreach ($arguments as $index => $argument) {
         if ($hideNext) {
            $redacted[] = '<redacted>';
            $hideNext = false;
            continue;
         }

         // ! Persist option names for diagnosis, but deny their values by
         //   default. Reproducible benchmark values live in the validated
         //   selection/config sections; argv can also contain unknown options.
         if (preg_match('#[a-z][a-z0-9+.-]*://[^/\s:@]+:[^@\s/]+@#i', $argument) === 1) {
            $equals = strpos($argument, '=');
            $redacted[] = $equals === false
               ? '<redacted-uri>'
               : substr($argument, 0, $equals + 1) . '<redacted-uri>';
            continue;
         }
         if (preg_match('/\A(--?[A-Za-z0-9][A-Za-z0-9._-]*)=.*/', $argument, $matches) === 1) {
            $redacted[] = $matches[1] . '=<redacted>';
            continue;
         }
         if (preg_match('/\A([A-Za-z_][A-Za-z0-9_.-]*)=.*/', $argument, $matches) === 1) {
            $redacted[] = $matches[1] . '=<redacted>';
            continue;
         }
         if (preg_match('/\A--?[A-Za-z0-9][A-Za-z0-9._-]*\z/', $argument) === 1) {
            $redacted[] = $argument;
            $next = $arguments[$index + 1] ?? null;
            $hideNext = is_string($next) && !str_starts_with($next, '-');
            continue;
         }

         $redacted[] = $argument;
      }

      return $redacted;
   }

   private function format (float $time): string
   {
      $seconds = (int) $time;
      $microseconds = (int) round(($time - $seconds) * 1_000_000);
      if ($microseconds === 1_000_000) {
         $seconds++;
         $microseconds = 0;
      }

      return gmdate('Y-m-d\TH:i:s', $seconds) . sprintf('.%06dZ', $microseconds);
   }
}
