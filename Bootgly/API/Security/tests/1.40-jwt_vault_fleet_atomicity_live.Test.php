<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Security\Tests\JWTVaultFleetAtomicityLive;


use const BOOTGLY_ROOT_DIR;
use const JSON_UNESCAPED_SLASHES;
use const LOCK_EX;
use const PHP_BINARY;
use const SIGKILL;
use const SORT_REGULAR;
use function array_filter;
use function array_key_exists;
use function array_map;
use function array_reverse;
use function array_unique;
use function array_values;
use function assert;
use function bin2hex;
use function count;
use function fclose;
use function file_put_contents;
use function fsockopen;
use function function_exists;
use function getenv;
use function hash;
use function is_array;
use function is_dir;
use function is_file;
use function is_link;
use function is_resource;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function mkdir;
use function preg_split;
use function proc_close;
use function proc_get_status;
use function proc_open;
use function proc_terminate;
use function random_bytes;
use function rmdir;
use function scandir;
use function stream_get_contents;
use function time;
use function trim;
use function unlink;
use function usleep;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Usage;
use Bootgly\API\Security\JWT\Vault;


$optin = getenv('BOOTGLY_H6_E2E') === '1';
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : 'redis-h6';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;
$capable = function_exists('proc_open');
$reachable = false;

if ($optin && $capable) {
   $Probe = @fsockopen($host, $port, $errorCode, $error, 0.5);
   $reachable = is_resource($Probe);
   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'Security H6: JWT Vault fleet-wide claim/take are backend-atomic (Redis live)',
   skip: $optin === false,

   test: function () use ($host, $port, $capable, $reachable): \Generator {
      yield assert(
         assertion: $capable && $reachable,
         description: 'H6 opt-in requires proc_open and the configured Redis endpoint'
      );
      if ($capable === false || $reachable === false) {
         return;
      }

      $run = bin2hex(random_bytes(8));
      $base = "/tmp/bootgly-h6-vault-{$run}";
      $cachePrefix = "h6:{$run}:";
      $vaultPrefix = 'h6_';
      $secret = bin2hex(random_bytes(32));
      $worker = __DIR__ . '/fixtures/jwt_vault_fleet_worker.php';

      $Clean = static function (string $path) use (&$Clean): void {
         if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
         }
         if (is_dir($path) === false) {
            return;
         }

         foreach (scandir($path) ?: [] as $name) {
            if ($name !== '.' && $name !== '..') {
               $Clean("{$path}/{$name}");
            }
         }
         @rmdir($path);
      };

      /** @return null|array<string,mixed> */
      $Parse = static function (string $output): null|array {
         $lines = preg_split('/\R/', trim($output));
         if (is_array($lines) === false) {
            return null;
         }

         foreach (array_reverse(array_filter($lines, static fn (string $line): bool => $line !== '')) as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
               return $decoded;
            }
         }

         return null;
      };

      /**
       * Run two host-shaped worker processes against one Redis record.
       *
       * @return array{
       *    error:null|string,
       *    released:bool,
       *    fast:bool,
       *    Workers:array<string,array<string,mixed>>
       * }
       */
      $Race = static function (
         string $action,
         string $input,
         string $target,
      ) use (
         $base,
         $cachePrefix,
         $host,
         $port,
         $secret,
         $vaultPrefix,
         $worker,
         $Clean,
         $Parse,
      ): array {
         $directory = "{$base}/{$action}";
         $barrier = "{$directory}/barrier";
         $Clean($directory);
         if (mkdir($barrier, 0700, true) === false) {
            return [
               'error' => 'race barrier directory could not be created',
               'released' => false,
               'fast' => false,
               'Workers' => [],
            ];
         }

         $Processes = [];
         $Pipes = [];
         $Statuses = [];
         $error = null;
         $released = false;
         $fast = false;

         try {
            foreach (['A', 'B'] as $role) {
               $storage = "{$directory}/host-{$role}";
               $command = [
                  PHP_BINARY,
                  $worker,
                  '--worker',
                  BOOTGLY_ROOT_DIR,
                  $storage,
                  $host,
                  (string) $port,
                  $cachePrefix,
                  $vaultPrefix,
                  $secret,
                  $action,
                  $input,
                  $target,
                  $barrier,
                  $role,
               ];
               $RolePipes = [];
               $Process = proc_open($command, [
                  0 => ['pipe', 'r'],
                  1 => ['pipe', 'w'],
                  2 => ['pipe', 'w'],
               ], $RolePipes);

               if (is_resource($Process) === false) {
                  throw new RuntimeException("worker {$role} could not start");
               }
               fclose($RolePipes[0]);

               $Processes[$role] = $Process;
               $Pipes[$role] = $RolePipes;
            }

            $deadline = microtime(true) + 12.0;
            do {
               $ready = (is_file("{$barrier}/A.ready") ? 1 : 0)
                  + (is_file("{$barrier}/B.ready") ? 1 : 0);
               $running = 0;
               foreach ($Processes as $role => $Process) {
                  $Status = proc_get_status($Process);
                  $Statuses[$role] = $Status;
                  $running += ($Status['running'] ?? false) ? 1 : 0;
               }

               if ($ready === 2) {
                  if (file_put_contents("{$barrier}/release", 'release', LOCK_EX) === false) {
                     throw new RuntimeException('race barrier could not be released');
                  }
                  $released = true;
                  break;
               }
               // ? An atomic create() winner can finish without fetch while
               //   only the loser observes the occupied key. Release that
               //   one waiter; worker exit/error validation still decides
               //   whether this was the legitimate fixed path.
               if ($ready === 1 && $running < 2) {
                  if (file_put_contents("{$barrier}/release", 'release', LOCK_EX) === false) {
                     throw new RuntimeException('single race waiter could not be released');
                  }
                  $released = true;
                  $fast = true;
                  break;
               }
               if ($running === 0) {
                  $error = 'race workers exited without reaching the required Redis barrier';
                  break;
               }

               usleep(1_000);
            } while (microtime(true) < $deadline);

            if ($released === false && $fast === false) {
               $error = 'race workers did not meet or bypass the barrier before timeout';
               file_put_contents("{$barrier}/release", 'timeout', LOCK_EX);
            }

            // @ Bound process completion after release. Pipes are read only
            //   after both writers closed, so a wedged worker cannot park the
            //   native test indefinitely in stream_get_contents()/proc_close().
            $finishDeadline = microtime(true) + 12.0;
            do {
               $running = 0;
               foreach ($Processes as $role => $Process) {
                  $Status = proc_get_status($Process);
                  $Statuses[$role] = $Status;
                  $running += ($Status['running'] ?? false) ? 1 : 0;
               }
               if ($running === 0) {
                  break;
               }

               usleep(1_000);
            } while (microtime(true) < $finishDeadline);

            if ($running !== 0) {
               $error ??= 'race workers did not exit after barrier release';
               foreach ($Processes as $Process) {
                  $Status = proc_get_status($Process);
                  if ($Status['running'] ?? false) {
                     proc_terminate($Process);
                  }
               }

               $grace = microtime(true) + 1.0;
               do {
                  $running = 0;
                  foreach ($Processes as $role => $Process) {
                     $Status = proc_get_status($Process);
                     $Statuses[$role] = $Status;
                     $running += ($Status['running'] ?? false) ? 1 : 0;
                  }
                  if ($running === 0) {
                     break;
                  }
                  usleep(1_000);
               } while (microtime(true) < $grace);

               if ($running !== 0) {
                  foreach ($Processes as $Process) {
                     $Status = proc_get_status($Process);
                     if ($Status['running'] ?? false) {
                        proc_terminate($Process, SIGKILL);
                     }
                  }
               }
            }

            $Workers = [];
            foreach ($Processes as $role => $Process) {
               $stdout = stream_get_contents($Pipes[$role][1]);
               $stderr = stream_get_contents($Pipes[$role][2]);
               fclose($Pipes[$role][1]);
               fclose($Pipes[$role][2]);

               $closed = proc_close($Process);
               $exit = $closed !== -1
                  ? $closed
                  : (int) ($Statuses[$role]['exitcode'] ?? -1);
               $Workers[$role] = [
                  'exit' => $exit,
                  'stdout' => $stdout,
                  'stderr' => $stderr,
                  'decoded' => $Parse(is_string($stdout) ? $stdout : ''),
               ];
            }

            return [
               'error' => $error,
               'released' => $released,
               'fast' => $fast,
               'Workers' => $Workers,
            ];
         }
         catch (Throwable $Throwable) {
            foreach ($Processes as $role => $Process) {
               $Status = proc_get_status($Process);
               if ($Status['running'] ?? false) {
                  proc_terminate($Process);
               }
               $RolePipes = $Pipes[$role] ?? [];
               foreach ($RolePipes as $Pipe) {
                  if (is_resource($Pipe)) {
                     @fclose($Pipe);
                  }
               }
               @proc_close($Process);
            }

            return [
               'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
               'released' => $released,
               'fast' => $fast,
               'Workers' => [],
            ];
         }
      };

      $Storage = new Cache([
         'driver' => 'redis',
         'host' => $host,
         'port' => $port,
         'prefix' => $cachePrefix,
         'timeout' => 2.0,
      ]);
      $Vault = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);

      try {
         $Storage->clear();

         // # Controls — real Redis, shared secret, sequential claim and take.
         $Peer = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);
         $control = [
            'write' => $Vault->write('control-read', 'visible', 60),
            'read' => null,
            'claim_first' => null,
            'claim_second' => null,
            'claim_value' => null,
            'take_seed' => null,
            'take_first' => null,
            'take_second' => null,
         ];
         $control['read'] = $Peer->read('control-read');
         $control['claim_first'] = $Vault->claim('control-claim', 'first', 60);
         $control['claim_second'] = $Peer->claim('control-claim', 'second', 60);
         $control['claim_value'] = $Vault->read('control-claim');
         $control['take_seed'] = $Vault->write('control-take', 'once', 60);
         $control['take_first'] = $Peer->take('control-take');
         $control['take_second'] = $Vault->take('control-take');

         // # Attack 1 — full JWT inspection through the single-use Usage guard.
         $JTI = "fleet-jti-{$run}";
         $JWT = new JWT($secret);
         $token = $JWT->sign([
            'sub' => 'user-h6',
            'jti' => $JTI,
            'exp' => time() + 120,
         ]);
         $usageLogical = 'jwt:jti:seen:' . hash('sha256', $JTI);
         $usageTarget = $vaultPrefix . hash('sha256', $usageLogical);
         $UsageRace = $Race('usage', $token, $usageTarget);

         // # Attack 2 — full refresh rotation through Tokens::rotate().
         $Tokens = new Tokens($Vault);
         $Issued = $Tokens->mint('user-h6', 120, ['scope' => 'h6']);
         $activeLogical = 'jwt:refresh:active:' . hash('sha256', $Issued->refresh);
         $activeTarget = $vaultPrefix . hash('sha256', $activeLogical);
         $RotateRace = $Race('rotate', $Issued->refresh, $activeTarget);

         $Races = [
            'usage' => $UsageRace,
            'rotate' => $RotateRace,
         ];
         $fixture = $control === [
            'write' => true,
            'read' => 'visible',
            'claim_first' => true,
            'claim_second' => false,
            'claim_value' => 'first',
            'take_seed' => true,
            'take_first' => 'once',
            'take_second' => null,
         ];

         foreach ($Races as $action => $RaceEvidence) {
            $Workers = $RaceEvidence['Workers'] ?? [];
            $fixture = $fixture
               && ($RaceEvidence['error'] ?? null) === null
               && count($Workers) === 2
               && ($RaceEvidence['released'] ?? false);

            $PIDs = [];
            $locks = [];
            $crossed = 0;
            foreach ($Workers as $role => $Worker) {
               $Decoded = $Worker['decoded'] ?? null;
               $fixture = $fixture
                  && ($Worker['exit'] ?? -1) === 0
                  && is_array($Decoded)
                  && array_key_exists('error', $Decoded)
                  && $Decoded['error'] === null
                  && ($Decoded['action'] ?? null) === $action
                  && ($Decoded['role'] ?? null) === $role;
               if (is_array($Decoded)) {
                  $PIDs[] = $Decoded['pid'] ?? null;
                  $crossed += ($Decoded['crossed'] ?? false) === true ? 1 : 0;
                  $locks[] = [
                     $Decoded['lock'] ?? null,
                     $Decoded['lock_dev'] ?? null,
                     $Decoded['lock_ino'] ?? null,
                  ];
               }
            }
            $fixture = $fixture
               && count(array_unique($PIDs, SORT_REGULAR)) === 2
               && count(array_unique($locks, SORT_REGULAR)) === 2
               && ($action === 'usage' ? $crossed >= 1 : $crossed === 2);
         }

         $evidence = [
            'control' => $control,
            'usage' => $UsageRace,
            'rotate' => $RotateRace,
            'original_refresh_active' => $Tokens->check($Issued->refresh),
         ];

         yield assert(
            assertion: $fixture,
            description: 'H6 Redis/fleet fixture and controls must be healthy before the security verdict: '
               . json_encode($evidence, JSON_UNESCAPED_SLASHES)
         );
         if ($fixture === false) {
            return;
         }

         $UsageKinds = array_values(array_map(
            static fn (array $Worker): mixed => $Worker['decoded']['result'] ?? null,
            $UsageRace['Workers'],
         ));
         $RotateKinds = array_values(array_map(
            static fn (array $Worker): mixed => $Worker['decoded']['result'] ?? null,
            $RotateRace['Workers'],
         ));

         $usageAccepted = count(array_filter(
            $UsageKinds,
            static fn (mixed $result): bool => is_array($result)
               && ($result['kind'] ?? null) === 'accepted',
         ));
         $usageReplay = count(array_filter(
            $UsageKinds,
            static fn (mixed $result): bool => is_array($result)
               && ($result['kind'] ?? null) === 'failure'
               && ($result['failure'] ?? null) === Failures::Replay->name,
         ));
         $rotationTokens = count(array_filter(
            $RotateKinds,
            static fn (mixed $result): bool => is_array($result)
               && ($result['kind'] ?? null) === 'token',
         ));
         $rotationLosers = count(array_filter(
            $RotateKinds,
            static fn (mixed $result): bool => is_array($result)
               && (($result['kind'] ?? null) === 'null' || ($result['kind'] ?? null) === 'replay'),
         ));
         $secure = $usageAccepted === 1
            && $usageReplay === 1
            && $rotationTokens === 1
            && $rotationLosers === 1
            && $Tokens->check($Issued->refresh) === false;

         yield assert(
            assertion: $secure,
            description: $secure
               ? 'JWT single-use and refresh rotation elect one fleet-wide winner each'
               : 'CONFIRMED H6 JWT Vault: host-local locks admitted duplicate fleet winners; evidence='
                  . json_encode($evidence, JSON_UNESCAPED_SLASHES)
         );
      }
      finally {
         try {
            $Storage->clear();
         }
         catch (Throwable) {
         }
         $Clean($base);
      }
   }
);
