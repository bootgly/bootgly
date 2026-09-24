<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


// ? Event-driven and batch clients cannot re-dial a redirect: a hop to another
//   connection is their final answer, and a refused hop still reaches them.
return new Test(
   description: 'It should deliver redirect outcomes to event-driven and batch callers, never drop them',
   test: function () {
      $A = 19912;
      $B = 19913;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli107');
      $serve = require __DIR__ . '/fixtures/origin.php';

      // ! One event-driven client per leg: the first callback stops its loop
      $leg = static function (string $method, string $URI, null|Closure $Redirection = null) use ($A): array {
         $fired = [];

         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->maxRedirects = 2;
         $Client->Redirection = $Redirection;
         $Client->on(Events::ResponseReceive, function ($Request, $Response) use (&$fired, $Client): void {
            $fired[] = "{$Response->code} {$Response->status} {$Request->method} {$Request->URI}";
            $Client->Event->loop = false; // @phpstan-ignore-line
         });

         $Client->request($method, $URI, body: $method === 'POST' ? 'secret=body' : null);
         $Socket = $Client->connect();

         // ! A hang must fail the assertion, never wedge the suite
         $Client->Event->defer(microtime(true) + 4.0, function () use ($Client): void {
            $Client->Event->loop = false; // @phpstan-ignore-line
         });
         if ($Socket !== false) {
            $Client->Event->loop();
         }

         return $fired;
      };

      $PIDs = [];
      try {
         $PIDs[] = $serve("127.0.0.1:{$A}", static function (array $request) use ($B): string {
            if (str_starts_with($request['target'], '/loop')) {
               $next = (int) substr($request['target'], 5) + 1;

               return "HTTP/1.1 302 Found\r\nLocation: /loop{$next}\r\nContent-Length: 0\r\n\r\n";
            }
            // ! The batch sibling keeps the reactor running past the other request's deadline
            if ($request['target'] === '/slow') {
               usleep(600000);
            }

            return match ($request['target']) {
               '/cross' => "HTTP/1.1 307 Temporary Redirect\r\nLocation: http://127.0.0.1:{$B}/landing\r\n"
                  . "Content-Length: 0\r\nConnection: close\r\n\r\n",
               '/gopher' => "HTTP/1.1 307 Temporary Redirect\r\nLocation: gopher://127.0.0.1/x\r\n"
                  . "Content-Length: 0\r\nConnection: close\r\n\r\n",
               // ! The same origin, but on a connection that closes: another dial
               '/close' => "HTTP/1.1 302 Found\r\nLocation: /landing\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
               default => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK",
            };
         }, $log);
         $PIDs[] = $serve("127.0.0.1:{$B}", static fn (array $request): string =>
            "HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nOK", $log);
         usleep(200000);

         $cross = $leg('GET', '/cross');
         $close = $leg('GET', '/close');
         $gopher = $leg('GET', '/gopher');
         $loop = $leg('GET', '/loop0');
         // ! Refused by the policy: the callback sees the Request as it was sent
         $refused = $leg('POST', '/loop0', static fn (string $host, int $port, bool $secure): bool => false);
         // ! The policy is asked even for a hop the mode would only deliver
         $pinned = $leg('GET', '/cross', static fn (string $host, int $port, bool $secure): bool => $port === $A);

         // @ Batch: a cross-origin hop is the final answer, and no stale deadline rewrites it
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->batch();
         $Client->timeout = 0.2;
         $Batched = $Client->request('GET', '/cross');
         $Closed = $Client->request('GET', '/close');
         $Client->timeout = 3;
         $Sibling = $Client->request('GET', '/slow');
         $Client->drain();
         $batched = [
            $Batched->code,
            $Batched->Header->get('Location'),
            $Closed->code,
            $Closed->Header->get('Location'),
            $Sibling->code,
         ];

         $targets = array_map(
            static fn (string $line): string => ((array) json_decode($line, true))['target'],
            file($log, FILE_IGNORE_NEW_LINES) ?: []
         );
      }
      finally {
         foreach ($PIDs as $PID) {
            posix_kill($PID, SIGTERM);
            pcntl_waitpid($PID, $status);
         }
         @unlink($log);
      }

      yield assert(
         assertion: $cross === ['307 Temporary Redirect GET /cross'],
         description: 'Event-driven: a cross-origin hop reaches the callback as the final 3xx: ' . json_encode($cross)
      );

      yield assert(
         assertion: $close === ['302 Found GET /close'],
         description: 'Event-driven: a same-origin hop on a closing connection reaches the callback as the final 3xx: '
            . json_encode($close)
      );

      yield assert(
         assertion: in_array('/landing', $targets, true) === false,
         description: 'Neither mode dials the next leg: ' . json_encode($targets)
      );

      yield assert(
         assertion: $gopher === ['0 Redirect Refused GET /gopher'],
         description: 'Event-driven: a refused Location reaches the callback: ' . json_encode($gopher)
      );

      yield assert(
         assertion: $loop === ['0 Too Many Redirects GET /loop2'],
         description: 'Event-driven: the hop cap reaches the callback: ' . json_encode($loop)
      );

      yield assert(
         assertion: $refused === ['0 Redirect Refused POST /loop0'],
         description: 'The policy runs before the Request is rewritten: ' . json_encode($refused)
      );

      yield assert(
         assertion: $pinned === ['0 Redirect Refused GET /cross'],
         description: 'A hop the policy refuses is refused in every mode: ' . json_encode($pinned)
      );

      yield assert(
         assertion: $batched === [307, "http://127.0.0.1:{$B}/landing", 302, '/landing', 200],
         description: 'Batch: hops that need another connection stay final 3xx answers: ' . json_encode($batched)
      );
   }
);
