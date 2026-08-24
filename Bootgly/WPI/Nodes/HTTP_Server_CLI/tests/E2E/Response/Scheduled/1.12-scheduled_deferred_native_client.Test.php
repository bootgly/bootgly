<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/native-client HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ BG-13 probe: the NATIVE HTTP client called from a deferred route.
      //   The reactor-tick probe timer only fires from inside Select::tick(),
      //   so it marking presence proves the worker reactor kept spinning
      //   while the outbound call was in flight.
      yield $Router->route('/deferred/native-client', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
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

            // @ The native client, embedded on the worker reactor (BG-13)
            $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
            $Client->react(TCP_Server_CLI::$Event);
            $Client->schedule(fn (mixed $value = null): Response => $Response->wait($value));
            $Client->configure(host: '127.0.0.1', port: BOOTGLY_E2E_UPSTREAM_PORT);
            $Upstream = $Client->request(method: 'GET', URI: '/delay');

            $elapsed = microtime(true) - $started;
            $usage = getrusage();
            $spent = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6
               - $baseline;

            // : Ship the evidence in the body — the Case runs in the harness
            //   master and only sees the raw response
            $Response->JSON->send([
               'code' => $Upstream->code,
               'elapsed' => $elapsed,
               // ? 0.0 = the timer never fired while the outbound was in flight
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
         description: 'The upstream call completes',
         fallback: 'Upstream did not answer 200!'
      )
         ->expect($body['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'The upstream really delayed',
         fallback: 'Upstream answered too fast - the fixture did not delay!'
      )
         ->expect($body['elapsed'] ?? 0.0)
         ->to->delimit(1.0, 1.6)
         ->assert();

      // ! The BG-13 assertion: the worker reactor keeps spinning while the
      //   outbound call is parked, so the 50 ms probe timer fires on time
      yield new Assertion(
         description: 'The worker reactor ticked during the outbound',
         fallback: 'The worker reactor never ticked while the upstream call was in flight!'
      )
         ->expect($body['gap'] ?? 0.0)
         ->to->delimit(0.040, 0.300)
         ->assert();

      yield new Assertion(
         description: 'The upstream wait burns no CPU',
         fallback: 'The upstream wait burned CPU - parking degenerated into a spin!'
      )
         ->expect($body['cpu'] ?? 9.9)
         ->to->delimit(0.0, 0.100)
         ->assert();
   })
);
