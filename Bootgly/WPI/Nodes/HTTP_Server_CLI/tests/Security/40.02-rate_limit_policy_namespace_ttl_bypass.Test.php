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
 * Security PoC M2 (HTTP_Server_CLI audit 2026-08-02) — independent
 * RateLimit policies must not share a principal counter or its TTL.
 *
 * The strict-policy control proves that three attempts pass and the fourth is
 * rejected inside its 60-second window. The attack primes the same principal
 * through a permissive one-second policy. On vulnerable code, both policies
 * use `ratelimit:<principal>`, so the short policy owns the shared record's TTL
 * and can recreate it before the strict window ends. Four strict attempts then
 * reach the protected handler instead of only three.
 *
 * Separate Cache objects sharing one isolated segment model two independently
 * constructed default policies without leaving a persistent SysV artifact.
 */
$segment = random_int(10_000_000, 2_000_000_000);
$cacheConfig = [
   'driver' => 'shared',
   'prefix' => 'ratelimit:',
   'segment' => $segment,
   'size' => 262_144,
];

$ShortCache = new Cache($cacheConfig);
$StrictCache = new Cache($cacheConfig);

$ShortLimit = new RateLimit(
   limit: 100,
   window: 1,
   algorithm: Algorithms::Fixed,
   key: static function (object $Request): string {
      $policy = $Request->Header->get('X-M2-Policy');
      $principal = $Request->Header->get('X-M2-Key') ?? 'missing';
      $requestID = $Request->Header->get('X-M2-Request') ?? 'missing';

      return $policy === 'short'
         ? $principal
         : "m2-short-control:{$requestID}";
   },
   Cache: $ShortCache,
);

$StrictLimit = new RateLimit(
   limit: 3,
   window: 60,
   algorithm: Algorithms::Fixed,
   key: static function (object $Request): string {
      $policy = $Request->Header->get('X-M2-Policy');
      $principal = $Request->Header->get('X-M2-Key') ?? 'missing';
      $requestID = $Request->Header->get('X-M2-Request') ?? 'missing';

      return $policy === 'strict'
         ? $principal
         : "m2-strict-control:{$requestID}";
   },
   Cache: $StrictCache,
);

$Request = static function (
   string $policy,
   string $principal,
   string $requestID,
   int $delay = 0
): Closure {
   return static function () use ($policy, $principal, $requestID, $delay): string {
      if ($delay > 0) {
         sleep($delay);
      }

      return "GET /m2/{$policy} HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-M2-Policy: {$policy}\r\n"
         . "X-M2-Key: {$principal}\r\n"
         . "X-M2-Request: {$requestID}\r\n\r\n";
   };
};

return new Test(
   description: 'RateLimit policies must isolate principal counters and TTLs',
   Separator: new Separator(line: true),
   skip: extension_loaded('sysvshm') === false || extension_loaded('sysvsem') === false,

   requests: [
      // @ Positive control: the strict policy blocks attempt four.
      $Request('strict', 'm2-control', 'control-1'),
      $Request('strict', 'm2-control', 'control-2'),
      $Request('strict', 'm2-control', 'control-3'),
      $Request('strict', 'm2-control', 'control-4'),

      // ! Attack: the short policy creates the strict principal with TTL=1.
      $Request('short', 'm2-attack', 'prime-1'),
      $Request('strict', 'm2-attack', 'attack-1'),
      $Request('strict', 'm2-attack', 'attack-2'),
      $Request('strict', 'm2-attack', 'attack-3'),
      // @ Expire only the short policy's window, still well inside 60 seconds.
      $Request('short', 'm2-attack', 'prime-2', delay: 2),
      $Request('strict', 'm2-attack', 'attack-4'),
      $Request('strict', 'm2-attack', 'attack-5'),
   ],

   middlewares: [$ShortLimit, $StrictLimit],

   response: static function (Request $Request, Response $Response): Response {
      $policy = $Request->Header->get('X-M2-Policy') ?? 'missing';
      $principal = $Request->Header->get('X-M2-Key') ?? 'missing';

      return $Response(body: "M2-PROTECTED-HANDLER:{$policy}:{$principal}");
   },

   test: static function (array $responses) use ($ShortCache): bool|string {
      try {
         if (count($responses) !== 11) {
            return 'M2 fixture failed: expected eleven live RateLimit responses, got '
               . count($responses) . '.';
         }

         $Status = static function (string $response): int {
            return preg_match('#^HTTP/1\.1 (\d{3}) #', $response, $matches) === 1
               ? (int) $matches[1]
               : 0;
         };

         $controlCodes = array_map($Status, array_slice($responses, 0, 4));
         if ($controlCodes !== [200, 200, 200, 429]) {
            Vars::$labels = ['M2 strict-policy positive control responses'];
            dump(json_encode(array_slice($responses, 0, 4)));

            return 'M2 control failed: the isolated strict 3/60 policy did not block attempt four; '
               . 'observed ' . json_encode($controlCodes) . '.';
         }

         foreach (array_slice($responses, 0, 3) as $response) {
            if (str_contains($response, 'M2-PROTECTED-HANDLER:strict:m2-control') === false) {
               return 'M2 control failed: an allowed strict request did not reach the protected handler.';
            }
         }
         if (str_contains($responses[3], 'M2-PROTECTED-HANDLER:') === true) {
            return 'M2 control failed: the rejected strict request still reached the protected handler.';
         }

         foreach ([4, 8] as $index) {
            if (
               $Status($responses[$index]) !== 200
               || str_contains($responses[$index], 'M2-PROTECTED-HANDLER:short:m2-attack') === false
            ) {
               Vars::$labels = ["M2 short-policy prime response {$index}"];
               dump(json_encode($responses[$index]));

               return 'M2 fixture failed: a permissive short-policy prime did not reach the handler.';
            }
         }

         $attackResponses = [
            $responses[5],
            $responses[6],
            $responses[7],
            $responses[9],
            $responses[10],
         ];
         $attackCodes = array_map($Status, $attackResponses);

         if ($attackCodes === [200, 200, 429, 200, 200]) {
            foreach ([0, 1, 3, 4] as $index) {
               if (
                  str_contains(
                     $attackResponses[$index],
                     'M2-PROTECTED-HANDLER:strict:m2-attack'
                  ) === false
               ) {
                  return 'M2 fixture failed: a purported bypass response did not reach the protected handler.';
               }
            }

            Vars::$labels = ['M2 cross-policy TTL bypass responses'];
            dump(json_encode($attackResponses));

            return 'CONFIRMED M2: a permissive 1-second RateLimit policy recreated the shared '
               . 'principal counter inside the strict 60-second window, allowing four strict '
               . 'requests to reach the protected handler; observed statuses '
               . json_encode($attackCodes) . '.';
         }

         // @ Retained regression after remediation: the short policy no longer
         //   affects the strict counter, which allows exactly its first three.
         if ($attackCodes === [200, 200, 200, 429, 429]) {
            foreach ([0, 1, 2] as $index) {
               if (
                  str_contains(
                     $attackResponses[$index],
                     'M2-PROTECTED-HANDLER:strict:m2-attack'
                  ) === false
               ) {
                  return 'M2 regression failed: an allowed strict request did not reach the handler.';
               }
            }
            foreach ([3, 4] as $index) {
               if (str_contains($attackResponses[$index], 'M2-PROTECTED-HANDLER:') === true) {
                  return 'M2 regression failed: a rejected strict request reached the handler.';
               }
            }

            return true;
         }

         Vars::$labels = ['M2 unexpected cross-policy responses'];
         dump(json_encode($attackResponses));

         return 'M2 fixture failed: neither vulnerable nor isolated policy behavior was observed; '
            . 'statuses were ' . json_encode($attackCodes) . '.';
      }
      finally {
         // @ Attach from the test process and remove the isolated SysV pair
         //   created by the worker so validation leaves no IPC artifact.
         $ShortCache->fetch('__m2_cleanup__');
         $Driver = $ShortCache->Driver;
         if ($Driver instanceof Shared) {
            $Driver->destroy();
         }
      }
   },
);
