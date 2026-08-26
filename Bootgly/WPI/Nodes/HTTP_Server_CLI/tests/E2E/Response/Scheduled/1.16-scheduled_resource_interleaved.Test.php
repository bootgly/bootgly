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

   requests: [
      function () {
         return "GET /resource/interleaved HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /resource/outside HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ R7 — two resources (two embedded clients) on ONE adopted reactor:
      //   interleaved batches, both drains, every leg answered by its own
      //   client. `/delay` is accepted first by the serial fixture, so the
      //   whole exchange fits in ~1 s only if the four legs really overlap.
      yield $Router->route('/resource/interleaved', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            $started = microtime(true);
            $Upstream = $Response->Upstream;
            $Mirror = $Response->Mirror;

            $Upstream->batch();
            $A = $Upstream->request(method: 'GET', URI: '/delay');
            $B = $Upstream->request(method: 'GET', URI: '/fast');
            $Mirror->batch();
            $C = $Mirror->request(method: 'GET', URI: '/fast');
            $D = $Mirror->request(method: 'GET', URI: '/fast');
            // ! Dispatch evidence — in batch mode request() queues and
            //   returns; a client that left batch mode parks each leg to
            //   completion here (the serial fixture makes wire overlap
            //   unobservable, so the spec pins dispatch concurrency)
            $issued = microtime(true) - $started;

            // ! Mirror drains FIRST while Upstream's legs are still in flight
            $Mirror->drain();
            $Upstream->drain();

            $Response->JSON->send([
               'codes' => [$A->code, $B->code, $C->code, $D->code],
               'bodies' => [$A->body, $B->body, $C->body, $D->body],
               'distinct' => $Upstream->Client !== $Mirror->Client,
               'issued' => $issued,
               'elapsed' => microtime(true) - $started,
               'loop' => TCP_Server_CLI::$Event->loop
            ]);
         });
      }, GET);

      // @ F4 — outside defer() there is no Fiber to park: the resource
      //   refuses BEFORE touching the client (no dial, no queue, no timer)
      yield $Router->route('/resource/outside', function (Request $Request, Response $Response) {
         try {
            $Response->Upstream->request(method: 'GET', URI: '/fast');
            $refusal = 'none';
         }
         catch (LogicException $Refusal) {
            $refusal = $Refusal->getMessage();
         }

         return $Response(code: 200, headers: ['Content-Type: text/plain'], body: $refusal);
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
      $interleaved = (array) json_decode($body($responses[0] ?? ''), true);

      yield new Assertion(
         description: 'Every leg of both batches completed (R7)',
         fallback: 'An interleaved batch leg did not answer 200!'
      )
         ->expect(($interleaved['codes'] ?? []) === [200, 200, 200, 200]
            && ($interleaved['bodies'] ?? []) === ['hello-bg13-ok', 'fast-ok', 'fast-ok', 'fast-ok'])
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Each resource owns its own embedded client',
         fallback: 'The two resources share one client!'
      )
         ->expect($interleaved['distinct'] ?? false)
         ->to->be(true)
         ->assert();

      // ! ~5 ms when the four legs queue; ~1 s when a batch parks its /delay
      //   leg to completion (measured 190x apart)
      yield new Assertion(
         description: 'Both batches were dispatched without parking a leg',
         fallback: 'A batched request parked instead of queueing - the legs serialized!'
      )
         ->expect($interleaved['issued'] ?? 9.9)
         ->to->delimit(0.0, 0.5)
         ->assert();

      // ! The serial fixture answers /delay (1 s) before the three /fast legs:
      //   an extra upstream round trip would push this past the band
      yield new Assertion(
         description: 'Both drains settled within one upstream latency',
         fallback: 'The interleaved batches took longer than the single delayed leg!'
      )
         ->expect($interleaved['elapsed'] ?? 9.9)
         ->to->delimit(0.9, 1.8)
         ->assert();

      yield new Assertion(
         description: 'The worker reactor survived two clients and two drains',
         fallback: 'A resource drain halted the WORKER reactor!'
      )
         ->expect($interleaved['loop'] ?? false)
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'Outside defer() the resource refuses before dialing (F4)',
         fallback: 'A resource used outside a deferred context was not refused!'
      )
         ->expect($body($responses[1] ?? ''))
         ->to->be('HTTP response resource must be used inside a live deferred context — call it from defer(), before handing off to SSE or a nested defer().')
         ->assert();

      yield new Assertion(
         description: 'The worker keeps serving after the resource episodes',
         fallback: 'A plain request after the resource calls was not served!'
      )
         ->expect($body($responses[2] ?? ''))
         ->to->be('alive')
         ->assert();
   })
);
