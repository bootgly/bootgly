<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


// ? An event-driven client re-sends the Request object it already holds: every
//   new cycle is a new logical request, and its redirect budget starts over.
return new Test(
   description: 'It should give every event-driven cycle a fresh redirect budget',
   test: function () {
      $A = 19918;
      $log = (string) tempnam(sys_get_temp_dir(), 'hcli109');
      $serve = require __DIR__ . '/fixtures/origin.php';

      // ! The second time /b is asked, it redirects too
      $PID = $serve("127.0.0.1:{$A}", static function (array $request): string {
         static $seen = [];
         $seen[$request['target']] = ($seen[$request['target']] ?? 0) + 1;

         return match (true) {
            $request['target'] === '/a' => "HTTP/1.1 302 Found\r\nLocation: /b\r\nContent-Length: 0\r\n\r\n",
            $request['target'] === '/b' && $seen['/b'] > 1 => "HTTP/1.1 302 Found\r\nLocation: /c\r\nContent-Length: 0\r\n\r\n",
            default => "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK",
         };
      }, $log);
      usleep(200000);

      $fired = [];
      try {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $A));
         $Client->timeout = 3;
         $Client->maxRedirects = 1;
         $Client->on(Events::ResponseReceive, function ($Request, $Response) use (&$fired, $Client): void {
            $fired[] = "{$Response->code} {$Request->URI}";

            // @ Cycle 2 asks the URI the first cycle landed on — the same Request object
            if (count($fired) === 1) {
               $Client->request('GET', '/b');

               return;
            }
            $Client->Event->loop = false; // @phpstan-ignore-line
         });

         $Client->request('GET', '/a');
         $Socket = $Client->connect();
         $Client->Event->defer(microtime(true) + 4.0, function () use ($Client): void {
            $Client->Event->loop = false; // @phpstan-ignore-line
         });
         if ($Socket !== false) {
            $Client->Event->loop();
         }
      }
      finally {
         posix_kill($PID, SIGTERM);
         pcntl_waitpid($PID, $status);
         @unlink($log);
      }

      yield assert(
         assertion: $fired === ['200 /b', '200 /c'],
         description: 'Each cycle follows its own redirect within maxRedirects = 1: ' . json_encode($fired)
      );
   }
);
