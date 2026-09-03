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

   requests: [
      function () {
         return "GET /embedded/relay HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /embedded/flaky HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      $embed = function (Response $Response, array $tune = []): HTTP_Client_CLI {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
         $Client->react(TCP_Server_CLI::$Event);
         $Client->schedule(fn (mixed $value = null): Response => $Response->wait($value));
         $Client->configure(new HTTP_Client_CLI\Configs(host: '127.0.0.1', port: BOOTGLY_E2E_UPSTREAM_PORT));
         foreach ($tune as $option => $value) {
            $Client->$option = $value;
         }

         return $Client;
      };

      // @ R8 — after the embedded call (and its halt()), the WORKER reactor
      //   must remain alive and the next requests must still be served
      yield $Router->route('/embedded/relay', function (Request $Request, Response $Response) use ($embed) {
         return $Response->defer(function (Response $Response) use ($embed) {
            $Upstream = $embed($Response)->request(method: 'GET', URI: '/delay');

            $Response->JSON->send([
               'code' => $Upstream->code,
               'loop' => TCP_Server_CLI::$Event->loop
            ]);
         });
      }, GET);

      // @ D4 — a retry re-dial is serviced BY THE FIBER between parks (the
      //   backoff closure runs on the host reactor stack and must not dial)
      yield $Router->route('/embedded/flaky', function (Request $Request, Response $Response) use ($embed) {
         return $Response->defer(function (Response $Response) use ($embed) {
            // ! Re-arm the fixture flake and pin a short, jitter-free backoff
            //   so the window below proves the backoff was really paid
            $embed($Response)->request(method: 'GET', URI: '/flaky/reset');

            $started = microtime(true);
            $Upstream = $embed($Response, [
               'maxRetries' => 2,
               'retryDelay' => 0.3,
               'retryJitter' => 0.0
            ])->request(method: 'GET', URI: '/flaky');

            $Response->JSON->send([
               'code' => $Upstream->code,
               'body' => $Upstream->body,
               'elapsed' => microtime(true) - $started
            ]);
         });
      }, GET);

      yield $Router->route('/plain', function (Request $Request, Response $Response) {
         return $Response(body: 'alive');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: new Assertions(Case: function (array $responses): Generator
   {
      $body = function (string $raw): string {
         return substr($raw, strpos($raw, "\r\n\r\n") + 4);
      };
      $relay = (array) json_decode($body($responses[0] ?? ''), true);
      $flaky = (array) json_decode($body($responses[1] ?? ''), true);

      yield new Assertion(
         description: 'The embedded upstream call completed',
         fallback: 'The relayed upstream call did not answer 200!'
      )
         ->expect($relay['code'] ?? 0)
         ->to->be(200)
         ->assert();

      yield new Assertion(
         description: 'halt() left the worker reactor alive (R8)',
         fallback: 'The embedded client halted the WORKER reactor!'
      )
         ->expect($relay['loop'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'A retried request resolves through the parked drain (D4)',
         fallback: 'The retry re-dial did not complete through the owner Fiber!'
      )
         ->expect(($flaky['code'] ?? 0) === 200 && ($flaky['body'] ?? '') === 'flaky-ok-2')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The retry really waited its backoff (not a harness resend)',
         fallback: 'The flaky leg resolved without paying the retry backoff!'
      )
         ->expect($flaky['elapsed'] ?? 0.0)
         ->to->delimit(0.25, 0.9)
         ->assert();

      yield new Assertion(
         description: 'The worker keeps serving after the embedded episodes',
         fallback: 'A plain request after the embedded calls was not served!'
      )
         ->expect($body($responses[2] ?? ''))
         ->to->be('alive')
         ->assert();
   })
);
