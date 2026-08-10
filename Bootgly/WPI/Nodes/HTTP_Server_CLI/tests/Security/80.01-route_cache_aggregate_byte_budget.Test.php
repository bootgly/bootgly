<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC M6 — route-cache storage needs an aggregate per-worker byte
 * budget in addition to its entry-count and per-entry limits.
 *
 * The real HTTP path primes one cache-enabled GET route with three distinct
 * query targets. Routing matches the same path, while Cache::compose() keys the
 * complete target, so all three responses can remain resident. A repeated
 * first target proves that the cache is actually active.
 *
 * The 10 KiB probe cap is deliberately tiny and applies only while this case
 * runs. Every individual response is well below it, while all three together
 * exceed it. On the vulnerable implementation no aggregate-cap property
 * exists, so the bounded probe demonstrates the missing admission boundary
 * without approaching the theoretical 512 MiB worker ceiling.
 */
$probeCap = 10 * 1024;
$bodySize = 4 * 1024;
$payload = str_repeat('M', $bodySize);
$handlers = 0;
$dateHandlers = 0;
$capName = null;
$capOriginal = null;
$capConfigured = false;
$capError = null;

$capCandidates = [
   'maxBytes',
   'maxSize',
   'maxWireBytes',
   'maxWireSize',
   'maxWorkerBytes',
   'maxWorkerSize',
   'maxWorkerWireBytes',
   'maxWorkerWireSize',
];
$counterCandidates = [
   'bytes',
   'size',
   'wireBytes',
   'totalBytes',
   'totalWireBytes',
];

$Requests = [
   static fn (): string =>
      "GET /m6/setup HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/cache?slot=1 HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/cache?slot=1 HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/cache?slot=2 HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/cache?slot=3 HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/cache?slot=1 HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/report HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/lifecycle HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static fn (): string =>
      "GET /m6/date-body HTTP/1.1\r\nHost: localhost\r\n\r\n",
   static function (): string {
      usleep(1_100_000);

      return "GET /m6/date-body HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   static fn (): string =>
      "GET /m6/cleanup HTTP/1.1\r\nHost: localhost\r\n\r\n",
];

return new Test(
   description: 'Route cache must enforce an aggregate per-worker byte budget',
   Separator: new Separator(line: true),

   requests: $Requests,

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use (
      $payload,
      $probeCap,
      $capCandidates,
      $counterCandidates,
      &$handlers,
      &$dateHandlers,
      &$capName,
      &$capOriginal,
      &$capConfigured,
      &$capError,
   ) {
      yield $Router->route('/m6/setup', static function (
         Request $Request,
         Response $Response,
      ) use (
         $probeCap,
         $capCandidates,
         &$capName,
         &$capOriginal,
         &$capConfigured,
         &$capError,
      ): Response {
         Cache::flush();

         foreach ($capCandidates as $candidate) {
            if (! property_exists(Cache::class, $candidate)) {
               continue;
            }

            try {
               $Cap = new ReflectionProperty(Cache::class, $candidate);
               if (! $Cap->isStatic() || ! $Cap->isPublic() || $Cap->isReadOnly()) {
                  continue;
               }

               $value = $Cap->getValue();
               if (! is_int($value)) {
                  continue;
               }

               $capName = $candidate;
               $capOriginal = $value;
               $Cap->setValue(null, $probeCap);
               $capConfigured = $Cap->getValue() === $probeCap;
               break;
            }
            catch (Throwable $Error) {
               $capError = $Error->getMessage();
            }
         }

         return $Response->JSON->send([
            'probe_cap' => $probeCap,
            'budget_property' => $capName,
            'budget_configured' => $capConfigured,
            'budget_error' => $capError,
            'entries_limit' => Cache::ENTRIES_LIMIT,
            'wire_limit' => Cache::WIRE_LIMIT,
            'configured_wire_product' =>
               Cache::ENTRIES_LIMIT * Cache::WIRE_LIMIT,
         ]);
      }, GET);

      yield $Router->route('/m6/cache', static function (
         Request $Request,
         Response $Response,
      ) use ($payload, &$handlers): Response {
         $handlers++;
         $slot = $Request->query('slot');

         return $Response(
            body: "M6-HANDLER:{$handlers};QUERY:{$slot};{$payload}"
         );
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m6/report', static function (
         Request $Request,
         Response $Response,
      ) use (
         $probeCap,
         $counterCandidates,
         &$handlers,
         &$capName,
         &$capConfigured,
      ): Response {
         $entryLengths = [];
         foreach (Cache::$entries as $key => $entry) {
            $entryLengths[$key] = strlen($entry[0]);
         }

         $counterName = null;
         $counterValue = null;
         foreach ($counterCandidates as $candidate) {
            if (! property_exists(Cache::class, $candidate)) {
               continue;
            }

            try {
               $Counter = new ReflectionProperty(Cache::class, $candidate);
               if (! $Counter->isStatic()) {
                  continue;
               }

               $value = $Counter->getValue();
               if (! is_int($value)) {
                  continue;
               }

               $counterName = $candidate;
               $counterValue = $value;
               break;
            }
            catch (Throwable) {
               continue;
            }
         }

         return $Response->JSON->send([
            'probe_cap' => $probeCap,
            'budget_property' => $capName,
            'budget_configured' => $capConfigured,
            'counter_property' => $counterName,
            'counter_value' => $counterValue,
            'handler_runs' => $handlers,
            'entries' => count(Cache::$entries),
            'entry_lengths' => $entryLengths,
            'retained_wire_bytes' => array_sum($entryLengths),
            'configured_wire_product' =>
               Cache::ENTRIES_LIMIT * Cache::WIRE_LIMIT,
         ]);
      }, GET);

      yield $Router->route('/m6/lifecycle', static function (
         Request $Request,
         Response $Response,
      ) use ($probeCap): Response {
         $matrix = [];

         try {
            // # A non-positive cap disables storage, including empty wire.
            Cache::flush();
            Cache::$maxBytes = 0;
            Cache::store('zero', '', 60, '/m6/zero');
            $matrix['zero'] = [
               'entries' => count(Cache::$entries),
               'bytes' => Cache::$bytes,
               'URIs' => count(Cache::$URIs),
            ];

            // # Exact aggregate fit, replacement delta, atomic rejection,
            //   and byte-pressure FIFO.
            Cache::flush();
            Cache::$maxBytes = 10;
            Cache::store('a', 'AAAAAA', 60, '/m6/a');
            Cache::store('b', 'BBBB', 60, '/m6/b');
            $matrix['exact'] = [
               'keys' => array_keys(Cache::$entries),
               'bytes' => Cache::$bytes,
            ];

            Cache::store('a', 'AAA', 60, '/m6/a');
            $matrix['shrink'] = [
               'keys' => array_keys(Cache::$entries),
               'wire' => Cache::$entries['a'][0] ?? null,
               'bytes' => Cache::$bytes,
            ];

            Cache::store('a', 'AAAAAAA', 60, '/m6/a');
            $matrix['grow'] = [
               'keys' => array_keys(Cache::$entries),
               'wire' => Cache::$entries['a'][0] ?? null,
               'bytes' => Cache::$bytes,
            ];

            Cache::store('a', 'XXXXXXXXXXX', 60, '/m6/rejected');
            $matrix['aggregate_reject'] = [
               'keys' => array_keys(Cache::$entries),
               'wire' => Cache::$entries['a'][0] ?? null,
               'bytes' => Cache::$bytes,
               'rejected_URI' => isset(Cache::$URIs['/m6/rejected']),
            ];

            Cache::store('c', 'CCC', 60, '/m6/c');
            Cache::store('d', 'D', 60, '/m6/d');
            $matrix['byte_fifo'] = [
               'keys' => array_keys(Cache::$entries),
               'bytes' => Cache::$bytes,
            ];

            $beforeTTL = [
               'keys' => array_keys(Cache::$entries),
               'bytes' => Cache::$bytes,
               'URIs' => Cache::$URIs,
            ];
            Cache::store('ttl', 'T', 0, '/m6/ttl-rejected');
            $matrix['TTL_reject'] = [
               'unchanged' => $beforeTTL === [
                  'keys' => array_keys(Cache::$entries),
                  'bytes' => Cache::$bytes,
                  'URIs' => Cache::$URIs,
               ],
               'rejected_URI' => isset(Cache::$URIs['/m6/ttl-rejected']),
            ];

            // # Lazy expiry releases the exact stored length.
            Cache::flush();
            Cache::$maxBytes = 10;
            Cache::store('expired', 'EXP', 60, '/m6/expired');
            Cache::$entries['expired'][1] = time() - 1;
            $expired = Cache::fetch('expired');
            $matrix['expiry'] = [
               'result' => $expired,
               'entries' => count(Cache::$entries),
               'bytes' => Cache::$bytes,
            ];

            // # The independent entry-count FIFO remains exact.
            Cache::flush();
            Cache::$maxBytes = Cache::ENTRIES_LIMIT + 1;
            for ($index = 0; $index <= Cache::ENTRIES_LIMIT; $index++) {
               Cache::store(
                  "count-{$index}",
                  'C',
                  60,
                  "/m6/count/{$index}"
               );
            }
            $matrix['count_fifo'] = [
               'entries' => count(Cache::$entries),
               'bytes' => Cache::$bytes,
               'first_present' => isset(Cache::$entries['count-0']),
               'last_present' =>
                  isset(Cache::$entries['count-' . Cache::ENTRIES_LIMIT]),
            ];

            // # The per-entry ceiling remains atomic at exact and +1.
            Cache::flush();
            Cache::$maxBytes = Cache::WIRE_LIMIT;
            $exactWire = str_repeat('W', Cache::WIRE_LIMIT);
            Cache::store('wire', $exactWire, 60, '/m6/wire');
            Cache::store(
               'wire',
               $exactWire . 'X',
               60,
               '/m6/wire-rejected'
            );
            $matrix['wire_limit'] = [
               'entries' => count(Cache::$entries),
               'length' => strlen(Cache::$entries['wire'][0] ?? ''),
               'bytes' => Cache::$bytes,
               'rejected_URI' =>
                  isset(Cache::$URIs['/m6/wire-rejected']),
            ];
            unset($exactWire);

            // # Informational + final response bytes draw from one full-wire
            //   allocation; no final-only undercount is possible.
            Cache::flush();
            $hintWire = "HTTP/1.1 103 Early Hints\r\n"
               . "Link: </m6.css>; rel=preload; as=style\r\n\r\n"
               . "HTTP/1.1 200 OK\r\nContent-Length: 0\r\n\r\n";
            Cache::$maxBytes = strlen($hintWire);
            Cache::store('hints', $hintWire, 60, '/m6/hints');
            $matrix['hints'] = [
               'length' => strlen($hintWire),
               'stored' => strlen(Cache::$entries['hints'][0] ?? ''),
               'bytes' => Cache::$bytes,
            ];

            // # A Date refresh must target only a canonical final-head field and
            //   remain length-neutral. A Date-looking body suffix must never
            //   grow retained wire without drawing from the aggregate budget.
            Cache::flush();
            $dateWire = "HTTP/1.1 200 OK\r\n"
               . "Content-Length: 8\r\n\r\n\r\nDate: ";
            Cache::$maxBytes = strlen($dateWire);
            Cache::store('date-body', $dateWire, 60, '/m6/date-body');
            $dateBefore = strlen(Cache::$entries['date-body'][0] ?? '');
            usleep(1_100_000);
            $dateFetched = Cache::fetch('date-body');
            $matrix['date_body_refresh'] = [
               'before' => $dateBefore,
               'after' => strlen(Cache::$entries['date-body'][0] ?? ''),
               'fetched' => strlen($dateFetched ?? ''),
               'bytes' => Cache::$bytes,
               'cap' => Cache::$maxBytes,
            ];

            // # A complete 29-byte Date lookalike in the body must remain
            //   byte-for-byte application data, not merely length-neutral.
            Cache::flush();
            $oldBodyDate = 'Mon, 01 Jan 2001 00:00:00 GMT';
            $dateBody = "\r\nDate: {$oldBodyDate}";
            $dateBodyWire = "HTTP/1.1 200 OK\r\n"
               . 'Content-Length: ' . strlen($dateBody)
               . "\r\n\r\n{$dateBody}";
            Cache::$maxBytes = strlen($dateBodyWire);
            Cache::store(
               'date-body-exact',
               $dateBodyWire,
               60,
               '/m6/date-body-exact'
            );
            Cache::$entries['date-body-exact'][3] = time() - 1;
            $dateBodyFetched = Cache::fetch('date-body-exact');
            $matrix['date_body_exact'] = [
               'unchanged' => $dateBodyFetched === $dateBodyWire,
               'offset' => Cache::$entries['date-body-exact'][2] ?? null,
               'length' => strlen($dateBodyFetched ?? ''),
               'bytes' => Cache::$bytes,
               'cap' => Cache::$maxBytes,
            ];

            // # A leading informational Date is not the final Date. Refresh
            //   only the canonical field in the following 200 head.
            Cache::flush();
            $interimDate = 'Tue, 02 Jan 2001 00:00:00 GMT';
            $finalDate = 'Mon, 01 Jan 2001 00:00:00 GMT';
            $dateHintWire = "HTTP/1.1 103 Early Hints\r\n"
               . "Date: {$interimDate}\r\n\r\n"
               . "HTTP/1.1 200 OK\r\nDate: {$finalDate}\r\n"
               . "Content-Length: 0\r\n\r\n";
            $interimOffset = strpos($dateHintWire, "\r\nDate: ") + 8;
            $finalOffset = strrpos($dateHintWire, "\r\nDate: ") + 8;
            Cache::$maxBytes = strlen($dateHintWire);
            Cache::store('date-final', $dateHintWire, 60, '/m6/date-final');
            Cache::$entries['date-final'][3] = time() - 1;
            $dateHintFetched = Cache::fetch('date-final');
            $matrix['date_final_refresh'] = [
               'offset' => Cache::$entries['date-final'][2] ?? null,
               'expected_offset' => $finalOffset,
               'interim_unchanged' =>
                  substr(
                     $dateHintFetched ?? '',
                     $interimOffset,
                     strlen($interimDate)
                  ) === $interimDate,
               'final_changed' =>
                  substr(
                     $dateHintFetched ?? '',
                     $finalOffset,
                     strlen($finalDate)
                  ) !== $finalDate,
               'length' => strlen($dateHintFetched ?? ''),
               'bytes' => Cache::$bytes,
               'cap' => Cache::$maxBytes,
            ];

            // # PHP normalizes a numeric-string array key to int. Replacement
            //   identity must follow that normalization instead of evicting and
            //   double-subtracting the entry being replaced.
            Cache::flush();
            Cache::$maxBytes = 10;
            Cache::store('1', 'AAAAAA', 60, '/m6/numeric');
            Cache::store('peer', 'BBBB', 60, '/m6/peer');
            Cache::store('1', 'AAAAAAA', 60, '/m6/numeric');
            $numericLengths = [];
            foreach (Cache::$entries as $key => $entry) {
               $numericLengths[$key] = strlen($entry[0]);
            }
            $matrix['numeric_replacement'] = [
               'keys' => array_keys(Cache::$entries),
               'wire' => Cache::$entries[1][0] ?? null,
               'actual' => array_sum($numericLengths),
               'bytes' => Cache::$bytes,
               'cap' => Cache::$maxBytes,
            ];

            // # Public-map reconciliation: repair in-place growth, direct
            //   removal, counter tampering, and an over-count direct map.
            Cache::flush();
            Cache::$maxBytes = 10;
            Cache::store('direct-x', 'XX', 60, '/m6/direct-x');
            Cache::$entries['direct-x'][0] = 'XXXXXX';
            Cache::$bytes = 0;
            Cache::store('direct-y', 'YYYY', 60, '/m6/direct-y');
            $matrix['direct_growth'] = [
               'keys' => array_keys(Cache::$entries),
               'bytes' => Cache::$bytes,
            ];

            unset(Cache::$entries['direct-x']);
            Cache::store('direct-z', 'ZZZZZZ', 60, '/m6/direct-z');
            $matrix['direct_unset'] = [
               'keys' => array_keys(Cache::$entries),
               'bytes' => Cache::$bytes,
            ];

            Cache::flush();
            Cache::$maxBytes = Cache::ENTRIES_LIMIT + 1;
            $expiration = time() + 60;
            for ($index = 0; $index <= Cache::ENTRIES_LIMIT; $index++) {
               Cache::$entries["direct-count-{$index}"] = [
                  'D',
                  $expiration,
                  -1,
                  time(),
               ];
            }
            Cache::$bytes = 0;
            Cache::store(
               'direct-count-' . Cache::ENTRIES_LIMIT,
               'R',
               60,
               '/m6/direct-count'
            );
            $matrix['direct_count'] = [
               'entries' => count(Cache::$entries),
               'bytes' => Cache::$bytes,
               'first_present' =>
                  isset(Cache::$entries['direct-count-0']),
               'last_wire' =>
                  Cache::$entries[
                     'direct-count-' . Cache::ENTRIES_LIMIT
                  ][0] ?? null,
            ];

            // # Flush releases every byte/URI and advances invalidation once.
            $generation = Cache::$generation;
            Cache::flush();
            $matrix['flush'] = [
               'entries' => count(Cache::$entries),
               'bytes' => Cache::$bytes,
               'URIs' => count(Cache::$URIs),
               'generation_delta' => Cache::$generation - $generation,
            ];
         }
         finally {
            Cache::flush();
            Cache::$maxBytes = $probeCap;
         }

         return $Response->JSON->send($matrix);
      }, GET);

      yield $Router->route('/m6/date-body', static function (
         Request $Request,
         Response $Response,
      ) use (&$dateHandlers): Response {
         $dateHandlers++;
         $Response->Header->remove('Date');

         return $Response(body: "\r\nDate: ");
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/m6/cleanup', static function (
         Request $Request,
         Response $Response,
      ) use (
         &$capName,
         &$capOriginal,
         &$capConfigured,
         &$dateHandlers,
      ): Response {
         Cache::flush();

         $restored = $capName === null;
         if ($capName !== null && $capOriginal !== null) {
            try {
               $Cap = new ReflectionProperty(Cache::class, $capName);
               $Cap->setValue(null, $capOriginal);
               $restored = $Cap->getValue() === $capOriginal;
            }
            catch (Throwable) {
               $restored = false;
            }
         }

         $capConfigured = false;

         return $Response->JSON->send([
            'entries' => count(Cache::$entries),
            'bytes' => Cache::$bytes,
            'URIs' => count(Cache::$URIs),
            'budget_restored' => $restored,
            'date_handlers' => $dateHandlers,
         ]);
      }, GET);
   },

   test: static function (array $responses) use (
      $probeCap,
      $bodySize,
   ): bool|string {
      if (count($responses) !== 11) {
         return 'M6 fixture failed: expected eleven responses, got '
            . count($responses) . '.';
      }

      $Body = static function (string $response): null|string {
         $separator = strpos($response, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         return substr($response, $separator + 4);
      };

      $Decode = static function (string $response) use ($Body): null|array {
         $body = $Body($response);
         if ($body === null) {
            return null;
         }

         try {
            $decoded = json_decode(
               $body,
               associative: true,
               flags: JSON_THROW_ON_ERROR
            );
         }
         catch (Throwable) {
            return null;
         }

         return is_array($decoded) ? $decoded : null;
      };

      foreach ($responses as $index => $response) {
         if (! is_string($response) || $response === '') {
            return 'M6 fixture failed: response ' . ($index + 1)
               . ' was empty.';
         }
      }

      $setup = $Decode($responses[0]);
      $report = $Decode($responses[6]);
      $lifecycle = $Decode($responses[7]);
      $cleanup = $Decode($responses[10]);
      if (
         $setup === null
         || $report === null
         || $lifecycle === null
         || $cleanup === null
      ) {
         $invalid = [];
         foreach ([
            'setup' => [0, $setup],
            'report' => [6, $report],
            'lifecycle' => [7, $lifecycle],
            'cleanup' => [10, $cleanup],
         ] as $name => [$index, $decoded]) {
            if ($decoded !== null) {
               continue;
            }

            $body = $Body($responses[$index]);
            $invalid[$name] = [
               'response_bytes' => strlen($responses[$index]),
               'head_prefix' => substr($responses[$index], 0, 160),
               'body_prefix' => $body === null
                  ? null
                  : substr($body, 0, 240),
            ];
         }

         Vars::$labels = ['M6 invalid JSON endpoint evidence'];
         dump(json_encode($invalid));

         return 'M6 fixture failed: setup, report, lifecycle, or cleanup did not return valid JSON; evidence='
            . json_encode($invalid);
      }

      if (
         ($setup['probe_cap'] ?? null) !== $probeCap
         || ($setup['entries_limit'] ?? null) !== Cache::ENTRIES_LIMIT
         || ($setup['wire_limit'] ?? null) !== Cache::WIRE_LIMIT
         || ($setup['configured_wire_product'] ?? null)
            !== Cache::ENTRIES_LIMIT * Cache::WIRE_LIMIT
      ) {
         Vars::$labels = ['M6 setup evidence'];
         dump(json_encode($setup));

         return 'M6 fixture failed: the worker did not report the expected cache limits.';
      }

      $Bodies = [];
      foreach (array_slice($responses, 1, 5) as $response) {
         $body = $Body($response);
         if ($body === null || strlen($body) < $bodySize) {
            return 'M6 fixture failed: a cache-route response was missing or truncated.';
         }
         $Bodies[] = $body;
      }

      if (
         ! str_starts_with($Bodies[0], 'M6-HANDLER:1;QUERY:1;')
         || ! str_starts_with($Bodies[1], 'M6-HANDLER:1;QUERY:1;')
      ) {
         Vars::$labels = ['M6 live cache-hit control'];
         dump(json_encode([
            'prime' => substr($Bodies[0], 0, 64),
            'repeat' => substr($Bodies[1], 0, 64),
         ]));

         return 'M6 control failed: repeating one query target did not replay its cached response.';
      }

      if (
         ! str_starts_with($Bodies[2], 'M6-HANDLER:2;QUERY:2;')
         || ! str_starts_with($Bodies[3], 'M6-HANDLER:3;QUERY:3;')
      ) {
         Vars::$labels = ['M6 query-churn control'];
         dump(json_encode([
            'query_2' => substr($Bodies[2], 0, 64),
            'query_3' => substr($Bodies[3], 0, 64),
         ]));

         return 'M6 control failed: distinct query targets did not reach the same cache-enabled route.';
      }

      $finalFirst = str_starts_with(
         $Bodies[4],
         'M6-HANDLER:1;QUERY:1;'
      );
      $finalMiss = str_starts_with(
         $Bodies[4],
         'M6-HANDLER:4;QUERY:1;'
      );
      if (! $finalFirst && ! $finalMiss) {
         Vars::$labels = ['M6 post-churn response'];
         dump(json_encode(substr($Bodies[4], 0, 64)));

         return 'M6 fixture failed: the final first-target request was neither a cache hit nor a bounded-cache miss.';
      }

      $entryLengths = $report['entry_lengths'] ?? null;
      $retained = $report['retained_wire_bytes'] ?? null;
      if (
         ! is_array($entryLengths)
         || ! is_int($retained)
         || $retained !== array_sum($entryLengths)
         || ($report['probe_cap'] ?? null) !== $probeCap
         || ($report['entries'] ?? null) !== count($entryLengths)
      ) {
         Vars::$labels = ['M6 exact wire-accounting evidence'];
         dump(json_encode($report));

         return 'M6 fixture failed: the worker report did not exactly describe retained cache wire.';
      }

      foreach ($entryLengths as $length) {
         if (! is_int($length) || $length <= 0 || $length >= $probeCap) {
            Vars::$labels = ['M6 per-entry control'];
            dump(json_encode($report));

            return 'M6 control failed: an individual cache entry did not fit below the aggregate probe cap.';
         }
      }

      if (
         ($cleanup['entries'] ?? null) !== 0
         || ($cleanup['bytes'] ?? null) !== 0
         || ($cleanup['URIs'] ?? null) !== 0
         || ($cleanup['budget_restored'] ?? null) !== true
         || ($cleanup['date_handlers'] ?? null) !== 1
      ) {
         Vars::$labels = ['M6 cleanup evidence'];
         dump(json_encode($cleanup));

         return 'M6 fixture failed: cleanup did not flush cache state or restore its configuration.';
      }

      $datePrime = $responses[8];
      $dateReplay = $responses[9];
      $dateSeparator = strpos($datePrime, "\r\n\r\n");
      if (
         $datePrime !== $dateReplay
         || $dateSeparator === false
         || strpos(substr($datePrime, 0, $dateSeparator), "\r\nDate: ") !== false
         || $Body($datePrime) !== "\r\nDate: "
         || strpos($datePrime, "\r\nContent-Length: 8\r\n") === false
      ) {
         Vars::$labels = ['M6 real Date-body cache replay'];
         dump(json_encode([
            'prime' => $datePrime,
            'replay' => $dateReplay,
            'handlers' => $cleanup['date_handlers'] ?? null,
         ]));

         return 'CONFIRMED M6: the real Response::encode(), stash(), and fetch() '
            . 'path changed or reframed a Date-looking cached response body.';
      }

      if ($retained > $probeCap) {
         $evidence = [
            'budget_property' => $report['budget_property'] ?? null,
            'budget_configured' => $report['budget_configured'] ?? false,
            'counter_property' => $report['counter_property'] ?? null,
            'counter_value' => $report['counter_value'] ?? null,
            'handler_runs' => $report['handler_runs'] ?? null,
            'entries' => $report['entries'],
            'entry_lengths' => array_values($entryLengths),
            'retained_wire_bytes' => $retained,
            'probe_cap' => $probeCap,
            'configured_wire_product' =>
               $report['configured_wire_product'] ?? null,
         ];

         Vars::$labels = ['M6 aggregate route-cache evidence'];
         dump(json_encode($evidence));

         return 'CONFIRMED M6: real cache-enabled GETs with distinct query targets '
            . "retained {$retained} wire bytes across "
            . count($entryLengths) . " individually admissible entries, exceeding the "
            . "{$probeCap}-byte aggregate probe cap; the implementation exposes only "
            . Cache::ENTRIES_LIMIT . ' entries x ' . Cache::WIRE_LIMIT
            . ' wire bytes (' . (Cache::ENTRIES_LIMIT * Cache::WIRE_LIMIT)
            . ' bytes per worker).';
      }

      $dateRefresh = $lifecycle['date_body_refresh'] ?? null;
      if (
         is_array($dateRefresh)
         && is_int($dateRefresh['after'] ?? null)
         && is_int($dateRefresh['bytes'] ?? null)
         && is_int($dateRefresh['cap'] ?? null)
         && (
            $dateRefresh['after'] > $dateRefresh['cap']
            || $dateRefresh['bytes'] !== $dateRefresh['after']
         )
      ) {
         Vars::$labels = ['M6 Date-refresh budget bypass'];
         dump(json_encode($dateRefresh));

         return 'CONFIRMED M6: a Date-looking response-body suffix made cache '
            . 'refresh grow retained wire beyond maxBytes without updating the '
            . 'aggregate byte ledger.';
      }

      $numericReplacement = $lifecycle['numeric_replacement'] ?? null;
      if (
         is_array($numericReplacement)
         && is_int($numericReplacement['actual'] ?? null)
         && is_int($numericReplacement['bytes'] ?? null)
         && is_int($numericReplacement['cap'] ?? null)
         && (
            $numericReplacement['actual'] > $numericReplacement['cap']
            || $numericReplacement['bytes'] !== $numericReplacement['actual']
         )
      ) {
         Vars::$labels = ['M6 numeric-key replacement budget bypass'];
         dump(json_encode($numericReplacement));

         return 'CONFIRMED M6: numeric-string replacement identity made cache '
            . 'retention exceed maxBytes while undercounting the aggregate byte '
            . 'ledger.';
      }

      if (
         ($setup['budget_property'] ?? null) !== 'maxBytes'
         || ($setup['budget_configured'] ?? null) !== true
         || ($setup['budget_error'] ?? null) !== null
         || ($report['budget_property'] ?? null) !== 'maxBytes'
         || ($report['budget_configured'] ?? null) !== true
         || ($report['counter_property'] ?? null) !== 'bytes'
         || ($report['counter_value'] ?? null) !== $retained
         || ($report['handler_runs'] ?? null) !== 4
         || ($report['entries'] ?? null) !== 2
         || ! $finalMiss
      ) {
         Vars::$labels = ['M6 bounded-cache accounting evidence'];
         dump(json_encode([
            'setup' => $setup,
            'report' => $report,
            'final_response' => substr($Bodies[4], 0, 64),
         ]));

         return 'M6 fixture failed: bounded retention did not prove its configured cap, exact ledger, FIFO miss, and handler controls.';
      }

      $lifecycleValid =
         ($lifecycle['zero']['entries'] ?? null) === 0
         && ($lifecycle['zero']['bytes'] ?? null) === 0
         && ($lifecycle['zero']['URIs'] ?? null) === 0
         && ($lifecycle['exact']['keys'] ?? null) === ['a', 'b']
         && ($lifecycle['exact']['bytes'] ?? null) === 10
         && ($lifecycle['shrink']['keys'] ?? null) === ['a', 'b']
         && ($lifecycle['shrink']['wire'] ?? null) === 'AAA'
         && ($lifecycle['shrink']['bytes'] ?? null) === 7
         && ($lifecycle['grow']['keys'] ?? null) === ['a']
         && ($lifecycle['grow']['wire'] ?? null) === 'AAAAAAA'
         && ($lifecycle['grow']['bytes'] ?? null) === 7
         && ($lifecycle['aggregate_reject']['keys'] ?? null) === ['a']
         && ($lifecycle['aggregate_reject']['wire'] ?? null) === 'AAAAAAA'
         && ($lifecycle['aggregate_reject']['bytes'] ?? null) === 7
         && ($lifecycle['aggregate_reject']['rejected_URI'] ?? null) === false
         && ($lifecycle['byte_fifo']['keys'] ?? null) === ['c', 'd']
         && ($lifecycle['byte_fifo']['bytes'] ?? null) === 4
         && ($lifecycle['TTL_reject']['unchanged'] ?? null) === true
         && ($lifecycle['TTL_reject']['rejected_URI'] ?? null) === false
         && ($lifecycle['expiry']['result'] ?? null) === null
         && ($lifecycle['expiry']['entries'] ?? null) === 0
         && ($lifecycle['expiry']['bytes'] ?? null) === 0
         && ($lifecycle['count_fifo']['entries'] ?? null)
            === Cache::ENTRIES_LIMIT
         && ($lifecycle['count_fifo']['bytes'] ?? null)
            === Cache::ENTRIES_LIMIT
         && ($lifecycle['count_fifo']['first_present'] ?? null) === false
         && ($lifecycle['count_fifo']['last_present'] ?? null) === true
         && ($lifecycle['wire_limit']['entries'] ?? null) === 1
         && ($lifecycle['wire_limit']['length'] ?? null)
            === Cache::WIRE_LIMIT
         && ($lifecycle['wire_limit']['bytes'] ?? null)
            === Cache::WIRE_LIMIT
         && ($lifecycle['wire_limit']['rejected_URI'] ?? null) === false
         && ($lifecycle['hints']['length'] ?? null) > 0
         && ($lifecycle['hints']['stored'] ?? null)
            === ($lifecycle['hints']['length'] ?? null)
         && ($lifecycle['hints']['bytes'] ?? null)
            === ($lifecycle['hints']['length'] ?? null)
         && ($lifecycle['date_body_refresh']['before'] ?? null) > 0
         && ($lifecycle['date_body_refresh']['after'] ?? null)
            === ($lifecycle['date_body_refresh']['before'] ?? null)
         && ($lifecycle['date_body_refresh']['fetched'] ?? null)
            === ($lifecycle['date_body_refresh']['before'] ?? null)
         && ($lifecycle['date_body_refresh']['bytes'] ?? null)
            === ($lifecycle['date_body_refresh']['before'] ?? null)
         && ($lifecycle['date_body_refresh']['cap'] ?? null)
            === ($lifecycle['date_body_refresh']['before'] ?? null)
         && ($lifecycle['date_body_exact']['unchanged'] ?? null) === true
         && ($lifecycle['date_body_exact']['offset'] ?? null) === -1
         && ($lifecycle['date_body_exact']['length'] ?? null)
            === ($lifecycle['date_body_exact']['bytes'] ?? null)
         && ($lifecycle['date_body_exact']['bytes'] ?? null)
            === ($lifecycle['date_body_exact']['cap'] ?? null)
         && ($lifecycle['date_final_refresh']['offset'] ?? null)
            === ($lifecycle['date_final_refresh']['expected_offset'] ?? null)
         && ($lifecycle['date_final_refresh']['interim_unchanged'] ?? null) === true
         && ($lifecycle['date_final_refresh']['final_changed'] ?? null) === true
         && ($lifecycle['date_final_refresh']['length'] ?? null)
            === ($lifecycle['date_final_refresh']['bytes'] ?? null)
         && ($lifecycle['date_final_refresh']['bytes'] ?? null)
            === ($lifecycle['date_final_refresh']['cap'] ?? null)
         && ($lifecycle['numeric_replacement']['keys'] ?? null) === [1]
         && ($lifecycle['numeric_replacement']['wire'] ?? null) === 'AAAAAAA'
         && ($lifecycle['numeric_replacement']['actual'] ?? null) === 7
         && ($lifecycle['numeric_replacement']['bytes'] ?? null)
            === ($lifecycle['numeric_replacement']['actual'] ?? null)
         && ($lifecycle['numeric_replacement']['cap'] ?? null) === 10
         && ($lifecycle['direct_growth']['keys'] ?? null)
            === ['direct-x', 'direct-y']
         && ($lifecycle['direct_growth']['bytes'] ?? null) === 10
         && ($lifecycle['direct_unset']['keys'] ?? null)
            === ['direct-y', 'direct-z']
         && ($lifecycle['direct_unset']['bytes'] ?? null) === 10
         && ($lifecycle['direct_count']['entries'] ?? null)
            === Cache::ENTRIES_LIMIT
         && ($lifecycle['direct_count']['bytes'] ?? null)
            === Cache::ENTRIES_LIMIT
         && ($lifecycle['direct_count']['first_present'] ?? null) === false
         && ($lifecycle['direct_count']['last_wire'] ?? null) === 'R'
         && ($lifecycle['flush']['entries'] ?? null) === 0
         && ($lifecycle['flush']['bytes'] ?? null) === 0
         && ($lifecycle['flush']['URIs'] ?? null) === 0
         && ($lifecycle['flush']['generation_delta'] ?? null) === 1;

      if (! $lifecycleValid) {
         Vars::$labels = ['M6 cache-budget lifecycle matrix'];
         dump(json_encode($lifecycle));

         return 'M6 fixture failed: aggregate cache accounting failed a cap, replacement, expiry, FIFO, framing, reconciliation, or flush boundary.';
      }

      return true;
   },
);
