<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Resources\Cache as CacheResource;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;


/**
 * SESS-4: what an HTTP server's workers write as the runtime identity is the
 * one directory a root launch hands over — `storage/sessions`, owner-only — and
 * the default session store keeps its records there, never in the shared
 * `storage/cache` that root-run code reads.
 *
 * - `HTTP_Server_CLI::DIRECTORIES` is exactly `sessions => 0700`;
 * - the default `Session\Handlers\Cache` (file driver, no path) prepares its
 *   records under `storage/sessions/cache` — pinned in the source, and the
 *   preparation itself run on a scratch fixture, never the real storage;
 * - that preparation creates both directories owner-only, and refuses a
 *   records directory that is group/world-writable or a link, saying why;
 * - an explicit path is kept, and another driver is given no path of the
 *   handler's choosing.
 */
return new Test(
   description: 'SESS-4/SRV-4: the HTTP node hands its workers only storage/sessions and their own cache-<uid>, and the default session records live in sessions/',
   test: function () {
      $directories = new ReflectionClassConstant(HTTP_Server_CLI::class, 'DIRECTORIES')->getValue();

      yield assert(
         assertion: $directories === ['sessions' => 0o700],
         description: 'HTTP_Server_CLI::DIRECTORIES is exactly storage/sessions, owner-only — the workers\' cache-<uid> is every node\'s, provisioned softly (' . var_export($directories, true) . ')'
      );

      // @ The constructor's default, read from the source: no I/O on the real storage
      $Constructor = new ReflectionMethod(Cache::class, '__construct');
      $source = (string) php_strip_whitespace((string) $Constructor->getFileName());
      $from = (int) strpos($source, 'function __construct (');
      $body = substr($source, $from, (int) strpos($source, 'function read (', $from) - $from);
      yield assert(
         assertion: str_contains($body, "if (\$cache['driver'] === 'file' && is_string(\$cache['path'] ?? null) === false) { \$cache['path'] = self::store(BOOTGLY_STORAGE_DIR . 'sessions'); }"),
         description: 'the default file store (no path) is prepared by store() under storage/sessions'
      );

      // @ store() itself, on a scratch storage
      $store = static fn (string $directory): string
         => (static fn (): string => self::store($directory))->bindTo(null, Cache::class)();
      $mode = static fn (string $path): int => is_array($state = @lstat($path)) ? (int) $state['mode'] & 0o777 : -1;
      $base = Temporaries::reserve('session-store');
      $sessions = "{$base}/sessions";
      $records = $store($sessions);
      yield assert(
         assertion: $records === "{$sessions}/cache" && $mode($sessions) === 0o700 && $mode($records) === 0o700,
         description: 'store() creates sessions/ and sessions/cache owner-only and returns the records directory '
            . '(' . decoct($mode($sessions)) . ', ' . decoct($mode($records)) . ')'
      );

      chmod($records, 0o777);
      $thrown = '';
      try {
         $store($sessions);
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable->getMessage();
      }
      yield assert(
         assertion: $thrown === "Session Cache store directory `{$records}` must not be group- or world-writable: `chmod 700 {$records}`.",
         description: 'a group/world-writable records directory is refused with the chmod to run (' . var_export($thrown, true) . ')'
      );

      rmdir($records);
      symlink($base, $records);
      $thrown = '';
      try {
         $store($sessions);
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable->getMessage();
      }
      yield assert(
         assertion: $thrown === "Session Cache store directory `{$records}` must be a plain directory, not a link.",
         description: 'a link at the records directory is refused, never followed (' . var_export($thrown, true) . ')'
      );
      unlink($records);
      rmdir($sessions);
      rmdir($base);

      // ! What a handler's store resolves to — with a path or another driver,
      //   store() never runs, so nothing is created
      $path = static function (array $config): string {
         $Handler = new Cache(['secret' => str_repeat('k', 32)] + $config);
         /** @var CacheResource $Store */
         $Store = (fn (): CacheResource => $this->Cache)->call($Handler);

         return $Store->Config->path;
      };

      $explicit = BOOTGLY_STORAGE_DIR . 'tests/sessions-explicit';
      $kept = $path(['path' => $explicit]);
      yield assert(
         assertion: $kept === $explicit,
         description: "an explicit path is kept ({$kept})"
      );

      $Shared = new CacheResource(['driver' => 'memory']);
      $other = $path(['driver' => 'memory']);
      yield assert(
         assertion: $other === $Shared->Config->path,
         description: "another driver is given no path of the handler's choosing ({$other})"
      );
   }
);
