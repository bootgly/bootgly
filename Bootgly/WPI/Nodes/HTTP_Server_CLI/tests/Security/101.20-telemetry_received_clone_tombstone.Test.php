<?php

use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * L4 pre-reset Response-clone tombstone regression.
 *
 * The first request on one persistent connection arms a Received listener. The
 * next admission therefore creates an Exchange before Response::reset(), while
 * the reusable Response still carries the prior request's null lifecycle. The
 * listener retains a clone at that boundary. Once the admitted request ends,
 * lazy SSE access through that stale clone must retain a terminal tombstone and
 * must not append an out-of-band head to the connection's following response.
 */
$Probe = new class {
   public null|Emitter $Emitter = null;
   public null|Response $Stale = null;
   public string $error = '';
   /** @var array<string,string> */
   public array $wires = [];
   public int $receivedCalls = 0;
   public string $preResetBody = '';
   public int $staleCalls = 0;
   public bool $staleOpened = false;
};

$Connect = static function (string $hostPort) {
   $Connection = stream_socket_client(
      "tcp://{$hostPort}",
      $errorCode,
      $errorMessage,
      timeout: 5,
   );
   if ($Connection === false) {
      throw new RuntimeException(
         "L4-101.20 persistent client connect failed: {$errorCode} {$errorMessage}"
      );
   }
   stream_set_blocking($Connection, false);

   return $Connection;
};

$Write = static function ($Connection, string $request): void {
   $offset = 0;
   while ($offset < strlen($request)) {
      $written = fwrite($Connection, substr($request, $offset));
      if ($written === false || $written === 0) {
         break;
      }
      $offset += $written;
   }

   if ($offset !== strlen($request)) {
      throw new RuntimeException('L4-101.20 request was not sent completely.');
   }
};

$Read = static function ($Connection, float $grace = 0.0): string {
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
         if ($chunk === false) {
            break;
         }
         if ($chunk !== '') {
            $wire .= $chunk;
         }
         else if (feof($Connection)) {
            break;
         }
      }

      if ($completeAt === null) {
         $separator = strpos($wire, "\r\n\r\n");
         if ($separator !== false) {
            $matches = [];
            $finite = preg_match(
               '/\r\nContent-Length:[ \t]*(\d+)[ \t]*\r\n/i',
               substr($wire, 0, $separator + 2),
               $matches,
            ) === 1;
            if (
               $finite === false
               || strlen($wire) >= $separator + 4 + (int) $matches[1]
            ) {
               $completeAt = microtime(true);
            }
         }
      }

      // ! The vulnerable SSE head can precede the legitimate final response.
      //   Keep reading briefly after the first complete head/body boundary.
      if ($completeAt !== null && microtime(true) - $completeAt >= $grace) {
         break;
      }
   }

   return $wire;
};

return new Test(
   description: 'A Response cloned by Received before reset must retain the admitted tombstone',
   Separator: new Separator(line: true),

   requests: [
      static fn (): string => "GET /l4/received-clone/setup HTTP/1.1\r\n"
         . "Host: localhost\r\n\r\n",

      static function (string $hostPort, int $testIndex) use (
         $Connect,
         $Probe,
         $Read,
         $Write,
      ): string {
         try {
            $Connection = $Connect($hostPort);

            // @ No Received listener exists at this admission. Its handler
            //   installs one for the next request, leaving Response's lifecycle
            //   slot null while preserving this exact keep-alive Package.
            $request = "GET /l4/received-clone/arm HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['arm'] = $Read($Connection);

            // @ Admission now publishes an Exchange against the reused Request.
            //   The listener clones Response before reset binds that Exchange.
            $request = "GET /l4/received-clone/retain HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['retain'] = $Read($Connection);

            // ! The retained clone still points at this transport. Secure code
            //   rejects its lazy SSE open and emits only the current 202.
            $request = "GET /l4/received-clone/trigger HTTP/1.1\r\n"
               . "Host: localhost\r\n"
               . "X-Bootgly-Test: {$testIndex}\r\n\r\n";
            $Write($Connection, $request);
            $Probe->wires['trigger'] = $Read($Connection, 0.30);
            fclose($Connection);
         }
         catch (Throwable $Throwable) {
            if (isset($Connection) && is_resource($Connection)) {
               fclose($Connection);
            }
            $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         return "GET /l4/received-clone/evidence HTTP/1.1\r\n"
            . "Host: localhost\r\n\r\n";
      },
   ],

   response: static function (
      Request $Request,
      Response $Response,
      Router $Router,
   ) use ($Probe): Generator {
      yield $Router->route('/l4/received-clone/setup', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Probe->Emitter = Emitter::$Instance;
         Emitter::$Instance = new Emitter;

         return $Response(body: 'L4-101.20-SETUP');
      }, GET);

      yield $Router->route('/l4/received-clone/arm', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         Emitter::$Instance->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use ($Probe): void {
               $CurrentRequest = $Emission->payload[0] ?? null;
               if (
                  $CurrentRequest instanceof Request === false
                  || $CurrentRequest->URI !== '/l4/received-clone/retain'
               ) {
                  return;
               }

               $Probe->receivedCalls++;
               $Probe->Stale = clone Server::$Response;
               $Probe->preResetBody = $Probe->Stale->Body->raw;
            },
         );

         return $Response(code: 203, body: 'L4-101.20-ARM-203');
      }, GET);

      yield $Router->route('/l4/received-clone/retain', static function (
         Request $Request,
         Response $Response,
      ): Response {
         return $Response(code: 201, body: 'L4-101.20-RETAIN-201');
      }, GET);

      yield $Router->route('/l4/received-clone/trigger', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $Stale = $Probe->Stale;
         if ($Stale === null) {
            return $Response(code: 500, body: 'L4-101.20-MISSING-CLONE');
         }

         $Probe->staleCalls++;
         $SSE = $Stale->SSE;
         $SSE->heartbeat = 0;
         $SSE->open();
         $Probe->staleOpened = $SSE->opened;

         return $Response(code: 202, body: 'L4-101.20-TRIGGER-202');
      }, GET);

      yield $Router->route('/l4/received-clone/evidence', static function (
         Request $Request,
         Response $Response,
      ) use ($Probe): Response {
         $evidence = [
            'received_calls' => $Probe->receivedCalls,
            'pre_reset_body' => $Probe->preResetBody,
            'stale_calls' => $Probe->staleCalls,
            'stale_opened' => $Probe->staleOpened,
         ];

         $Probe->Stale = null;
         if ($Probe->Emitter !== null) {
            Emitter::$Instance = $Probe->Emitter;
         }

         return $Response(body: 'L4-101.20-EVIDENCE:' . json_encode($evidence));
      }, GET);
   },

   test: static function (array $responses) use ($Probe): bool|string {
      if (
         count($responses) !== 2
         || str_contains($responses[0] ?? '', 'L4-101.20-SETUP') === false
      ) {
         return 'L4-101.20 control failed: native setup response missing.';
      }
      if ($Probe->error !== '') {
         return 'L4-101.20 fixture failed: ' . $Probe->error;
      }

      $failures = [];
      $arm = $Probe->wires['arm'] ?? '';
      if (
         substr_count($arm, 'HTTP/1.1 ') !== 1
         || str_contains($arm, 'HTTP/1.1 203 Non-Authoritative Information') === false
         || str_contains($arm, 'L4-101.20-ARM-203') === false
      ) {
         $failures['arm_control'] = $arm;
      }

      $retain = $Probe->wires['retain'] ?? '';
      if (
         substr_count($retain, 'HTTP/1.1 ') !== 1
         || str_contains($retain, 'HTTP/1.1 201 Created') === false
         || str_contains($retain, 'L4-101.20-RETAIN-201') === false
      ) {
         $failures['retain_control'] = $retain;
      }

      $trigger = $Probe->wires['trigger'] ?? '';
      if (
         substr_count($trigger, 'HTTP/1.1 ') !== 1
         || str_contains($trigger, 'HTTP/1.1 202 Accepted') === false
         || str_contains($trigger, 'L4-101.20-TRIGGER-202') === false
         || str_contains($trigger, 'Content-Type: text/event-stream')
      ) {
         $failures['stale_trigger_wire'] = $trigger;
      }

      $wire = $responses[1] ?? '';
      $separator = strpos($wire, "\r\n\r\n");
      $body = $separator === false ? '' : substr($wire, $separator + 4);
      $prefix = 'L4-101.20-EVIDENCE:';
      $evidence = str_starts_with($body, $prefix)
         ? json_decode(substr($body, strlen($prefix)), true)
         : null;
      $expected = [
         'received_calls' => 1,
         'pre_reset_body' => 'L4-101.20-ARM-203',
         'stale_calls' => 1,
         'stale_opened' => false,
      ];
      if ($evidence !== $expected) {
         $failures['boundary_evidence'] = [
            'expected' => $expected,
            'actual' => $evidence,
         ];
      }

      if ($failures !== []) {
         return 'L4-101.20 CONFIRMED: a Received listener retained a pre-reset '
            . 'Response clone without the admitted Exchange tombstone, allowing '
            . 'late SSE bytes on a reused connection. Evidence: '
            . json_encode($failures);
      }

      return true;
   },
);
