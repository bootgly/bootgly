<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


// ? HCLI-18 — an event-driven consumer holds no Request to poll: a response the client
//   refuses (past maxResponseBytes, or with invalid framing) must reach its ResponseReceive
//   callback as code 0 with the failure status, never vanish. Also pins the 16 MiB default.
return new Test(
   description: 'It should deliver a refused response to the event-driven callback',
   test: function () {
      // ! An origin on a free port, answering each connection by the path it was asked for
      $Server = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
      if ($Server === false) {
         yield assert(assertion: false, description: "No free loopback port: {$errstr}");
         return;
      }
      $name = (string) stream_socket_get_name($Server, false);
      $port = (int) substr($name, strrpos($name, ':') + 1);

      $forked = pcntl_fork();
      if ($forked === 0) {
         while (($Peer = @stream_socket_accept($Server, 10)) !== false) {
            $input = '';
            while (strpos($input, "\r\n\r\n") === false) {
               $chunk = @fread($Peer, 65535);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $input .= $chunk;
            }

            $path = preg_match('/^[A-Z]+ (\S+) HTTP/', $input, $matches) === 1 ? $matches[1] : '/';
            @fwrite($Peer, match ($path) {
               '/big'     => "HTTP/1.1 200 OK\r\nContent-Length: 100000\r\n\r\n" . str_repeat('B', 100000),
               '/invalid' => "HTTP/1.1 200 OK\r\nContent-Length: -1\r\n\r\nhello",
               default    => "HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello",
            });
            @fclose($Peer);
         }

         exit(0);
      }
      fclose($Server);

      // ! One event-driven client per leg: the first callback stops its loop
      $leg = static function (string $path) use ($port): array {
         $fired = [];

         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: $port));
         $Client->timeout = 5;
         $Client->maxResponseBytes = 65536;
         $Client->on(Events::ResponseReceive, function ($Request, $Response) use (&$fired, $Client): void {
            $fired[] = "{$Response->code} {$Response->status}";
            $Client->Event->loop = false; // @phpstan-ignore-line
         });

         $Client->request('GET', $path);
         $Socket = $Client->connect();

         // ! A hang must fail the assertion, never wedge the suite
         $Client->Event->defer(microtime(true) + 6.0, function () use ($Client): void {
            $Client->Event->loop = false; // @phpstan-ignore-line
         });
         if ($Socket !== false) {
            $Client->Event->loop();
         }

         return $fired;
      };

      $control = $leg('/control');
      $big = $leg('/big');
      $invalid = $leg('/invalid');

      posix_kill($forked, SIGTERM);
      pcntl_waitpid($forked, $status);

      yield assert(
         assertion: $control === ['200 OK'],
         description: 'control: a normal response fires the callback once: ' . json_encode($control)
      );

      yield assert(
         assertion: $big === ['0 Response Too Large'],
         description: 'a response past maxResponseBytes fires the callback with its failure: ' . json_encode($big)
      );

      yield assert(
         assertion: $invalid === ['0 Invalid Response'],
         description: 'a response with invalid framing fires the callback with its failure: ' . json_encode($invalid)
      );

      yield assert(
         assertion: (new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST))->maxResponseBytes === 16_777_216,
         description: 'maxResponseBytes defaults to 16 MiB'
      );
   }
);
