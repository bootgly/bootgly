<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * Security PoC L4 (HTTP_Server_CLI audit 2026-08-02) — a completed deferred
 * response must reach the same terminal Telemetry lifecycle as a synchronous
 * response.
 *
 * The setup leg installs an isolated Emitter and Telemetry registry, then
 * switches this worker to the production Encoder_. A synchronous response is
 * the positive lifecycle control. The control snapshot proves that admission
 * and terminal accounting both ran before the target leg starts. The deferred leg then
 * completes through the real Response Fiber loop and emits a verifiable 202
 * response. The evidence snapshot distinguishes the exact vulnerable state
 * from a secure terminal lifecycle.
 *
 * A snapshot route is itself in-flight while it gathers metrics. Therefore
 * the secure baseline is one in-flight request, and its own terminal transition
 * is visible only to the next snapshot.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses2xx = null;

   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      if (($series['labels']['class'] ?? null) === '2xx') {
         $responses2xx = $series['value'] ?? null;
         break;
      }
   }

   return [
      'requests_total' => $metrics['http_requests_total']['series'][0]['value'] ?? null,
      'in_flight' => $metrics['http_requests_in_flight']['series'][0]['value'] ?? null,
      'duration_count' => $metrics['http_request_duration_seconds']['series'][0]['count'] ?? null,
      'responses_2xx' => $responses2xx,
   ];
};

$Request = static function (string $path): Closure {
   return static fn (): string => "GET {$path} HTTP/1.1\r\n"
      . "Host: localhost\r\n"
      . "\r\n";
};

return new Test(
   description: 'Completed deferred responses must close the Telemetry lifecycle exactly once',
   Separator: new Separator(line: true),

   requests: [
      $Request('/l4/telemetry/setup'),
      $Request('/l4/telemetry/sync'),
      $Request('/l4/telemetry/control'),
      $Request('/l4/telemetry/deferred'),
      $Request('/l4/telemetry/evidence'),
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe, $Snapshot): Generator {
      yield $Router->route('/l4/telemetry/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();

         // ! Exercise the exact production lifecycle after this setup response.
         //   Encoder_Testing already captured the prior Emitter for the current
         //   call, so setup cannot leave an unmatched Received in the registry.
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-SETUP-OK');
      }, GET);

      yield $Router->route('/l4/telemetry/sync', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 200, body: 'L4-SYNC-OK');
      }, GET);

      yield $Router->route('/l4/telemetry/control', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         if ($Probe->Observability === null) {
            return $Response(code: 500, body: 'L4-CONTROL-NO-REGISTRY');
         }

         return $Response(
            body: 'L4-CONTROL:' . json_encode($Snapshot($Probe->Observability))
         );
      }, GET);

      yield $Router->route('/l4/telemetry/deferred', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response->defer(static function (Response $Response): void {
            $Response->wait();
            $Response(code: 202, body: 'L4-DEFERRED-OK');
         });
      }, GET);

      yield $Router->route('/l4/telemetry/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         if ($Probe->Observability === null) {
            return $Response(code: 500, body: 'L4-EVIDENCE-NO-REGISTRY');
         }

         $evidence = $Snapshot($Probe->Observability);

         // @ Restore suite globals after gathering. Encoder_ and this request's
         //   captured Emitter still finish the current response consistently;
         //   subsequent Security cases get the ordinary test encoder/emitter.
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-EVIDENCE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses): bool|string {
      if (count($responses) !== 5) {
         return 'L4 fixture failed: expected five live responses, got '
            . count($responses) . '.';
      }

      [$setup, $sync, $controlWire, $deferred, $evidenceWire] = $responses;

      $Decode = static function (string $wire, string $prefix): null|array {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator === false) {
            return null;
         }

         $body = substr($wire, $separator + 4);
         if (str_starts_with($body, $prefix) === false) {
            return null;
         }

         $decoded = json_decode(substr($body, strlen($prefix)), true);

         return is_array($decoded) ? $decoded : null;
      };

      if (
         str_contains($setup, 'HTTP/1.1 200 OK') === false
         || str_contains($setup, 'L4-SETUP-OK') === false
         || str_contains($sync, 'HTTP/1.1 200 OK') === false
         || str_contains($sync, 'L4-SYNC-OK') === false
      ) {
         Vars::$labels = ['L4 setup/synchronous control evidence'];
         dump(json_encode(['setup' => $setup, 'sync' => $sync]));

         return 'L4 control failed: setup or the synchronous production-encoder '
            . 'response did not complete.';
      }

      $control = $Decode($controlWire, 'L4-CONTROL:');
      $expectedControl = [
         'requests_total' => 1,
         'in_flight' => 1,
         'duration_count' => 1,
         'responses_2xx' => 1,
      ];
      if ($control !== $expectedControl) {
         Vars::$labels = ['L4 synchronous Telemetry lifecycle control'];
         dump(json_encode(['expected' => $expectedControl, 'actual' => $control]));

         return 'L4 control failed: the synchronous request did not prove that '
            . 'admission, terminal, duration and 2xx accounting were active. '
            . 'Evidence: ' . json_encode([
               'expected' => $expectedControl,
               'actual' => $control,
               'wire' => $controlWire,
            ]);
      }

      if (
         str_contains($deferred, 'HTTP/1.1 202 Accepted') === false
         || str_contains($deferred, 'L4-DEFERRED-OK') === false
      ) {
         Vars::$labels = ['L4 deferred completion control'];
         dump(json_encode($deferred));

         return 'L4 fixture failed: the real deferred Fiber did not complete its '
            . '202 response before metrics were inspected.';
      }

      $evidence = $Decode($evidenceWire, 'L4-EVIDENCE:');
      $expectedVulnerable = [
         'requests_total' => 2,
         'in_flight' => 2,
         'duration_count' => 2,
         'responses_2xx' => 2,
      ];
      $expectedSecure = [
         'requests_total' => 3,
         'in_flight' => 1,
         'duration_count' => 3,
         'responses_2xx' => 3,
      ];

      if ($evidence === $expectedVulnerable) {
         Vars::$labels = ['L4 deferred Telemetry lifecycle evidence'];
         dump(json_encode([
            'synchronous_control' => $control,
            'deferred_evidence' => $evidence,
            'secure_expected' => $expectedSecure,
         ]));

         return 'CONFIRMED L4: the completed deferred 202 response remained in-flight '
            . 'and was omitted from request-total, 2xx-status and duration accounting. '
            . 'Evidence: ' . json_encode([
               'synchronous_control' => $control,
               'deferred_evidence' => $evidence,
               'secure_expected' => $expectedSecure,
            ]);
      }

      if ($evidence !== $expectedSecure) {
         Vars::$labels = ['L4 unexpected Telemetry lifecycle evidence'];
         dump(json_encode([
            'synchronous_control' => $control,
            'deferred_evidence' => $evidence,
            'vulnerable_expected' => $expectedVulnerable,
            'secure_expected' => $expectedSecure,
         ]));

         return 'L4 fixture produced neither the confirmed lifecycle omission nor '
            . 'exactly-once deferred accounting.';
      }

      return true;
   },
);
