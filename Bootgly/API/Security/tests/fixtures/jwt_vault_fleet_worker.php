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
use function file_put_contents;
use function fwrite;
use function getmypid;
use function hash;
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


/** @param array<string,mixed> $result */
$emit = static function (array $result, int $status = 0): never {
   $JSON = json_encode($result, JSON_UNESCAPED_SLASHES);
   fwrite(STDOUT, (is_string($JSON) ? $JSON : '{}') . PHP_EOL);
   exit($status);
};

if (($argv[1] ?? '') !== '--worker' || count($argv) !== 14) {
   $emit(['error' => 'invalid worker arguments'], 2);
}

$root = rtrim((string) $argv[2], '/');
$storage = rtrim((string) $argv[3], '/');
$host = (string) $argv[4];
$port = (int) $argv[5];
$cachePrefix = (string) $argv[6];
$vaultPrefix = (string) $argv[7];
$secret = (string) $argv[8];
$action = (string) $argv[9];
$input = (string) $argv[10];
$target = (string) $argv[11];
$barrier = rtrim((string) $argv[12], '/');
$role = (string) $argv[13];

if (
   $root === ''
   || $storage === ''
   || $host === ''
   || $port < 1
   || $port > 65535
   || $cachePrefix === ''
   || $vaultPrefix === ''
   || strlen($secret) < 32
   || ($action !== 'usage' && $action !== 'rotate')
   || $input === ''
   || $target === ''
   || $barrier === ''
   || preg_match('/^[AB]$/D', $role) !== 1
) {
   $emit(['error' => 'invalid worker argument value'], 2);
}

try {
   if (is_dir($storage) === false && mkdir($storage, 0700, true) === false) {
      throw new RuntimeException('worker storage could not be created');
   }
   chmod($storage, 0700);

   define('BOOTGLY_STORAGE_BASE', $storage);
   define('BOOTGLY_STORAGE_DIR', "{$storage}/");

   // ! This is an isolated fixture process, not a Bootgly CLI command.
   $_SERVER['SCRIPT_FILENAME'] = '';
   require "{$root}/autoboot.php";

   $Storage = new class([
      'driver' => 'redis',
      'host' => $host,
      'port' => $port,
      'prefix' => $cachePrefix,
      'timeout' => 2.0,
   ], $barrier, $role, $target) extends Cache {
      // * Data
      private string $barrier;
      private string $role;
      private string $target;

      // * Metadata
      private bool $armed = true;
      private bool $crossed = false;


      /** @param array<string,mixed> $config */
      public function __construct (
         array $config,
         string $barrier,
         string $role,
         string $target,
      )
      {
         parent::__construct($config);

         // * Data
         $this->barrier = $barrier;
         $this->role = $role;
         $this->target = $target;
      }

      public function fetch (string $key): mixed
      {
         $value = parent::fetch($key);

         // ? Synchronize only the vulnerable record read, once per worker.
         if ($this->armed === false || $key !== $this->target) {
            return $value;
         }
         $this->armed = false;

         $ready = "{$this->barrier}/{$this->role}.ready";
         if (file_put_contents($ready, 'ready', LOCK_EX) === false) {
            throw new RuntimeException('worker could not publish barrier readiness');
         }

         $deadline = microtime(true) + 10.0;
         while (is_file("{$this->barrier}/release") === false) {
            if (microtime(true) >= $deadline) {
               throw new RuntimeException('worker barrier timed out');
            }

            usleep(1_000);
         }

         $this->crossed = true;

         return $value;
      }

      public function report (): bool
      {
         return $this->crossed;
      }
   };

   $Vault = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);

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
   else {
      $Tokens = new Tokens($Vault);
      $Result = $Tokens->rotate($input, 120);

      $result = match (true) {
         $Result instanceof Token => [
            'kind' => 'token',
            'refresh_hash' => hash('sha256', $Result->refresh),
         ],
         $Result instanceof Replay => [
            'kind' => 'replay',
            'refresh_hash' => null,
         ],
         default => [
            'kind' => 'null',
            'refresh_hash' => null,
         ],
      };
   }

   $lock = "{$Vault->path}{$vaultPrefix}.lock";
   $LockStat = @stat($lock);

   $emit([
      'error' => null,
      'action' => $action,
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
      'role' => $role,
   ], 1);
}
