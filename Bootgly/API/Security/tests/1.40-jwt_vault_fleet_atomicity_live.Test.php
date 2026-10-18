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
use function array_reverse;
use function array_unique;
use function assert;
use function bin2hex;
use function count;
use function fclose;
use function file_put_contents;
use function fsockopen;
use function function_exists;
use function getenv;
use function hash;
use function in_array;
use function is_array;
use function is_dir;
use function is_file;
use function is_int;
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
use Generator;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ACI\Tests\Temporaries;
use Bootgly\API\Security\JWT;
use Bootgly\API\Security\JWT\Failures;
use Bootgly\API\Security\JWT\Tokens;
use Bootgly\API\Security\JWT\Vault;


// ! The file lane needs only child processes; the Redis lane is opt-in.
$capable = function_exists('proc_open');
$optin = getenv('BOOTGLY_H6_E2E') === '1';
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : 'redis-h6';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;
$reachable = false;

if ($optin && $capable) {
   $Probe = @fsockopen($host, $port, $errorCode, $error, 0.5);
   $reachable = is_resource($Probe);
   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'Security H6: JWT single-use, refresh rotation and logout elect one winner across two host lock domains (file store; Redis opt-in)',
   skip: $capable === false,

   test: function () use ($host, $port, $optin, $reachable): Generator {
      // ! Run-scoped names: a store, two host storage dirs and barriers per
      //   race — under one reserved temporary an interrupted run's sweep reaps
      $run = bin2hex(random_bytes(8));
      $base = Temporaries::reserve('h6-vault');
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
       * Run two host-shaped worker processes against one shared store record.
       *
       * Modes (where the workers park on `$target`, see the fixture):
       * - `fetch`: both after their first read (a claim winner may never read,
       *   so one waiter alone is released once the other exited);
       * - `evict`: both immediately before the election evict (B reads the
       *   target only once A parked, so A always reaches its election);
       * - `late`: A after its successful evict, released only once B — which
       *   reads the target after A's evict — has run to the end.
       *
       * @param array<string,string> $actions Role => worker action.
       *
       * @return array{
       *    error:null|string,
       *    released:bool,
       *    fast:bool,
       *    Workers:array<string,array<string,mixed>>
       * }
       */
      $Race = static function (
         string $directory,
         string $driver,
         string $endpoint,
         string $cachePrefix,
         array $actions,
         string $input,
         string $target,
         string $mode,
      ) use (
         $secret,
         $vaultPrefix,
         $worker,
         $Clean,
         $Parse,
      ): array {
         $barrier = "{$directory}/barrier";
         $Clean($directory);
         if (@mkdir($barrier, 0700, true) === false) {
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
            // @ Two hosts: each its own storage dir (own Vault lock file)
            foreach ($actions as $role => $action) {
               $command = [
                  PHP_BINARY,
                  $worker,
                  '--worker',
                  BOOTGLY_ROOT_DIR,
                  "{$directory}/host-{$role}",
                  $driver,
                  $endpoint,
                  $cachePrefix,
                  $vaultPrefix,
                  $secret,
                  $action,
                  $input,
                  $target,
                  $barrier,
                  $mode,
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

            // @@ Barrier: release once the mode's meeting point is reached
            $deadline = microtime(true) + 15.0;
            do {
               $ready = [
                  'A' => is_file("{$barrier}/A.ready"),
                  'B' => is_file("{$barrier}/B.ready"),
               ];
               $alive = [];
               foreach ($Processes as $role => $Process) {
                  $Status = proc_get_status($Process);
                  $Statuses[$role] = $Status;
                  $alive[$role] = ($Status['running'] ?? false) === true;
               }

               if ($mode === 'late') {
                  // ? A parked after its evict and B ran to the end
                  if ($ready['A'] && $alive['B'] === false) {
                     $released = true;
                     break;
                  }
                  if ($ready['A'] === false && $alive['A'] === false) {
                     $error = 'late race: worker A exited without parking after its evict';
                     break;
                  }
               }
               else {
                  if ($ready['A'] && $ready['B']) {
                     $released = true;
                     break;
                  }
                  // ? An atomic create() winner can finish without fetch while
                  //   only the loser observes the occupied key. Release that
                  //   one waiter; worker exit/error validation still decides
                  //   whether this was the legitimate fixed path.
                  if (
                     $mode === 'fetch'
                     && ($ready['A'] xor $ready['B'])
                     && ($alive['A'] === false || $alive['B'] === false)
                  ) {
                     $released = true;
                     $fast = true;
                     break;
                  }
                  // ? Every worker must reach the evict barrier
                  if (
                     $mode === 'evict'
                     && (
                        ($ready['A'] === false && $alive['A'] === false)
                        || ($ready['B'] === false && $alive['B'] === false)
                     )
                  ) {
                     $error = 'evict race: a worker exited without reaching the evict barrier';
                     break;
                  }
                  if ($alive['A'] === false && $alive['B'] === false) {
                     $error = 'race workers exited without reaching the barrier';
                     break;
                  }
               }

               usleep(1_000);
            } while (microtime(true) < $deadline);

            if ($released === false && $error === null) {
               $error = "{$mode} race: the workers did not meet the barrier before timeout";
            }
            // ! Released or not, unpark every waiter so nothing outlives the race
            if (file_put_contents("{$barrier}/release", $released ? 'release' : 'abort', LOCK_EX) === false) {
               throw new RuntimeException('race barrier could not be released');
            }

            // @ Bound process completion after release. Pipes are read only
            //   after both writers closed, so a wedged worker cannot park the
            //   native test indefinitely in stream_get_contents()/proc_close().
            $finishDeadline = microtime(true) + 15.0;
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

            // :
            return [
               'error' => $error,
               'released' => $released,
               'fast' => $fast,
               'Workers' => $Workers,
            ];
         }
         catch (Throwable $Throwable) {
            @file_put_contents("{$barrier}/release", 'abort', LOCK_EX);
            foreach ($Processes as $role => $Process) {
               $Status = proc_get_status($Process);
               if ($Status['running'] ?? false) {
                  proc_terminate($Process, SIGKILL);
               }
               $RolePipes = $Pipes[$role] ?? [];
               foreach ($RolePipes as $Pipe) {
                  if (is_resource($Pipe)) {
                     @fclose($Pipe);
                  }
               }
               @proc_close($Process);
            }

            // :
            return [
               'error' => $Throwable::class . ': ' . $Throwable->getMessage(),
               'released' => $released,
               'fast' => $fast,
               'Workers' => [],
            ];
         }
      };

      /**
       * Check one race's process evidence: both workers exited cleanly with
       * their own role/action/mode, two PIDs, two distinct lock files
       * (dev/ino — two host lock domains) and the expected barrier crossings.
       *
       * @param array<string,mixed> $Evidence
       * @param array<string,string> $actions
       */
      $Inspect = static function (array $Evidence, array $actions, string $mode): bool {
         $Workers = $Evidence['Workers'] ?? [];
         $healthy = ($Evidence['error'] ?? null) === null
            && ($Evidence['released'] ?? false) === true
            && is_array($Workers)
            && count($Workers) === 2;

         $PIDs = [];
         $locks = [];
         $crossed = 0;
         foreach ($actions as $role => $action) {
            $Decoded = $Workers[$role]['decoded'] ?? null;
            $healthy = $healthy
               && ($Workers[$role]['exit'] ?? -1) === 0
               && is_array($Decoded)
               && array_key_exists('error', $Decoded)
               && $Decoded['error'] === null
               && ($Decoded['action'] ?? null) === $action
               && ($Decoded['mode'] ?? null) === $mode
               && ($Decoded['role'] ?? null) === $role
               && is_int($Decoded['lock_dev'] ?? null)
               && is_int($Decoded['lock_ino'] ?? null);
            if (is_array($Decoded)) {
               $PIDs[] = $Decoded['pid'] ?? null;
               $crossed += ($Decoded['crossed'] ?? false) === true ? 1 : 0;
               $locks[] = [$Decoded['lock_dev'] ?? null, $Decoded['lock_ino'] ?? null];
            }
         }

         // :
         return $healthy
            && count(array_unique($PIDs, SORT_REGULAR)) === 2
            && count(array_unique($locks, SORT_REGULAR)) === 2
            && ($mode === 'fetch' ? $crossed >= 1 : $crossed === 2);
      };

      /**
       * One store lane: controls, then the single-use race, then rotate||rotate
       * and rotate||revoke in both barrier modes; verdicts after evidence.
       *
       * @param array<string,mixed> $config The shared store's Cache config.
       */
      $Lane = static function (
         string $lane,
         string $driver,
         string $endpoint,
         array $config,
      ) use (
         $base,
         $run,
         $secret,
         $vaultPrefix,
         $Race,
         $Inspect,
      ): Generator {
         $cachePrefix = "h6:{$run}:{$lane}:";
         $Storage = new Cache($config + ['prefix' => $cachePrefix]);
         $Vault = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);
         $Peer = new Vault($Storage, prefix: $vaultPrefix, secret: $secret);
         $Tokens = new Tokens($Vault);

         try {
            $Storage->clear();

            // # Controls — the shared store, shared secret, sequential claim and take.
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

            // # Attack 1 — full JWT inspection through the single-use Usage guard.
            $JTI = "fleet-jti-{$run}-{$lane}";
            $JWT = new JWT($secret);
            $token = $JWT->sign([
               'sub' => 'user-h6',
               'jti' => $JTI,
               'exp' => time() + 120,
            ]);
            $usageLogical = 'jwt:jti:seen:' . hash('sha256', $JTI);
            $usageActions = ['A' => 'usage', 'B' => 'usage'];
            $Usage = $Race(
               "{$base}/{$lane}/usage",
               $driver,
               $endpoint,
               $cachePrefix,
               $usageActions,
               $token,
               $vaultPrefix . hash('sha256', $usageLogical),
               'fetch',
            );
            $fixture = $fixture && $Inspect($Usage, $usageActions, 'fetch');

            // # Attacks 2–5 — refresh rotation through Tokens, one family each:
            //   rotate||rotate (double-submit) and rotate||revoke (logout).
            $Legs = [];
            foreach (['rotate', 'revoke'] as $kind) {
               foreach (['evict', 'late'] as $mode) {
                  $Issued = $Tokens->mint('user-h6', 120, ['scope' => 'h6']);
                  $activeLogical = 'jwt:refresh:active:' . hash('sha256', $Issued->refresh);
                  $actions = ['A' => 'rotate', 'B' => $kind];
                  $Evidence = $Race(
                     "{$base}/{$lane}/{$kind}-{$mode}",
                     $driver,
                     $endpoint,
                     $cachePrefix,
                     $actions,
                     $Issued->refresh,
                     $vaultPrefix . hash('sha256', $activeLogical),
                     $mode,
                  );
                  $fixture = $fixture && $Inspect($Evidence, $actions, $mode);

                  $Legs["{$kind}-{$mode}"] = [
                     'kind' => $kind,
                     'mode' => $mode,
                     'family' => $Issued->family,
                     'refresh' => $Issued->refresh,
                     'Evidence' => $Evidence,
                  ];
               }
            }

            // @ Verdicts — after every race, so one failure carries them all
            $Results = static fn (array $Evidence): array => [
               'A' => $Evidence['Workers']['A']['decoded']['result'] ?? null,
               'B' => $Evidence['Workers']['B']['decoded']['result'] ?? null,
            ];

            $UsageResults = $Results($Usage);
            $accepted = 0;
            $replayed = 0;
            foreach ($UsageResults as $result) {
               $accepted += is_array($result) && ($result['kind'] ?? null) === 'accepted' ? 1 : 0;
               $replayed += is_array($result)
                  && ($result['kind'] ?? null) === 'failure'
                  && ($result['failure'] ?? null) === Failures::Replay->name ? 1 : 0;
            }
            $verdicts = [
               'usage' => $accepted === 1 && $replayed === 1,
            ];

            $Facts = [];
            foreach ($Legs as $name => $Leg) {
               $LegResults = $Results($Leg['Evidence']);
               $rotation = $LegResults['A'];
               $other = $LegResults['B'];
               $Rotations = $Leg['kind'] === 'rotate' ? [$rotation, $other] : [$rotation];

               $tokens = 0;
               $nulls = 0;
               $replays = 0;
               $successors = [];
               $families = true;
               foreach ($Rotations as $result) {
                  $kind = is_array($result) ? ($result['kind'] ?? null) : null;
                  $tokens += $kind === 'token' ? 1 : 0;
                  $nulls += $kind === 'null' ? 1 : 0;
                  $replays += $kind === 'replay' ? 1 : 0;
                  if ($kind === 'token') {
                     $refresh = $result['refresh'] ?? null;
                     $successors[] = is_string($refresh) && $refresh !== ''
                        ? $Tokens->check($refresh)
                        : 'missing';
                  }
                  if ($kind === 'token' || $kind === 'replay') {
                     $families = $families && ($result['family'] ?? null) === $Leg['family'];
                  }
               }

               // ! Through a peer Vault: the revocation every host reads
               $marker = $Peer->read('jwt:refresh:family:' . hash('sha256', $Leg['family'])) !== null;
               $original = $Tokens->check($Leg['refresh']);

               $Facts[$name] = [
                  'results' => $LegResults,
                  'tokens' => $tokens,
                  'nulls' => $nulls,
                  'replays' => $replays,
                  'successors_active' => $successors,
                  'families_match' => $families,
                  'marker' => $marker,
                  'original_active' => $original,
               ];

               $dead = in_array(true, $successors, true) === false
                  && in_array('missing', $successors, true) === false;
               // ! late: A won the election, then resumed only after B revoked
               //   the family — its re-check fails closed (no Token at all)
               $closed = $Leg['mode'] !== 'late'
                  || (is_array($rotation) && ($rotation['kind'] ?? null) === 'null');
               $Facts[$name]['winner_closed'] = $closed;
               $verdicts[$name] = $Leg['kind'] === 'rotate'
                  // # rotate||rotate: one replay, one winner, no live successor
                  ? $closed
                     && $replays === 1
                     && $tokens + $nulls === 1
                     && $dead
                     && $families
                     && $marker
                     && $original === false
                  // # rotate||revoke: the logout wins, no live successor
                  : $closed
                     && is_array($other)
                     && ($other['kind'] ?? null) === 'true'
                     && $tokens + $nulls + $replays === 1
                     && $dead
                     && $families
                     && $marker
                     && $original === false;
            }

            $evidence = json_encode([
               'lane' => $lane,
               'control' => $control,
               'verdicts' => $verdicts,
               'facts' => $Facts,
               'usage' => $Usage,
               'legs' => $Legs,
            ], JSON_UNESCAPED_SLASHES);

            // : Fixture and controls first: a broken harness is not a verdict
            yield assert(
               assertion: $fixture,
               description: "H6 {$lane} fleet fixture and controls must be healthy before the security verdict: {$evidence}"
            );

            $summary = json_encode(['lane' => $lane, 'verdicts' => $verdicts, 'facts' => $Facts], JSON_UNESCAPED_SLASHES);

            yield assert(
               assertion: $verdicts['usage'],
               description: $verdicts['usage']
                  ? "{$lane}: a single-use JWT is accepted on one host and a Replay on the other"
                  : "{$lane}: single-use JWT admitted duplicate fleet winners; {$summary}"
            );
            yield assert(
               assertion: $verdicts['rotate-evict'],
               description: $verdicts['rotate-evict']
                  ? "{$lane}: a double-submitted refresh racing to the evict yields one Replay and no live successor"
                  : "{$lane}: rotate||rotate at the evict lost theft detection or kept a live successor; {$summary}"
            );
            yield assert(
               assertion: $verdicts['rotate-late'],
               description: $verdicts['rotate-late']
                  ? "{$lane}: a refresh replayed after the winner's evict is a Replay, and the winner fails closed with no successor"
                  : "{$lane}: rotate||rotate after the evict lost theft detection or kept a live successor; {$summary}"
            );
            yield assert(
               assertion: $verdicts['revoke-evict'],
               description: $verdicts['revoke-evict']
                  ? "{$lane}: a logout racing a rotation to the evict returns true and leaves no live successor"
                  : "{$lane}: rotate||revoke at the evict failed the logout or kept a live successor; {$summary}"
            );
            yield assert(
               assertion: $verdicts['revoke-late'],
               description: $verdicts['revoke-late']
                  ? "{$lane}: a logout after the rotation's evict returns true, and the rotation fails closed with no successor"
                  : "{$lane}: rotate||revoke after the evict failed the logout or kept a live successor; {$summary}"
            );
         }
         finally {
            try {
               $Storage->clear();
            }
            catch (Throwable) {
            }
         }
      };

      try {
         // @ File lane — default: one store directory both hosts reach
         $store = "{$base}/file/store";
         if (@mkdir($store, 0700, true) === false) {
            throw new RuntimeException("file lane store could not be created: {$store}");
         }
         yield from $Lane('file', 'file', $store, [
            'driver' => 'file',
            'path' => $store,
         ]);

         // @ Redis lane — opt-in (BOOTGLY_H6_E2E=1 + REDIS_HOST)
         if ($optin) {
            yield assert(
               assertion: $reachable,
               description: "H6 Redis lane opt-in requires the configured endpoint {$host}:{$port}"
            );

            yield from $Lane('redis', 'redis', "{$host}:{$port}", [
               'driver' => 'redis',
               'host' => $host,
               'port' => $port,
               'timeout' => 2.0,
            ]);
         }
      }
      finally {
         // ! Every run — green, red or refused — leaves no temp tree behind
         $Clean($base);
      }
   }
);
