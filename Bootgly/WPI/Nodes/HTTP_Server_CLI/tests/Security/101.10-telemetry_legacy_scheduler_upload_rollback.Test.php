<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\ACI\Events\Contextualizing;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Events as WPIEvents;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 legacy-scheduler compatibility and upload rollback regression.
 *
 * Contextualizing predates explicit cancellation. An unobserved deferred job
 * that completes inline remains compatible with that contract. Once Telemetry
 * admits an exchange, lack of cancellation support is rejected before a Fiber
 * can be orphaned; rejection must restore upload ownership to the live Request
 * so the encoder's ordinary cleanup unlinks the temporary file.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   public string $rollbackWire = '';
   public null|string $temp = null;
   public int $rollbackWork = 0;
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['2xx' => 0, '5xx' => 0];
   foreach (($metrics['http_responses_total']['series'] ?? []) as $series) {
      $class = $series['labels']['class'] ?? null;
      if (is_string($class) && array_key_exists($class, $responses)) {
         $responses[$class] = (int) ($series['value'] ?? 0);
      }
   }

   return [
      'requests_total' => (int) ($metrics['http_requests_total']['series'][0]['value'] ?? 0),
      'in_flight' => (int) ($metrics['http_requests_in_flight']['series'][0]['value'] ?? 0),
      'duration_count' => (int) (
         $metrics['http_request_duration_seconds']['series'][0]['count'] ?? 0
      ),
      'responses_2xx' => $responses['2xx'],
      'responses_5xx' => $responses['5xx'],
   ];
};

$Build = static function (): WPIEvents&Loops&Scheduler&Contextualizing {
   return new class implements WPIEvents, Loops, Scheduler, Contextualizing {
      public function add ($Socket, int $flag, mixed $payload): bool
      {
         return true;
      }

      public function del ($Socket, int $flag): bool
      {
         return true;
      }

      public function loop (): void
      {
         // Inline-completing compatibility work needs no reactor.
      }

      public function destroy (): void
      {
         // No retained state.
      }

      public function schedule (
         Fiber $Fiber,
         mixed $value = null,
         int $flag = self::SCHEDULE_READ,
      ): bool {
         return false;
      }

      public function defer (float|int $deadline, Closure $Callback): int
      {
         return 1;
      }

      public function cancel (int $ID): bool
      {
         return true;
      }

      public function bind (
         Fiber $Fiber,
         Closure $Enter,
         Closure $Leave,
      ): void {
         // Response installs context inline around start/resume.
      }
   };
};

return new Test(
   description: 'Legacy scheduler rejection must preserve compatibility and roll back upload ownership',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/legacy/bootstrap HTTP/1.1\r\nHost: localhost\r\n\r\n",

      static fn (): string => "GET /l4/legacy/setup HTTP/1.1\r\nHost: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use ($Probe): string {
         try {
            $Connection = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCode,
               $errorMessage,
               timeout: 5,
            );
            if ($Connection === false) {
               throw new RuntimeException(
                  "L4 rollback connect failed: {$errorCode} {$errorMessage}"
               );
            }
            stream_set_blocking($Connection, false);
            $request = "GET /l4/legacy/rollback HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            fwrite($Connection, $request);

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
               $read = [$Connection];
               $write = null;
               $except = null;
               $ready = stream_select($read, $write, $except, 0, 50000);
               if ($ready === false) {
                  break;
               }
               if ($ready === 1) {
                  $chunk = fread($Connection, 8192);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $Probe->rollbackWire .= $chunk;
               }

               $separator = strpos($Probe->rollbackWire, "\r\n\r\n");
               if ($separator === false) {
                  continue;
               }
               $matches = [];
               if (
                  preg_match(
                     '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
                     substr($Probe->rollbackWire, 0, $separator + 2),
                     $matches,
                  ) === 1
                  && strlen($Probe->rollbackWire) >= $separator + 4 + (int) $matches[1]
               ) {
                  break;
               }
            }
            fclose($Connection);
         }
         catch (Throwable $Throwable) {
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/legacy/evidence HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Build, $Probe, $Snapshot): Generator {
      yield $Router->route('/l4/legacy/bootstrap', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         Emitter::$Instance = new Emitter;
         // ! A conventional public listener needs an internal Exchange for
         //   clone tombstones, but does not require scheduler cancellation.
         Emitter::$Instance->listen(
            RequestEvents::Received,
            static function (): void {
               // Compatibility-only listener.
            },
         );

         return $Response(body: 'L4-LEGACY-BOOTSTRAP');
      }, GET);

      yield $Router->route('/l4/legacy/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Build, $Probe): Response {
         // ? Compatibility control runs before installing Telemetry. Its work
         //   completes inline, so the legacy scheduler never has to retain it.
         $OldEvent = TCP_Server_CLI::$Event;
         TCP_Server_CLI::$Event = $Build();
         try {
            return $Response->defer(static function (Response $Deferred) use (
               $Probe,
            ): void {
               $Probe->Observability = new Observability(collectors: false);
               new Telemetry($Probe->Observability)->boot();
               Server::$Encoder = new Encoder_;

               $Deferred(code: 202, body: 'L4-LEGACY-DEFERRED-202');
            });
         }
         finally {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }, GET);

      yield $Router->route('/l4/legacy/rollback', static function (
         Request $Request,
         Response $Response,
      ) use ($Build, $Probe): Response {
         $temp = tempnam(sys_get_temp_dir(), 'bootgly-l4-upload-');
         if ($temp === false || file_put_contents($temp, 'L4-UPLOAD') !== 9) {
            throw new RuntimeException('Could not materialize the rollback upload fixture.');
         }
         $Probe->temp = $temp;
         $Request->files = [
            'upload' => [
               'name' => 'l4.txt',
               'type' => 'text/plain',
               'tmp_name' => $temp,
               'error' => 0,
               'size' => 9,
            ],
         ];

         $OldEvent = TCP_Server_CLI::$Event;
         TCP_Server_CLI::$Event = $Build();
         try {
            return $Response->defer(static function () use ($Probe): void {
               $Probe->rollbackWork++;
            });
         }
         finally {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }, GET);

      yield $Router->route('/l4/legacy/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'file_exists' => is_string($Probe->temp) && is_file($Probe->temp),
            'rollback_work' => $Probe->rollbackWork,
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         // @ Cleanup is unconditional after recording evidence; a vulnerable
         //   run must not leak its fixture into later Security cases.
         if (is_string($Probe->temp) && is_file($Probe->temp)) {
            unlink($Probe->temp);
         }
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-LEGACY:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 3
         || str_contains($responses[0] ?? '', 'L4-LEGACY-BOOTSTRAP') === false
         || str_contains($responses[1] ?? '', 'HTTP/1.1 202 Accepted') === false
         || str_contains($responses[1] ?? '', 'L4-LEGACY-DEFERRED-202') === false
      ) {
         return 'L4 legacy Contextualizing scheduler compatibility control failed: '
            . json_encode($responses);
      }
      if ($Probe->error !== '') {
         return 'L4 upload rollback fixture failed: ' . $Probe->error;
      }
      if (
         substr_count($Probe->rollbackWire, 'HTTP/1.1 ') !== 1
         || str_contains(
            $Probe->rollbackWire,
            'HTTP/1.1 500 Internal Server Error',
         ) === false
      ) {
         return 'L4 observed legacy-scheduler rejection did not preserve the '
            . 'single Catcher 500 control: ' . json_encode($Probe->rollbackWire);
      }

      $wire = $responses[2] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-LEGACY:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'file_exists' => false,
         'rollback_work' => 0,
         'metrics' => [
            'requests_total' => 1,
            'in_flight' => 1,
            'duration_count' => 1,
            'responses_2xx' => 0,
            'responses_5xx' => 1,
         ],
      ];
      if ($evidence !== $expected) {
         return 'L4 regression: observed defer rejection on a legacy scheduler '
            . 'did not roll upload ownership back for deterministic cleanup: '
            . json_encode([
               'expected' => $expected,
               'actual' => $evidence,
            ]);
      }

      return true;
   },
);
