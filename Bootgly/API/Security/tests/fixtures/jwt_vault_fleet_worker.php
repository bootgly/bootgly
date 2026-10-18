<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\Fixtures\JWTVaultFleetWorker;


use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;
use const PHP_EOL;
use const STDOUT;
use function chmod;
use function count;
use function define;
use function explode;
use function file_put_contents;
use function fwrite;
use function getmypid;
use function hash;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_string;
use function json_encode;
use function microtime;
use function mkdir;
use function preg_match;
use function rtrim;
use function stat;
use function str_starts_with;
use function strlen;
use function usleep;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Replay;
use Bootgly\API\Security\JWT\Token;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Usage;
use Bootgly\API\Security\JWT\Vault;


/**
 * One host of the 1.40 fleet race: its own storage directory (so its own
 * Vault lock file — a separate host lock domain) over the store it shares
 * with the other host (a `file` directory or a Redis endpoint).
 *
 * Arguments: --worker <root> <storage> <driver> <endpoint> <cache prefix>
 *    <vault prefix> <secret> <action> <input> <target> <barrier> <mode> <role>
 *
 * - driver/endpoint: `file` + the shared store directory, or `redis` + host:port.
 * - action: `usage` (single-use JWT inspection), `rotate` or `revoke` (refresh).
 * - mode: where the worker parks on the `target` storage key —
 *   `fetch`: after its first read of the key (both roles);
 *   `evict`: immediately before the evict of the key (both roles; B's first
 *   read of the key waits until A parked, so A always reaches its evict);
 *   `late`: A parks after its successful evict of the key; B's first read of
 *   the key waits until A has evicted, then B runs to the end unparked.
 */

/** @param array<string,mixed> $result */
$emit = static function (array $result, int $status = 0): never {
   $JSON = json_encode($result, JSON_UNESCAPED_SLASHES);
   fwrite(STDOUT, (is_string($JSON) ? $JSON : '{}') . PHP_EOL);
   exit($status);
};

// ?
if (($argv[1] ?? '') !== '--worker' || count($argv) !== 15) {
   $emit(['error' => 'invalid worker arguments'], 2);
}

// ! Arguments
$root = rtrim((string) $argv[2], '/');
$storage = rtrim((string) $argv[3], '/');
$driver = (string) $argv[4];
$endpoint = (string) $argv[5];
$cachePrefix = (string) $argv[6];
$vaultPrefix = (string) $argv[7];
$secret = (string) $argv[8];
$action = (string) $argv[9];
$input = (string) $argv[10];
$target = (string) $argv[11];
$barrier = rtrim((string) $argv[12], '/');
$mode = (string) $argv[13];
$role = (string) $argv[14];

// # Store
$config = null;
if ($driver === 'file' && str_starts_with($endpoint, '/') && is_dir($endpoint)) {
   $config = [
      'driver' => 'file',
      'path' => rtrim($endpoint, '/'),
      'prefix' => $cachePrefix,
   ];
}
else if ($driver === 'redis' && preg_match('/^[^:]+:\d{1,5}$/D', $endpoint) === 1) {
   [$host, $port] = explode(':', $endpoint, 2);
   $config = (int) $port >= 1 && (int) $port <= 65535
      ? [
         'driver' => 'redis',
         'host' => $host,
         'port' => (int) $port,
         'prefix' => $cachePrefix,
         'timeout' => 2.0,
      ]
      : null;
}

// ?
if (
   $config === null
   || $root === ''
   || $storage === ''
   || $cachePrefix === ''
   || $vaultPrefix === ''
   || strlen($secret) < 32
   || in_array($action, ['usage', 'rotate', 'revoke'], true) === false
   || $input === ''
   || $target === ''
   || $barrier === ''
   || in_array($mode, ['fetch', 'evict', 'late'], true) === false
   || preg_match('/^[AB]$/D', $role) !== 1
) {
   $emit(['error' => 'invalid worker argument value'], 2);
}

try {
   // ! A per-host storage directory: its own Vault lock file
   if (is_dir($storage) === false && mkdir($storage, 0700, true) === false) {
      throw new RuntimeException('worker storage could not be created');
   }
   chmod($storage, 0700);

   define('BOOTGLY_STORAGE_BASE', $storage);
   define('BOOTGLY_STORAGE_DIR', "{$storage}/");

   // ! This is an isolated fixture process, not a Bootgly CLI command.
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   $Storage = new class($config, $barrier, $mode, $role, $target) extends Cache {
      // * Data
      private string $barrier;
      private string $mode;
      private string $role;
      private string $target;

      // * Metadata
      private bool $armed = true;
      private bool $crossed = false;
      private bool $waited = false;


      /** @param array<string,mixed> $config */
      public function __construct (
         array $config,
         string $barrier,
         string $mode,
         string $role,
         string $target,
      )
      {
         parent::__construct($config);

         // * Data
         $this->barrier = $barrier;
         $this->mode = $mode;
         $this->role = $role;
         $this->target = $target;
      }

      public function fetch (string $key): mixed
      {
         // ? Synchronize only the target record.
         if ($key !== $this->target) {
            return parent::fetch($key);
         }

         // # evict, late — B reads the target only once A parked on it
         //   (before its evict / after its evict), so A always reaches the
         //   election and B's whole path runs against A's parked state
         if ($this->role === 'B' && $this->mode !== 'fetch' && $this->waited === false) {
            $this->waited = true;
            $this->wait("{$this->barrier}/A.ready");
            if ($this->mode === 'late') {
               $this->crossed = true;
            }
         }

         $value = parent::fetch($key);
         // # fetch — park after the vulnerable read, once
         if ($this->mode === 'fetch' && $this->armed) {
            $this->armed = false;
            $this->park();
         }

         // :
         return $value;
      }

      public function evict (string $key, mixed $expected): bool
      {
         // ? Synchronize only the target record, once per worker.
         if ($this->armed === false || $key !== $this->target) {
            return parent::evict($key, $expected);
         }

         // # evict — both park immediately before the election
         if ($this->mode === 'evict') {
            $this->armed = false;
            $this->park();

            return parent::evict($key, $expected);
         }

         // # late — A parks after winning the election
         $evicted = parent::evict($key, $expected);
         if ($this->mode === 'late' && $this->role === 'A' && $evicted) {
            $this->armed = false;
            $this->park();
         }

         // :
         return $evicted;
      }

      /**
       * Publish this worker's readiness and wait for the parent's release.
       */
      private function park (): void
      {
         $ready = "{$this->barrier}/{$this->role}.ready";
         if (file_put_contents($ready, 'ready', LOCK_EX) === false) {
            throw new RuntimeException('worker could not publish barrier readiness');
         }

         $this->wait("{$this->barrier}/release");
         $this->crossed = true;
      }

      /**
       * Wait for a barrier flag file.
       */
      private function wait (string $flag): void
      {
         $deadline = microtime(true) + 10.0;
         // @@
         while (is_file($flag) === false) {
            if (microtime(true) >= $deadline) {
               throw new RuntimeException('worker barrier timed out');
            }

            usleep(1_000);
         }
      }

      public function report (): bool
      {
         return $this->crossed;
      }
   };

   $Vault = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);

   // @
   if ($action === 'usage') {
      $Verifier = new JWT($secret);
      $Verifier->track(new Usage($Vault, single: true));
      $Verification = $Verifier->inspect($input);

      $result = [
         'kind' => $Verification->valid ? 'accepted' : 'failure',
         'failure' => $Verification->failure instanceof Failures
            ? $Verification->failure->name
            : null,
      ];
   }
   else if ($action === 'revoke') {
      $Tokens = new Tokens($Vault);

      $result = [
         'kind' => $Tokens->revoke($input) ? 'true' : 'false',
      ];
   }
   else {
      $Tokens = new Tokens($Vault);
      $Result = $Tokens->rotate($input, 120);

      // ! The raw successor and the family are test-only evidence: the parent
      //   checks the successor is dead and the family revoked.
      $result = match (true) {
         $Result instanceof Token => [
            'kind' => 'token',
            'refresh' => $Result->refresh,
            'refresh_hash' => hash('sha256', $Result->refresh),
            'family' => $Result->family,
         ],
         $Result instanceof Replay => [
            'kind' => 'replay',
            'refresh' => null,
            'refresh_hash' => null,
            'family' => $Result->family,
         ],
         default => [
            'kind' => 'null',
            'refresh' => null,
            'refresh_hash' => null,
            'family' => null,
         ],
      };
   }

   $lock = "{$Vault->path}{$vaultPrefix}.lock";
   $LockStat = @stat($lock);

   // :
   $emit([
      'error' => null,
      'action' => $action,
      'mode' => $mode,
      'role' => $role,
      'pid' => getmypid(),
      'crossed' => $Storage->report(),
      'lock' => $lock,
      'lock_dev' => is_array($LockStat) ? ($LockStat['dev'] ?? null) : null,
      'lock_ino' => is_array($LockStat) ? ($LockStat['ino'] ?? null) : null,
      'result' => $result,
   ]);
}
catch (Throwable $Throwable) {
   $emit([
      'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
      'action' => $action,
      'mode' => $mode,
      'role' => $role,
   ], 1);
}
