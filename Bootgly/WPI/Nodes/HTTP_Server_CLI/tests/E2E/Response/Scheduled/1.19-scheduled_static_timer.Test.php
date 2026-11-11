<?php

use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * An application's static `Timer` keeps working in a worker whose loop owns
 * the signals: SIGALRM is one of the signals the worker takes over on its
 * loop, so a task a route arms with `Timer::add()` still runs.
 *
 * One keep-alive connection arms the timer and then asks the same worker
 * whether it fired — the runner's own request is only a ping.
 */
$Worker = new class {
   public bool $armed = false;
   public int $fired = 0;
};
$Evidence = new class {
   public string $armed = '';
   public string $fired = '';
   public float $waited = 0.0;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   requests: [
      function (string $hostPort, int $testIndex = 0) use ($Evidence): string {
         /**
          * One request on the connection; the Content-Length body, or ''.
          *
          * @param resource $Connection
          */
         $ask = static function ($Connection, string $path) use ($testIndex): string {
            fwrite($Connection, "GET {$path} HTTP/1.1\r\nHost: localhost\r\nX-Bootgly-Test: {$testIndex}\r\n\r\n");

            $buffer = '';
            $deadline = hrtime(true) + 3_000_000_000;
            while (hrtime(true) < $deadline) {
               $chunk = fread($Connection, 8192);
               if ($chunk !== false && $chunk !== '') {
                  $buffer .= $chunk;
               }
               $head = strpos($buffer, "\r\n\r\n");
               if (
                  $head !== false
                  && preg_match('/^Content-Length:\s*(\d+)/mi', $buffer, $Match) === 1
                  && strlen($buffer) >= $head + 4 + (int) $Match[1]
               ) {
                  return substr($buffer, $head + 4, (int) $Match[1]);
               }
               if ($chunk === false || feof($Connection)) {
                  break;
               }
            }

            return '';
         };

         $Connection = stream_socket_client("tcp://{$hostPort}", $errno, $errstr, timeout: 5);
         if ($Connection === false) {
            $Evidence->error = "connect failed: {$errstr}";
         }
         else {
            stream_set_timeout($Connection, 0, 100_000);
            $Evidence->armed = $ask($Connection, '/timer/arm');

            // @ The alarm has whole-second granularity: ask until it fired
            $started = hrtime(true);
            do {
               usleep(100_000);
               $Evidence->fired = $ask($Connection, '/timer/fired');
               $Evidence->waited = (hrtime(true) - $started) / 1_000_000_000;
            } while ($Evidence->fired === 'fired:0' && $Evidence->waited < 4.0);
            fclose($Connection);
         }

         return "GET /timer/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) use ($Worker)
   {
      yield $Router->route('/timer/arm', function (Request $Request, Response $Response) use ($Worker) {
         if ($Worker->armed === false) {
            $Worker->armed = true;
            Timer::add(interval: 1, handler: static function () use ($Worker): void {
               $Worker->fired++;
            }, persistent: false);
         }

         return $Response(body: 'armed');
      }, GET);

      yield $Router->route('/timer/fired', function (Request $Request, Response $Response) use ($Worker) {
         return $Response(body: "fired:{$Worker->fired}");
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(body: 'pong');
      });
   },

   test: function (array $responses) use ($Evidence) {
      $observed = json_encode([
         'error' => $Evidence->error,
         'armed' => $Evidence->armed,
         'fired' => $Evidence->fired,
         'waited' => $Evidence->waited,
      ]);

      if ($Evidence->error !== '' || $Evidence->armed !== 'armed') {
         return "The route could not arm the static Timer: {$observed}";
      }
      // ? Once, after about a second: a one-shot task, never a burst
      if ($Evidence->fired !== 'fired:1' || $Evidence->waited < 0.5) {
         return "A Timer::add() task armed by a route did not run once in its worker: {$observed}";
      }
      if (str_contains($responses[0], 'pong') === false) {
         return 'The worker stopped answering after the timer ran';
      }

      return true;
   }
);
