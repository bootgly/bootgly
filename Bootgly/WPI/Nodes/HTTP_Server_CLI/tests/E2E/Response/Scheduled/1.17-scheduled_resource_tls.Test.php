<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   Separator: new Separator(line: ''),

   request: function () {
      return "GET /deferred/secure HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ BG-13 S6: the same zero-boilerplate call over https — the fixture
      //   only speaks TLS on its port and the resource verifies the
      //   certificate against its cafile, so a 200 proves the handshake. The
      //   reactor-tick probe now spans dial + handshake + response.
      yield $Router->route('/deferred/secure', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            // ! Reactor-tick probe
            $ticked = 0.0;
            TCP_Server_CLI::$Event->defer(
               microtime(true) + 0.050,
               static function () use (&$ticked): void {
                  $ticked = microtime(true);
               }
            );

            // ! CPU baseline (anti-spin evidence)
            $usage = getrusage();
            $baseline = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6;
            $started = microtime(true);

            $Upstream = $Response->Secure->request(method: 'GET', URI: '/delay');

            $elapsed = microtime(true) - $started;
            $usage = getrusage();
            $spent = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6
               - $baseline;

            $Response->JSON->send([
               'code' => $Upstream->code,
               'body' => $Upstream->body,
               'elapsed' => $elapsed,
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
         description: 'The verified TLS upstream call completes with the body',
         fallback: 'The Secure resource did not relay the 200 over TLS!'
      )
         ->expect(($body['code'] ?? 0) === 200 && ($body['body'] ?? '') === 'hello-bg13-ok')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The upstream really delayed',
         fallback: 'Upstream answered too fast - the fixture did not delay!'
      )
         ->expect($body['elapsed'] ?? 0.0)
         ->to->delimit(1.0, 1.6)
         ->assert();

      yield new Assertion(
         description: 'The worker reactor ticked during the TLS call',
         fallback: 'The worker reactor never ticked while the TLS call was in flight!'
      )
         ->expect($body['gap'] ?? 0.0)
         ->to->delimit(0.040, 0.300)
         ->assert();

      yield new Assertion(
         description: 'The TLS wait burns no CPU',
         fallback: 'The TLS wait burned CPU - a parked leg degenerated into a spin!'
      )
         ->expect($body['cpu'] ?? 9.9)
         ->to->delimit(0.0, 0.100)
         ->assert();
   })
);
