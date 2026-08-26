<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


return new Test(
   Separator: new Separator(line: ''),

   requests: [
      function () {
         return "GET /secure/downgrade HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /secure/burst HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
      }
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ R3 remainder — an https → http step-down is refused by name, parked
      yield $Router->route('/secure/downgrade', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            $started = microtime(true);
            $Upstream = $Response->Secure->request(method: 'GET', URI: '/downgrade');

            $Response->JSON->send([
               'code' => $Upstream->code,
               'status' => $Upstream->status,
               'elapsed' => microtime(true) - $started
            ]);
         });
      }, GET);

      // @ A keep-alive TLS answer flushed as three back-to-back records must
      //   be reassembled across reactor wakes: no EOF ends this body, only
      //   the Content-Length — a reader that loses a record ends at the
      //   (shortened) timeout
      yield $Router->route('/secure/burst', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            // ! Knob surface: a hang must fail fast and by name, not through
            //   the harness window
            $Response->Secure->Client->timeout = 1.0;

            $started = microtime(true);
            $Upstream = $Response->Secure->request(method: 'GET', URI: '/burst');

            $Response->JSON->send([
               'code' => $Upstream->code,
               'status' => $Upstream->status,
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
      $decode = function (string $raw): array {
         return (array) json_decode(substr($raw, strpos($raw, "\r\n\r\n") + 4), true);
      };
      $downgrade = $decode($responses[0] ?? '');
      $burst = $decode($responses[1] ?? '');

      yield new Assertion(
         description: 'An https to http redirect is refused by name (R3)',
         fallback: 'The insecure redirect was followed or failed under another name!'
      )
         ->expect(($downgrade['code'] ?? -1) === 0 && ($downgrade['status'] ?? '') === 'Insecure Redirect')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The refusal is immediate (no dial to the cleartext origin)',
         fallback: 'The insecure redirect took a round trip before being refused!'
      )
         ->expect($downgrade['elapsed'] ?? 9.9)
         ->to->delimit(0.0, 0.5)
         ->assert();

      yield new Assertion(
         description: 'A keep-alive TLS answer split across records is reassembled',
         fallback: 'A keep-alive TLS body split across records was not reassembled - the burst hung until the timeout!'
      )
         ->expect(($burst['code'] ?? 0) === 200 && ($burst['body'] ?? '') === 'burst-record-oneburst-record-two')
         ->to->be(true)
         ->assert();

      yield new Assertion(
         description: 'The burst completes promptly',
         fallback: 'The burst answer only completed at the timeout!'
      )
         ->expect($burst['elapsed'] ?? 9.9)
         ->to->delimit(0.0, 0.8)
         ->assert();

      yield new Assertion(
         description: 'The worker keeps serving after the TLS episodes',
         fallback: 'A plain request after the TLS calls was not served!'
      )
         ->expect(substr($responses[2] ?? '', strpos($responses[2] ?? '', "\r\n\r\n") + 4))
         ->to->be('alive')
         ->assert();
   })
);
