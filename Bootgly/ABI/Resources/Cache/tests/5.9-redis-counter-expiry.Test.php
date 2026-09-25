<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Suite\Test;


/**
 * A Redis counter with a TTL is created WITH its expiry, in one atomic step.
 *
 * `increment()` used to send `INCRBY` and then a separate `EXPIRE`: anything that stopped
 * the second command — a dropped connection, an ACL denying EXPIRE, an expiry the server
 * refuses — left the counter without expiry forever, and a RateLimit key then answered
 * 429 for good (H6C-6). A counter reused after reaching 0 re-armed its live window, and
 * on ext-redis an error reply came back as a count of 0 — "under every limit". One EVAL
 * now runs `SET … EX … NX` then `INCRBY`, and a refused increment raises.
 *
 * Both transports are driven in child processes (the driver prefers ext-redis whenever
 * it is loaded; the native run clears the ini scan directory). The stub legs S and E need
 * no server; the live legs run against REDIS_HOST/REDIS_PORT when a Redis answers there.
 */
$PHP = PHP_BINARY;
$host = getenv('REDIS_HOST') !== false ? (string) getenv('REDIS_HOST') : '127.0.0.1';
$port = getenv('REDIS_PORT') !== false ? (int) getenv('REDIS_PORT') : 6379;

return new Test(
   description: 'Cache(Redis): a counter with a TTL is created with its expiry in one step, and a refused increment raises',
   skip: DIRECTORY_SEPARATOR === '\\'
      || function_exists('shell_exec') === false
      || function_exists('pcntl_fork') === false,
   test: function () use ($PHP, $host, $port) {
      $script = __DIR__ . '/redis-counter.php';
      $run = static function (string $environment) use ($PHP, $script, $host, $port): mixed {
         $output = @shell_exec(
            $environment . escapeshellarg($PHP)
               . ' -r ' . escapeshellarg('require $_SERVER["argv"][1] ?? "";')
               . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($host) . ' ' . (int) $port . ' 2>/dev/null'
         );

         return json_decode(trim((string) $output), true);
      };

      $lanes = [];
      $lanes[] = $run('');
      $lanes[] = $run('PHP_INI_SCAN_DIR= ');

      $seen = [];
      foreach ($lanes as $observed) {
         yield assert(
            assertion: is_array($observed) && isset($observed['lane']),
            description: 'The counter probe produced no readable result: ' . var_export($observed, true)
         );
         if (is_array($observed) === false || isset($observed['lane']) === false) {
            continue;
         }
         $lane = (string) $observed['lane'];
         // ? The second run still had ext-redis: the native lane is unreachable here
         if (isset($seen[$lane]) === true) {
            continue;
         }
         $seen[$lane] = true;
         if (isset($observed['skip']) === true) {
            yield (new Assertion(description: "Counter probe not driven on the {$lane} transport: " . (string) $observed['skip']))->skip();
            continue;
         }
         $legs = (array) $observed['legs'];

         // @ Stub server — no Redis needed
         yield assert(
            assertion: ($legs['S'] ?? false) === true,
            description: "[{$lane}] a counter with a TTL is ONE command for the whole call — EVAL with the key, the step and the TTL, its script creating the counter with SET … EX … NX before INCRBY and answering GET"
         );
         yield assert(
            assertion: ($legs['E'] ?? false) === true,
            description: "[{$lane}] an error reply raises — it is never answered as a count of 0 (a rate limiter would fail open)"
         );

         // ? The live legs need a reachable Redis — shown as skipped, never passed
         if (($observed['live'] ?? false) !== true) {
            yield (new Assertion(description: "[{$lane}] live legs A, O, R, P, Z, N, C: no Redis answers at {$host}:{$port} (REDIS_HOST/REDIS_PORT)"))->skip();
            continue;
         }

         // @ Live Redis
         yield assert(
            assertion: ($legs['A'] ?? false) === true,
            description: "[{$lane}] a connection cut right after the counter's command leaves it counted, with its expiry"
         );
         yield assert(
            assertion: ($legs['O'] ?? false) === true,
            description: "[{$lane}] an expiry the server refuses raises and leaves no count behind"
         );
         yield assert(
            assertion: ($legs['R'] ?? false) === true,
            description: "[{$lane}] a live window is never re-armed — not after a decrement back to 0, not by +0"
         );
         yield assert(
            assertion: ($legs['P'] ?? false) === true,
            description: "[{$lane}] the count comes back exact beyond 2^53"
         );
         yield assert(
            assertion: ($legs['Z'] ?? false) === true,
            description: "[{$lane}] a TTL of 0 or less keeps a plain counter without expiry"
         );
         yield assert(
            assertion: ($legs['N'] ?? false) === true,
            description: "[{$lane}] a value that is not a counter raises, with and without a TTL"
         );
         yield assert(
            assertion: ($legs['C'] ?? false) === true,
            description: "[{$lane}] a counter stored without expiry keeps none — the TTL arms creation only"
         );
      }

      // ? A transport this machine cannot reach is shown as skipped, never passed in silence
      foreach (['ext', 'native'] as $lane) {
         if (isset($seen[$lane]) === false) {
            yield (new Assertion(
               description: "The {$lane} transport was not driven here (ext-redis is "
                  . ($lane === 'ext' ? 'not loaded' : 'still loaded with PHP_INI_SCAN_DIR cleared') . ')'
            ))->skip();
         }
      }
   }
);
