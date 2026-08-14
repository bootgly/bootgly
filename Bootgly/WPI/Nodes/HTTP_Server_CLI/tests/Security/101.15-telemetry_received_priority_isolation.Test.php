<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 Received-event priority isolation regression.
 *
 * An application listener registered before Telemetry at the maximum public
 * priority can stop ordinary event propagation. It must not blind core request
 * accounting. A second leg registers a throwing maximum-priority listener
 * after Telemetry and proves the exceptional no-wire terminal path still
 * preserves the original Throwable and closes without inventing a status.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $stopped = [];
   /** @var array<string,mixed> */
   public array $thrown = [];
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['1xx' => null, '2xx' => null, '3xx' => null, '4xx' => null, '5xx' => null];
   $Integer = static fn (mixed $value): null|int => is_int($value) || is_float($value)
      ? (int) $value
      : null;

   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      $class = $series['labels']['class'] ?? null;
      if (is_string($class) && array_key_exists($class, $responses)) {
         $responses[$class] = $Integer($series['value'] ?? null);
      }
   }

   return [
      'requests_total' => $Integer($metrics['http_requests_total']['series'][0]['value'] ?? null),
      'in_flight' => $Integer($metrics['http_requests_in_flight']['series'][0]['value'] ?? null),
      'duration_count' => $Integer(
         $metrics['http_request_duration_seconds']['series'][0]['count'] ?? null
      ),
      'responses_1xx' => $responses['1xx'],
      'responses_2xx' => $responses['2xx'],
      'responses_3xx' => $responses['3xx'],
      'responses_4xx' => $responses['4xx'],
      'responses_5xx' => $responses['5xx'],
   ];
};

return new Test(
   description: 'Maximum-priority Received listeners must not blind Telemetry',
   Separator: new Separator(line: true),

   request: static function () use ($Probe, $Snapshot): string {
      /** @var Closure $Fixture */
      $Fixture = require __DIR__ . '/Support/Telemetry_Encoder.Fixture.php';

      try {
         $Fixture(static function (
            Closure $Encode,
            Closure $Run,
            Closure $Configure,
         ) use ($Probe, $Snapshot): void {
            // ! Register the stopper before boot() at the same maximum public
            //   priority. The lower-priority sentinel proves propagation did
            //   stop; successful metrics therefore require an isolated core
            //   admission path rather than accidental listener fall-through.
            $StoppedEvents = new Emitter;
            $stoppers = 0;
            $sentinels = 0;
            $publicPayload = false;
            $StoppedEvents->listen(
               RequestEvents::Received,
               static function (Emission $Emission) use (
                  &$publicPayload,
                  &$stoppers,
               ): void {
                  $CurrentRequest = $Emission->payload[0] ?? null;
                  if (
                     $CurrentRequest instanceof Request === false
                     || $CurrentRequest->URI !== '/l4/received-priority/stop'
                  ) {
                     return;
                  }

                  $stoppers++;
                  $publicPayload = count($Emission->payload) === 1;
                  $Emission->stop();
               },
               priority: PHP_INT_MAX,
            );
            $StoppedObservability = new Observability(collectors: false);
            Emitter::$Instance = $StoppedEvents;
            new Telemetry($StoppedObservability)->boot();
            $StoppedEvents->listen(
               RequestEvents::Received,
               static function (Emission $Emission) use (&$sentinels): void {
                  $CurrentRequest = $Emission->payload[0] ?? null;
                  if (
                     $CurrentRequest instanceof Request
                     && $CurrentRequest->URI === '/l4/received-priority/stop'
                  ) {
                     $sentinels++;
                  }
               },
               priority: PHP_INT_MAX - 1,
            );
            $Configure($StoppedEvents);

            $stoppedHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$stoppedHandlers): Generator {
               yield $Router->route('/l4/received-priority/stop', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$stoppedHandlers): Response {
                  $stoppedHandlers++;

                  return $Response(code: 201, body: 'L4-RECEIVED-STOP-201');
               }, GET);
            };
            $stopped = $Run(static fn (): string => $Encode(
               "GET /l4/received-priority/stop HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->stopped = [
               ...$stopped,
               'stoppers' => $stoppers,
               'sentinels' => $sentinels,
               'public_payload' => $publicPayload,
               'handlers' => $stoppedHandlers,
               'metrics' => $Snapshot($StoppedObservability),
            ];

            // ? Ordering control: Telemetry boots first, then a same-priority
            //   application listener throws. Accounting must observe admission
            //   and replay terminal closure while the original throw/no-wire
            //   semantics remain unchanged.
            $ThrownEvents = new Emitter;
            $ThrownObservability = new Observability(collectors: false);
            Emitter::$Instance = $ThrownEvents;
            new Telemetry($ThrownObservability)->boot();
            $throwers = 0;
            $ThrownEvents->listen(
               RequestEvents::Received,
               static function (Emission $Emission) use (&$throwers): void {
                  $CurrentRequest = $Emission->payload[0] ?? null;
                  if (
                     $CurrentRequest instanceof Request === false
                     || $CurrentRequest->URI !== '/l4/received-priority/throw'
                  ) {
                     return;
                  }

                  $throwers++;
                  throw new RuntimeException('L4-RECEIVED-MAX-THROW');
               },
               priority: PHP_INT_MAX,
            );
            $Configure($ThrownEvents);

            $thrownHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$thrownHandlers): Generator {
               yield $Router->route('/l4/received-priority/throw', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$thrownHandlers): Response {
                  $thrownHandlers++;

                  return $Response(body: 'L4-RECEIVED-THROW-HANDLER-MUST-NOT-RUN');
               }, GET);
            };
            $thrown = $Run(static fn (): string => $Encode(
               "GET /l4/received-priority/throw HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->thrown = [
               ...$thrown,
               'throwers' => $throwers,
               'handlers' => $thrownHandlers,
               'metrics' => $Snapshot($ThrownObservability),
            ];
         });
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l4/received-priority/harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/received-priority/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-RECEIVED-PRIORITY-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         return 'L4 Received-priority fixture failed: ' . $Probe->error;
      }
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-RECEIVED-PRIORITY-HARNESS-OK') === false
      ) {
         return 'L4 Received-priority native harness control failed.';
      }

      $expected2xx = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 1,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      $stopped = $Probe->stopped;
      if (
         ($stopped['throwable_class'] ?? null) !== null
         || is_string($stopped['wire'] ?? null) === false
         || str_contains($stopped['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($stopped['wire'], 'L4-RECEIVED-STOP-201') === false
         || ($stopped['stoppers'] ?? null) !== 1
         || ($stopped['sentinels'] ?? null) !== 0
         || ($stopped['public_payload'] ?? null) !== true
         || ($stopped['handlers'] ?? null) !== 1
         || ($stopped['metrics'] ?? null) !== $expected2xx
      ) {
         Vars::$labels = ['L4 stopped Received lifecycle evidence'];
         dump(json_encode($stopped));

         return 'L4 regression: a pre-boot maximum-priority Received stopper '
            . 'blinded or corrupted the admitted Telemetry lifecycle. Evidence: '
            . json_encode($stopped);
      }

      $expectedNoStatus = $expected2xx;
      $expectedNoStatus['responses_2xx'] = 0;
      $thrown = $Probe->thrown;
      if (
         ($thrown['wire'] ?? null) !== null
         || ($thrown['throwable_class'] ?? null) !== RuntimeException::class
         || ($thrown['throwable_message'] ?? null) !== 'L4-RECEIVED-MAX-THROW'
         || ($thrown['throwers'] ?? null) !== 1
         || ($thrown['handlers'] ?? null) !== 0
         || ($thrown['metrics'] ?? null) !== $expectedNoStatus
      ) {
         Vars::$labels = ['L4 posterior maximum-priority Received throw evidence'];
         dump(json_encode($thrown));

         return 'L4 Received-priority throw control did not preserve the '
            . 'original Throwable, no-wire result and null-status lifecycle.';
      }

      return true;
   },
);
