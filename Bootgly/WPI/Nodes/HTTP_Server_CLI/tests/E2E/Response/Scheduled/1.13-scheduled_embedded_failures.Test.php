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
      return "GET /embedded/failures HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // @ BG-13 R2/R3: every failure terminal must resolve a PARKED request
      //   exactly once, deterministically, with code 0 + a named status
      yield $Router->route('/embedded/failures', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            $cases = [];
            $run = function (string $path, int $port, array $tune) use ($Response, &$cases): void {
               $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
               $Client->react(TCP_Server_CLI::$Event);
               $Client->schedule(fn (mixed $value = null): Response => $Response->wait($value));
               $Client->configure(host: '127.0.0.1', port: $port);
               foreach ($tune as $option => $value) {
                  $Client->$option = $value;
               }

               $started = microtime(true);
               $Upstream = $Client->request(method: 'GET', URI: $path);
               $cases[] = [
                  'path' => $path,
                  'code' => $Upstream->code,
                  'status' => $Upstream->status,
                  'elapsed' => microtime(true) - $started
               ];
            };

            // ! R2 — a peer that never answers resolves at the response window
            $run('/never', BOOTGLY_E2E_UPSTREAM_PORT, ['timeout' => 0.5]);
            // ! R3 — dead origin (retry-vetoed dial)
            $run('/', 1, []);
            // ! R3 — redirect leg to a dead origin
            $run('/leave', BOOTGLY_E2E_UPSTREAM_PORT, []);
            // ! R3 — oversized body
            $run('/grow', BOOTGLY_E2E_UPSTREAM_PORT, ['maxResponseBytes' => 1024]);
            // ! R3 — malformed chunked framing
            $run('/chunk', BOOTGLY_E2E_UPSTREAM_PORT, []);

            $Response->JSON->send($cases);
         });
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: new Assertions(Case: function (string $response): Generator
   {
      $cases = (array) json_decode(substr($response, strpos($response, "\r\n\r\n") + 4), true);

      $expected = [
         '/never' => ['status' => 'Timeout', 'floor' => 0.45, 'ceiling' => 1.2],
         '/' => ['status' => 'Connection Failed', 'floor' => 0.0, 'ceiling' => 0.4],
         '/leave' => ['status' => 'Redirect Failed', 'floor' => 0.0, 'ceiling' => 0.6],
         '/grow' => ['status' => 'Response Too Large', 'floor' => 0.0, 'ceiling' => 0.6],
         '/chunk' => ['status' => 'Invalid Chunked Encoding', 'floor' => 0.0, 'ceiling' => 0.6]
      ];

      yield new Assertion(
         description: 'Every failure case resolved exactly once, in order',
         fallback: 'A parked failure terminal hung, duplicated or produced no result!'
      )
         ->expect(array_column($cases, 'path'))
         ->to->be(array_keys($expected))
         ->assert();

      foreach ($cases as $case) {
         $path = $case['path'] ?? '?';
         $want = $expected[$path] ?? ['status' => '?', 'floor' => 0.0, 'ceiling' => 0.0];

         yield new Assertion(
            description: "{$path} resolves with code 0 and its named status",
            fallback: "{$path} did not resolve as [0, {$want['status']}]: got [{$case['code']}, {$case['status']}]!"
         )
            ->expect($case['code'] === 0 && $case['status'] === $want['status'])
            ->to->be(true)
            ->assert();

         yield new Assertion(
            description: "{$path} resolves inside its window",
            fallback: "{$path} took {$case['elapsed']}s - outside [{$want['floor']}, {$want['ceiling']}]!"
         )
            ->expect($case['elapsed'] >= $want['floor'] && $case['elapsed'] <= $want['ceiling'])
            ->to->be(true)
            ->assert();
      }
   })
);
