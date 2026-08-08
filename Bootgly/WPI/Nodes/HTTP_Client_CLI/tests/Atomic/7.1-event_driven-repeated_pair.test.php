<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Specification(
   description: 'It should encode every event-driven leg from its own request, not the first one',
   test: function () {
      $port = 19881;
      $paths = ['/one', '/one', '/two', '/two', '/three', '/three'];

      // ! A keep-alive origin that echoes back the path it actually received
      $forked = pcntl_fork();
      if ($forked === 0) {
         $Server = @stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $errstr);
         if ($Server === false) {
            exit(1);
         }

         $Peer = @stream_socket_accept($Server, 10);
         if ($Peer !== false) {
            while (true) {
               $input = '';
               while (strpos($input, "\r\n\r\n") === false) {
                  $chunk = @fread($Peer, 65535);
                  if ($chunk === false || $chunk === '') {
                     break 2;
                  }
                  $input .= $chunk;
               }

               $path = '(none)';
               if (preg_match('/^[A-Z]+ (\S+) HTTP/m', $input, $matches) === 1) {
                  $path = $matches[1];
               }

               $body = "served:{$path}";
               @fwrite(
                  $Peer,
                  "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body) . "\r\n\r\n{$body}"
               );
            }
         }

         @fclose($Server);
         exit(0);
      }

      usleep(200000);

      // ! Drive the client the way the benchmark worker does: queue the next
      //   request from inside the response hook
      $index = 0;
      $served = [];

      $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $Client->configure('127.0.0.1', $port);
      $Client->timeout = 8;

      $Client->on(
         Events::ResponseReceive,
         function ($Request, $Response) use (&$index, &$served, $paths, $Client): void {
            $served[] = $Response->Body->raw;

            if ($index < count($paths)) {
               $Client->request('GET', $paths[$index++]);

               return;
            }

            HTTP_Client_CLI::$Event->loop = false; // @phpstan-ignore-line
         }
      );

      $Client->request('GET', $paths[$index++]);
      $Socket = $Client->connect();

      // ! A hang must fail the assertion, never wedge the suite
      HTTP_Client_CLI::$Event->defer(microtime(true) + 8.0, function (): void {
         HTTP_Client_CLI::$Event->loop = false; // @phpstan-ignore-line
      });

      if ($Socket !== false) {
         HTTP_Client_CLI::$Event->loop();
      }

      posix_kill($forked, SIGTERM);
      pcntl_waitpid($forked, $status);

      // ! Leave event-driven mode behind for the rest of the suite
      new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);

      $mismatches = [];
      foreach ($paths as $leg => $path) {
         $got = $served[$leg] ?? '(no response)';
         if ($got !== "served:{$path}") {
            $mismatches[] = ($leg + 1) . ":{$got}";
         }
      }

      yield assert(
         assertion: count($served) === count($paths),
         description: 'Every leg answered: ' . count($served) . ' of ' . count($paths)
      );

      // @ The first repeat is the control: a memo hit must still work
      yield assert(
         assertion: ($served[1] ?? null) === 'served:/one',
         description: 'Repeating the first pair reuses correct bytes: '
            . var_export($served[1] ?? null, true)
      );

      yield assert(
         assertion: $mismatches === [],
         description: 'Every leg was encoded from its own request: '
            . ($mismatches === [] ? 'all match' : implode(', ', $mismatches))
      );
   }
);
