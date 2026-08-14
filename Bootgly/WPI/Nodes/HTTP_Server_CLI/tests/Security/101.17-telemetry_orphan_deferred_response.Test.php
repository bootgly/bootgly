<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 orphan deferred-response regression.
 *
 * The public deferred flag and an unbound replacement Response can suppress
 * synchronous wire without creating a scheduled Cancellation generation. Such
 * no-wire exits must still close the admitted exchange as a null-status
 * lifecycle instead of leaving Telemetry permanently in flight.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $flagged = [];
   /** @var array<string,mixed> */
   public array $replacement = [];
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
   description: 'No-wire orphan deferred responses must close a null-status Telemetry lifecycle',
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
            $FlaggedEvents = new Emitter;
            $FlaggedObservability = new Observability(collectors: false);
            Emitter::$Instance = $FlaggedEvents;
            new Telemetry($FlaggedObservability)->boot();
            $Configure($FlaggedEvents);

            $flaggedHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$flaggedHandlers): Generator {
               yield $Router->route('/l4/orphan-deferred/flag', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$flaggedHandlers): Response {
                  $flaggedHandlers++;
                  $Response->deferred = true;

                  return $Response(code: 202, body: 'L4-ORPHAN-FLAG-NO-WIRE');
               }, GET);
            };
            $flagged = $Run(static fn (): string => $Encode(
               "GET /l4/orphan-deferred/flag HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->flagged = [
               ...$flagged,
               'handlers' => $flaggedHandlers,
               'metrics' => $Snapshot($FlaggedObservability),
            ];

            $ReplacementEvents = new Emitter;
            $ReplacementObservability = new Observability(collectors: false);
            Emitter::$Instance = $ReplacementEvents;
            new Telemetry($ReplacementObservability)->boot();
            $Configure($ReplacementEvents);

            $replacementHandlers = 0;
            $replacementWork = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$replacementHandlers, &$replacementWork): Generator {
               yield $Router->route('/l4/orphan-deferred/replacement', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$replacementHandlers, &$replacementWork): Response {
                  $replacementHandlers++;
                  $Replacement = new Response(code: 203, body: 'L4-ORPHAN-REPLACEMENT-NO-WIRE');

                  return $Replacement->defer(static function () use (
                     &$replacementWork,
                  ): void {
                     $replacementWork++;
                  });
               }, GET);
            };
            $replacement = $Run(static fn (): string => $Encode(
               "GET /l4/orphan-deferred/replacement HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->replacement = [
               ...$replacement,
               'handlers' => $replacementHandlers,
               'work' => $replacementWork,
               'metrics' => $Snapshot($ReplacementObservability),
            ];
         });
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l4/orphan-deferred/harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/orphan-deferred/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-ORPHAN-DEFERRED-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         return 'L4 orphan deferred fixture failed: ' . $Probe->error;
      }
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-ORPHAN-DEFERRED-HARNESS-OK') === false
      ) {
         return 'L4 orphan deferred native harness control failed.';
      }

      $expected = [
         'requests_total' => 1,
         'in_flight' => 0,
         'duration_count' => 1,
         'responses_1xx' => 0,
         'responses_2xx' => 0,
         'responses_3xx' => 0,
         'responses_4xx' => 0,
         'responses_5xx' => 0,
      ];
      $failures = [];
      foreach (['flagged' => $Probe->flagged, 'replacement' => $Probe->replacement] as $leg => $evidence) {
         if (
            ($evidence['wire'] ?? null) !== ''
            || ($evidence['throwable_class'] ?? null) !== null
            || ($evidence['handlers'] ?? null) !== 1
            || ($evidence['metrics'] ?? null) !== $expected
         ) {
            $failures[$leg] = $evidence;
         }
      }
      if (($Probe->replacement['work'] ?? null) !== 0) {
         $failures['replacement_work'] = $Probe->replacement;
      }

      if ($failures !== []) {
         Vars::$labels = ['L4 orphan deferred terminal accounting'];
         dump(json_encode($failures));

         return 'L4 regression: a no-wire orphan deferred response left '
            . 'Telemetry in flight, selected a status or executed unbound '
            . 'work. Evidence: ' . json_encode($failures);
      }

      return true;
   },
);
