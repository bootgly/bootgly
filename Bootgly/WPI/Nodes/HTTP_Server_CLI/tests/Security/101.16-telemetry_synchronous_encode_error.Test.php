<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 synchronous serialization-failure regression.
 *
 * Response selection is not terminal success until encode() produces wire.
 * A synchronous encoder failure must preserve the original Throwable and no
 * wire while closing total/duration/in-flight with no synthetic status.
 */
$Probe = new class {
   public string $error = '';
   /** @var array<string,mixed> */
   public array $control = [];
   /** @var array<string,mixed> */
   public array $failed = [];
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
   description: 'Synchronous encode failure must close Telemetry without a selected status',
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
            // ? Positive control proves the real decoder, production Encoder_,
            //   handler and serializer can emit one ordinary 201 lifecycle.
            $ControlEvents = new Emitter;
            $ControlObservability = new Observability(collectors: false);
            Emitter::$Instance = $ControlEvents;
            new Telemetry($ControlObservability)->boot();
            $Configure($ControlEvents);

            $controlHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$controlHandlers): Generator {
               yield $Router->route('/l4/sync-encode/control', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$controlHandlers): Response {
                  $controlHandlers++;

                  return $Response(code: 201, body: 'L4-SYNC-ENCODE-CONTROL');
               }, GET);
            };
            $control = $Run(static fn (): string => $Encode(
               "GET /l4/sync-encode/control HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->control = [
               ...$control,
               'handlers' => $controlHandlers,
               'metrics' => $Snapshot($ControlObservability),
            ];

            $FailedEvents = new Emitter;
            $FailedObservability = new Observability(collectors: false);
            Emitter::$Instance = $FailedEvents;
            new Telemetry($FailedObservability)->boot();
            $Configure($FailedEvents);

            $State = new class {
               public int $encodes = 0;
            };
            $Explosive = new class extends Response {
               public null|Closure $Record = null;

               public function encode (Packages $Package, null|int &$length): string
               {
                  if ($this->Body->raw === 'L4-SYNC-ENCODE-THROW') {
                     ($this->Record)();
                     throw new RuntimeException('L4-SYNC-ENCODE-THROW');
                  }

                  return parent::encode($Package, $length);
               }
            };
            $Explosive->Record = static function () use ($State): void {
               $State->encodes++;
            };
            Server::$Response = $Explosive;

            $failedHandlers = 0;
            SAPI::$Handler = static function (
               Request $Request,
               Response $Response,
               Router $Router,
            ) use (&$failedHandlers): Generator {
               yield $Router->route('/l4/sync-encode/fail', static function (
                  Request $Request,
                  Response $Response,
               ) use (&$failedHandlers): Response {
                  $failedHandlers++;

                  return $Response(code: 202, body: 'L4-SYNC-ENCODE-THROW');
               }, GET);
            };
            $failed = $Run(static fn (): string => $Encode(
               "GET /l4/sync-encode/fail HTTP/1.1\r\nHost: localhost\r\n\r\n"
            ));
            $Probe->failed = [
               ...$failed,
               'handlers' => $failedHandlers,
               'encodes' => $State->encodes,
               'metrics' => $Snapshot($FailedObservability),
            ];
         });
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /l4/sync-encode/harness HTTP/1.1\r\n"
         . "Host: localhost\r\nConnection: close\r\n\r\n";
   },

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ): Generator {
      yield $Router->route('/l4/sync-encode/harness', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(body: 'L4-SYNC-ENCODE-HARNESS-OK');
      }, GET);
   },

   test: static function (string $response) use ($Probe): bool|string {
      if ($Probe->error !== '') {
         return 'L4 synchronous encode fixture failed: ' . $Probe->error;
      }
      if (
         str_contains($response, 'HTTP/1.1 200 OK') === false
         || str_contains($response, 'L4-SYNC-ENCODE-HARNESS-OK') === false
      ) {
         return 'L4 synchronous encode native harness control failed.';
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
      $control = $Probe->control;
      if (
         ($control['throwable_class'] ?? null) !== null
         || is_string($control['wire'] ?? null) === false
         || str_contains($control['wire'], 'HTTP/1.1 201 Created') === false
         || str_contains($control['wire'], 'L4-SYNC-ENCODE-CONTROL') === false
         || ($control['handlers'] ?? null) !== 1
         || ($control['metrics'] ?? null) !== $expected2xx
      ) {
         Vars::$labels = ['L4 synchronous encode positive control'];
         dump(json_encode($control));

         return 'L4 synchronous encode positive control failed.';
      }

      $expectedNoStatus = $expected2xx;
      $expectedNoStatus['responses_2xx'] = 0;
      $failed = $Probe->failed;
      if (
         ($failed['wire'] ?? null) !== null
         || ($failed['throwable_class'] ?? null) !== RuntimeException::class
         || ($failed['throwable_message'] ?? null) !== 'L4-SYNC-ENCODE-THROW'
         || ($failed['handlers'] ?? null) !== 1
         || ($failed['encodes'] ?? null) !== 1
         || ($failed['metrics'] ?? null) !== $expectedNoStatus
      ) {
         Vars::$labels = ['L4 synchronous encode failure evidence'];
         dump(json_encode($failed));

         return 'L4 regression: synchronous serialization failure selected a '
            . 'status, leaked accounting, changed the original Throwable or '
            . 'produced wire. Evidence: ' . json_encode($failed);
      }

      return true;
   },
);
