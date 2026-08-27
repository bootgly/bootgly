<?php

use const Bootgly\WPI;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Events\Cancelling;
use Bootgly\ACI\Events\Loops;
use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Observability;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events as WPIEvents;
use Bootgly\WPI\Endpoints\Servers\Encoder;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Telemetry;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 nested scheduler-rejection upload rollback regression.
 *
 * The outer deferred generation owns a captured upload. Its nested child moves
 * that ownership immediately before running, then suspends and is rejected by
 * a cancellation-aware scheduler. The nested defer must restore the upload to
 * its parent before throwing LogicException, so the parent may catch, select
 * one 202 response and deterministically unlink the file in its loop cleanup.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Encoder $Encoder = null;
   public null|Observability $Observability = null;
   public string $error = '';
   public string $wire = '';
   public null|string $temp = null;
   public int $outerWork = 0;
   public int $childBeforeWait = 0;
   public int $childAfterWait = 0;
   public int $catches = 0;
   public null|string $caughtClass = null;
   public null|string $caughtMessage = null;
   public bool $parentRetained = false;
};

$Snapshot = static function (Observability $Observability): array {
   $metrics = $Observability->gather()->metrics;
   $responses = ['2xx' => 0, '4xx' => 0, '5xx' => 0];
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
      'responses_4xx' => $responses['4xx'],
      'responses_5xx' => $responses['5xx'],
   ];
};

$Build = static function (object $Inner): WPIEvents&Loops&Scheduler&Cancelling {
   return new class($Inner) implements WPIEvents, Loops, Scheduler, Cancelling {
      private object $Inner;

      public function __construct (object $Inner)
      {
         $this->Inner = $Inner;
      }

      public function add ($Socket, int $flag, mixed $payload): bool
      {
         return $this->Inner->add($Socket, $flag, $payload);
      }

      public function del ($Socket, int $flag): bool
      {
         return $this->Inner->del($Socket, $flag);
      }

      public function loop (): void
      {
         $this->Inner->loop();
      }

      public function destroy (): void
      {
         // This transient facade never owns the underlying reactor.
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
         return $this->Inner->defer($deadline, $Callback);
      }

      public function cancel (int $ID): bool
      {
         return $this->Inner->cancel($ID);
      }

      public function interrupt (Fiber $Fiber, Throwable $Throwable): bool
      {
         return $this->Inner->interrupt($Fiber, $Throwable);
      }

      public function bind (
         Fiber $Fiber,
         Closure $Enter,
         Closure $Leave,
      ): void {
         // Response binds around the initial inline start itself. No rejected
         // continuation may enter the underlying scheduler.
      }
   };
};

$Read = static function ($Connection): string {
   stream_set_blocking($Connection, false);
   $wire = '';
   $completeAt = null;
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
         $wire .= $chunk;
      }

      $separator = strpos($wire, "\r\n\r\n");
      if ($separator !== false && $completeAt === null) {
         $matches = [];
         if (
            preg_match(
               '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
               substr($wire, 0, $separator + 2),
               $matches,
            ) === 1
            && strlen($wire) >= $separator + 4 + (int) $matches[1]
         ) {
            $completeAt = microtime(true);
         }
      }
      if ($completeAt !== null && microtime(true) - $completeAt >= 0.20) {
         break;
      }
   }

   return $wire;
};

return new Test(
   description: 'Nested scheduler rejection must restore parent upload ownership before one 202',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/nested-reject/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Probe,
         $Read,
      ): string {
         try {
            $Connection = stream_socket_client(
               "tcp://{$hostPort}",
               $errorCode,
               $errorMessage,
               timeout: 5,
            );
            if ($Connection === false) {
               throw new RuntimeException(
                  "L4 nested-rejection connect failed: {$errorCode} {$errorMessage}"
               );
            }

            $request = "GET /l4/nested-reject/target HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $offset = 0;
            while ($offset < strlen($request)) {
               $written = fwrite($Connection, substr($request, $offset));
               if ($written === false || $written === 0) {
                  break;
               }
               $offset += $written;
            }
            if ($offset !== strlen($request)) {
               throw new RuntimeException(
                  'L4 nested-rejection request was not sent completely.'
               );
            }

            $Probe->wire = $Read($Connection);
            fclose($Connection);
         }
         catch (Throwable $Throwable) {
            if (isset($Connection) && is_resource($Connection)) {
               fclose($Connection);
            }
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/nested-reject/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Build, $Probe, $Snapshot): Generator {
      yield $Router->route('/l4/nested-reject/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         $Probe->Encoder = Server::$Encoder;
         $Probe->Observability = new Observability(collectors: false);

         Emitter::$Instance = new Emitter;
         new Telemetry($Probe->Observability)->boot();
         Server::$Encoder = new Encoder_;

         return $Response(body: 'L4-NESTED-REJECT-SETUP');
      }, GET);

      yield $Router->route('/l4/nested-reject/target', static function (
         Request $Request,
         Response $Response,
      ) use ($Build, $Probe): Response {
         $temp = tempnam(sys_get_temp_dir(), 'bootgly-l4-nested-');
         if ($temp === false || file_put_contents($temp, 'L4-NESTED') !== 9) {
            throw new RuntimeException(
               'Could not materialize the nested-rejection upload fixture.'
            );
         }
         $Probe->temp = $temp;
         $Request->files = [
            'upload' => [
               'name' => 'nested.txt',
               'type' => 'text/plain',
               'tmp_name' => $temp,
               'error' => 0,
               'size' => 9,
            ],
         ];

         $OldEvent = TCP_Server_CLI::$Event;
         TCP_Server_CLI::$Event = $Build($OldEvent);
         try {
            return $Response->defer(static function (Response $Outer) use (
               $Probe,
               $temp,
            ): void {
               $Probe->outerWork++;

               try {
                  $Outer->defer(static function (Response $Child) use (
                     $Probe,
                  ): void {
                     $Probe->childBeforeWait++;
                     $Child->wait();
                     $Probe->childAfterWait++;
                  });
               }
               catch (LogicException $Exception) {
                  $Probe->catches++;
                  $Probe->caughtClass = $Exception::class;
                  $Probe->caughtMessage = $Exception->getMessage();

                  $ParentRequest = WPI->Request;
                  $Probe->parentRetained = $ParentRequest instanceof Request
                     && ($ParentRequest->files['upload']['tmp_name'] ?? null) === $temp
                     && is_file($temp);
               }

               $Outer(code: 202, body: 'L4-NESTED-REJECT-PARENT-202');
            });
         }
         finally {
            TCP_Server_CLI::$Event = $OldEvent;
         }
      }, GET);

      yield $Router->route('/l4/nested-reject/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe, $Snapshot): Response {
         $Observability = $Probe->Observability;
         $evidence = [
            'outer_work' => $Probe->outerWork,
            'child_before_wait' => $Probe->childBeforeWait,
            'child_after_wait' => $Probe->childAfterWait,
            'catches' => $Probe->catches,
            'caught_class' => $Probe->caughtClass,
            'caught_message' => $Probe->caughtMessage,
            'parent_retained' => $Probe->parentRetained,
            'file_exists' => is_string($Probe->temp) && is_file($Probe->temp),
            'metrics' => $Observability === null ? null : $Snapshot($Observability),
         ];

         // @ Never leak the fixture when the regression fails.
         if (is_string($Probe->temp) && is_file($Probe->temp)) {
            unlink($Probe->temp);
         }
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }
         if ($Probe->Encoder !== null) {
            Server::$Encoder = $Probe->Encoder;
         }

         return $Response(body: 'L4-NESTED-REJECT:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-NESTED-REJECT-SETUP') === false
      ) {
         return 'L4 nested-rejection setup/harness response failed.';
      }
      if ($Probe->error !== '') {
         return 'L4 nested-rejection fixture failed: ' . $Probe->error;
      }
      if (
         substr_count($Probe->wire, 'HTTP/1.1 ') !== 1
         || str_contains($Probe->wire, 'HTTP/1.1 202 Accepted') === false
         || str_contains($Probe->wire, 'L4-NESTED-REJECT-PARENT-202') === false
      ) {
         return 'L4 nested scheduler rejection did not preserve one parent '
            . '202 wire: ' . json_encode($Probe->wire);
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-NESTED-REJECT:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'outer_work' => 1,
         'child_before_wait' => 1,
         'child_after_wait' => 0,
         'catches' => 1,
         'caught_class' => LogicException::class,
         'caught_message' => 'HTTP deferred execution was rejected by the scheduler.',
         'parent_retained' => true,
         'file_exists' => false,
         'metrics' => [
            'requests_total' => 1,
            'in_flight' => 1,
            'duration_count' => 1,
            'responses_2xx' => 1,
            'responses_4xx' => 0,
            'responses_5xx' => 0,
         ],
      ];
      if ($evidence !== $expected) {
         return 'L4 regression: nested scheduler rejection did not restore '
            . 'the parent upload before its caught LogicException and sole '
            . '202 completion: ' . json_encode([
               'expected' => $expected,
               'actual' => $evidence,
            ]);
      }

      return true;
   },
);
