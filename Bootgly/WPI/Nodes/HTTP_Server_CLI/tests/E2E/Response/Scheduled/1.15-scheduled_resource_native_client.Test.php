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
      return "GET /deferred/resource HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ BG-13 S5: the zero-boilerplate form — the `Upstream` resource is
      //   registered once in the suite autoboot (responseResources) and the
      //   route neither builds, adopts nor schedules a client. The reactor
      //   tick and CPU probes are the same evidence 1.12 collects by hand.
      yield $Router->route('/deferred/resource', function (Request $Request, Response $Response) {
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

            // @ One line — the resource is the whole embedding
            $Upstream = $Response->Upstream->request(method: 'GET', URI: '/delay');

            $elapsed = microtime(true) - $started;
            $usage = getrusage();
            $spent = $usage['ru_utime.tv_sec'] + $usage['ru_utime.tv_usec'] / 1e6
               + $usage['ru_stime.tv_sec'] + $usage['ru_stime.tv_usec'] / 1e6
               - $baseline;

            // ! Same resource, same deferral: the second read is the SAME
            //   instance (no rebuild inside one generation)
            $same = $Response->Upstream === $Response->Upstream
               && $Response->Upstream->Client->owned === false;

            $Response->JSON->send([
               'code' => $Upstream->code,
               'body' => $Upstream->body,
               'elapsed' => $elapsed,
               'gap' => $ticked > 0.0 ? $ticked - $started : 0.0,
               'cpu' => $spent,
               'same' => $same
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
         description: 'The resource call completes with the upstream body',
         fallback: 'The Upstream resource did not relay the 200!'
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
         description: 'The worker reactor ticked during the resource wait',
         fallback: 'The worker reactor never ticked while the resource call was in flight!'
      )
         ->expect($body['gap'] ?? 0.0)
         ->to->delimit(0.040, 0.300)
         ->assert();

      yield new Assertion(
         description: 'The resource wait burns no CPU',
         fallback: 'The resource wait burned CPU - parking degenerated into a spin!'
      )
         ->expect($body['cpu'] ?? 9.9)
         ->to->delimit(0.0, 0.100)
         ->assert();

      yield new Assertion(
         description: 'One adopted client per deferral, stable within it',
         fallback: 'The resource was rebuilt mid-deferral or its client is not adopted!'
      )
         ->expect($body['same'] ?? false)
         ->to->be(true)
         ->assert();
   })
);
