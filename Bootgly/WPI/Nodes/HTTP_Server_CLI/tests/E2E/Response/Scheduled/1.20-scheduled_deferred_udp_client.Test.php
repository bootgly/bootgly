<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Configs;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Outcomes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


/**
 * The embedded UDP client called from a deferred route, as the manual shows
 * it: it adopts the worker's reactor and parks the request's Fiber through
 * `$Response->wait()` while it waits for a datagram — the worker keeps
 * ticking, the wait burns no CPU, the answer arrives, and a second wait the
 * peer never answers expires at its bound.
 */
return new Test(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/udp-client HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/deferred/udp-client', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            // ! A loopback UDP peer that answers after 150 ms, from the worker's own loop
            $Peer = stream_socket_server('udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND);
            [$host, $port] = explode(':', (string) stream_socket_get_name($Peer, false));
            TCP_Server_CLI::$Event->defer(
               microtime(true) + 0.150,
               static function () use ($Peer): void {
                  $ping = stream_socket_recvfrom($Peer, 100, 0, $address);
                  stream_socket_sendto($Peer, "pong:{$ping}", 0, $address);
               }
            );
            // ! Reactor-tick probe
            $ticked = 0.0;
            TCP_Server_CLI::$Event->defer(
               microtime(true) + 0.050,
               static function () use (&$ticked): void {
                  $ticked = microtime(true);
               }
            );

            // ! CPU baseline (anti-spin evidence: a tick-parked wait burns a core)
            $usage = getrusage();
            $baseline = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
            $started = microtime(true);

            // @ The embedded UDP client on the worker reactor
            $Client = new UDP_Client_CLI(UDP_Client_CLI::MODE_EMBEDDED);
            $Client->react(TCP_Server_CLI::$Event);
            $Client->schedule(fn (mixed $value = null): Response => $Response->wait($value));
            $Client->configure(new Configs(host: $host, port: (int) $port));

            try {
               $Socket = $Client->connect();
               $Flow = $Client->Connections->Flows[(int) $Socket];
               $Flow->output = 'ping';
               $Flow->writing($Socket);

               $Outcome = $Flow->receive(2.0);
               $answer = $Outcome === Outcomes::Received ? $Flow->input : null;
               $elapsed = microtime(true) - $started;

               // @ A second wait the peer never answers
               $expiring = microtime(true);
               $Expired = $Flow->receive(0.2);
               $expired = microtime(true) - $expiring;
            }
            finally {
               $Client->stop();
               fclose($Peer);
            }

            $usage = getrusage();
            $spent = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6
               - $baseline;

            // : Ship the evidence in the body — the Case runs in the harness
            //   master and only sees the raw response
            $Response->JSON->send([
               'outcome' => $Outcome->name,
               'answer' => $answer,
               'elapsed' => $elapsed,
               'expired' => [$Expired->name, $expired],
               'closed' => is_resource($Socket) === false && $Client->Connections->Flows === [],
               // ? 0.0 = the timer never fired while the wait was parked
               'gap' => $ticked > 0.0 ? $ticked - $started : 0.0,
               'cpu' => $spent
            ]);
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: new Assertions(Case: function (string $response): Generator
   {
      $body = (array) json_decode(substr($response, strpos($response, "\r\n\r\n") + 4), true);

      yield new Assertion(
         description: 'The datagram answer arrives',
         fallback: 'The parked wait did not receive the answer!'
      )
         ->expect([$body['outcome'] ?? null, $body['answer'] ?? null])
         ->to->be(['Received', 'pong:ping'])
         ->assert();

      yield new Assertion(
         description: 'The wait lasted until the peer answered',
         fallback: 'The wait did not park until the answer!'
      )
         ->expect($body['elapsed'] ?? 0.0)
         ->to->delimit(0.120, 0.600)
         ->assert();

      yield new Assertion(
         description: 'An unanswered wait expires at its bound',
         fallback: 'The unanswered wait did not expire at its bound!'
      )
         ->expect(($body['expired'][0] ?? null) === 'Expired'
            && ($body['expired'][1] ?? 0.0) >= 0.19 && ($body['expired'][1] ?? 9.9) < 0.6)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'stop() releases the flow',
         fallback: 'The flow was left open after stop()!'
      )
         ->expect($body['closed'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The worker reactor ticked during the wait',
         fallback: 'The worker reactor never ticked while the datagram wait was parked!'
      )
         ->expect($body['gap'] ?? 0.0)
         ->to->delimit(0.040, 0.140)
         ->assert();

      yield new Assertion(
         description: 'The datagram waits burn no CPU',
         fallback: 'The datagram wait burned CPU - parking degenerated into a spin!'
      )
         ->expect($body['cpu'] ?? 9.9)
         ->to->delimit(0.0, 0.100)
         ->assert();
   })
);
