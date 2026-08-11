<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Resources\Cache;
use Bootgly\ABI\Resources\Cache\Drivers\Shared;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit\Algorithms;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M3 (HTTP_Server_CLI audit 2026-08-02) — expired
 * RateLimit records must release fixed Shared-cache capacity automatically.
 *
 * A live HTTP RateLimit policy receives enough distinct, attacker-controlled
 * principals to saturate a deliberately small isolated SysV segment. After
 * every admitted record expires, a fresh principal must reach the protected
 * handler without an operator calling purge(). On vulnerable code, expiry is
 * only a logical miss: the records still occupy the segment and the request
 * receives a 500. A repeated recovery principal proves that a secure 200 came
 * from a persisted counter rather than fail-open handling. A following
 * explicit purge and successful request are the control proving that stale
 * cache allocation, not a dead worker or fixture, caused the failure.
 */
$segment = random_int(10_000_000, 2_000_000_000);
$TTL = 5;
$fillCount = 40;
$purged = null;

$Cache = new Cache([
   'driver' => 'shared',
   'prefix' => 'ratelimit:',
   'segment' => $segment,
   'size' => 32_768,
]);

$Limit = new RateLimit(
   limit: 1,
   window: $TTL,
   algorithm: Algorithms::Fixed,
   key: static function (object $Request): string {
      return $Request->Header->get('X-M3-Key') ?? 'missing';
   },
   Cache: $Cache,
   scope: 'security-m3-expired-shared-capacity',
);

$Build = static function (
   string $phase,
   string $key,
   int $delay = 0,
   bool $purge = false,
) use ($Cache, &$purged): Closure {
   return static function () use (
      $Cache,
      &$purged,
      $phase,
      $key,
      $delay,
      $purge,
   ): string {
      if ($delay > 0) {
         sleep($delay);
      }
      if ($purge === true) {
         $purged = $Cache->purge();
      }

      return "GET /m3/{$phase} HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-M3-Phase: {$phase}\r\n"
         . "X-M3-Key: {$key}\r\n\r\n";
   };
};

$Requests = [
   // @ Positive control: the live limiter rejects a repeated principal.
   $Build('control-allowed', 'm3-control'),
   $Build('control-rejected', 'm3-control'),
];

for ($index = 0; $index < $fillCount; $index++) {
   $key = str_pad("m3-fill-{$index}-", 1_024, 'k');
   $Requests[] = $Build("fill-{$index}", $key);
}

// ! This request must recover automatically after every admitted fill record
//   expires. It is the secure assertion and fails with M3 on vulnerable code.
$Requests[] = $Build(
   'automatic-recovery',
   str_pad('m3-automatic-recovery-', 1_024, 'a'),
   delay: $TTL + 1,
);
// @ A real recovery persisted the first request and must reject this repeat.
$Requests[] = $Build(
   'automatic-recovery-repeat',
   str_pad('m3-automatic-recovery-', 1_024, 'a'),
);
// @ Control: explicit reclamation must restore the same source-to-sink path.
$Requests[] = $Build(
   'manual-recovery',
   str_pad('m3-manual-recovery-', 1_024, 'm'),
   purge: true,
);

return new Test(
   description: 'Expired RateLimit records must release Shared-cache capacity automatically',
   Separator: new Separator(line: true),
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,

   requests: $Requests,
   middlewares: [$Limit],

   response: static function (Request $Request, Response $Response): Response {
      $phase = $Request->Header->get('X-M3-Phase') ?? 'missing';

      return $Response(body: "M3-PROTECTED-HANDLER:{$phase}");
   },

   test: static function (array $responses) use (
      $Cache,
      &$purged,
      $fillCount,
   ): bool|string {
      try {
         $expected = $fillCount + 5;
         if (count($responses) !== $expected) {
            return "M3 fixture failed: expected {$expected} live responses, got "
               . count($responses) . '.';
         }

         $Status = static function (string $response): int {
            return preg_match('#^HTTP/1\.1 (\d{3}) #', $response, $matches) === 1
               ? (int) $matches[1]
               : 0;
         };

         $controlCodes = array_map($Status, array_slice($responses, 0, 2));
         if ($controlCodes !== [200, 429]) {
            Vars::$labels = ['M3 RateLimit positive control responses'];
            dump(json_encode(array_slice($responses, 0, 2)));

            return 'M3 control failed: the live one-per-window policy did not allow then reject '
               . 'the repeated principal; observed ' . json_encode($controlCodes) . '.';
         }
         if (
            str_contains($responses[0], 'M3-PROTECTED-HANDLER:control-allowed') === false
            || str_contains($responses[1], 'M3-PROTECTED-HANDLER:') === true
         ) {
            return 'M3 control failed: RateLimit admission did not guard the protected handler.';
         }

         $fillResponses = array_slice($responses, 2, $fillCount);
         $fillCodes = array_map($Status, $fillResponses);
         $admitted = count(array_filter(
            $fillCodes,
            static fn (int $code): bool => $code === 200,
         ));
         $capacityFailures = count(array_filter(
            $fillCodes,
            static fn (int $code): bool => $code === 500,
         ));
         if ($admitted === 0 || $capacityFailures === 0) {
            Vars::$labels = ['M3 Shared-cache saturation responses'];
            dump(json_encode($fillCodes));

            return 'M3 fixture failed: the isolated segment did not show both successful admission '
               . "and capacity failure; admitted={$admitted}, failures={$capacityFailures}.";
         }

         $automaticIndex = 2 + $fillCount;
         $manualIndex = $automaticIndex + 2;
         $automaticCodes = [
            $Status($responses[$automaticIndex]),
            $Status($responses[$automaticIndex + 1]),
         ];
         $manualCode = $Status($responses[$manualIndex]);

         if (
            $manualCode !== 200
            || str_contains(
               $responses[$manualIndex],
               'M3-PROTECTED-HANDLER:manual-recovery',
            ) === false
         ) {
            Vars::$labels = ['M3 explicit-purge recovery response'];
            dump(json_encode($responses[$manualIndex]));

            return 'M3 control failed: explicit expiry purge did not restore the protected path; '
               . "purged=" . json_encode($purged) . ", status={$manualCode}.";
         }

         if ($automaticCodes === [500, 500]) {
            if (
               str_contains(
                  $responses[$automaticIndex],
                  'M3-PROTECTED-HANDLER:',
               ) === true
               || is_int($purged) === false
               || $purged < 1
            ) {
               return 'M3 fixture failed: the apparent capacity failure lacked the protected-handler '
                  . 'or explicit-reclamation controls.';
            }

            Vars::$labels = ['M3 expired Shared-cache capacity evidence'];
            dump(json_encode([
               'admitted_before_capacity' => $admitted,
               'capacity_failures_before_expiry' => $capacityFailures,
               'automatic_recovery_statuses_after_ttl' => $automaticCodes,
               'expired_records_removed_by_explicit_purge' => $purged,
               'manual_recovery_status' => $manualCode,
            ]));

            return 'CONFIRMED M3: expired RateLimit records remained allocated after their TTL, '
               . 'so a fresh principal still received HTTP 500; explicit purge removed '
               . "{$purged} expired records and restored HTTP 200.";
         }

         if (
            $automaticCodes === [200, 429]
            && str_contains(
               $responses[$automaticIndex],
               'M3-PROTECTED-HANDLER:automatic-recovery',
            ) === true
            && str_contains(
               $responses[$automaticIndex + 1],
               'M3-PROTECTED-HANDLER:',
            ) === false
         ) {
            return true;
         }

         Vars::$labels = ['M3 unexpected automatic-recovery responses'];
         dump(json_encode(array_slice($responses, $automaticIndex, 2)));

         return 'M3 fixture failed: automatic recovery produced neither vulnerable [500,500] '
            . 'nor secure persisted [200,429] behavior; statuses='
            . json_encode($automaticCodes) . '.';
      }
      finally {
         // @ Attach from the test process and remove the isolated SysV pair
         //   created by the worker so validation leaves no IPC artifact.
         $Cache->fetch('__m3_cleanup__');
         $Driver = $Cache->Driver;
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }
   },
);
