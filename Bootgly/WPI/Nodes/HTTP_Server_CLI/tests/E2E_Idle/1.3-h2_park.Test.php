<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Idle\Routes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\tests\HTTP2\Client as H2Client;


/**
 * BG-20 on HTTP/2 (prior knowledge): the connection preface counts as a write,
 * which used to buy exactly one extra idle window (~30 s in production). A
 * stream whose deferral parks past two windows must still answer, and the
 * connection must stay open afterwards.
 */
$Probe = new class {
   public int $status = 0;
   public string $body = '';
   public float $elapsed = 0.0;
   public bool $open = false;
   public string $error = '';
};

return new Test(
   Separator: new Separator(line: ''),

   request: static function (string $hostPort, int $testIndex) use ($Probe): string {
      try {
         $H2 = new H2Client("tcp://{$hostPort}");
         $H2->preface();
         $started = microtime(true);
         $H2->send(Frame::pack(
            HTTP2::FRAME_HEADERS,
            HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM,
            1,
            HPACK::encode([
               [':method', 'GET'],
               [':scheme', 'http'],
               [':path', '/idle/park?seconds=6'],
               [':authority', 'localhost'],
               ['x-bootgly-test', (string) $testIndex]
            ])
         ));
         $headers = $H2->expect(HTTP2::FRAME_HEADERS, 9.0);
         $data = $H2->expect(HTTP2::FRAME_DATA, 2.0);
         $Probe->elapsed = microtime(true) - $started;

         $map = [];
         foreach (((new HPACK)->decode($headers['payload'] ?? '', PHP_INT_MAX) ?? []) as $key => $pair) {
            if (is_array($pair) && count($pair) === 2) {
               $map[$pair[0]] = $pair[1];
            }
            else {
               $map[$key] = $pair;
            }
         }
         $Probe->status = (int) ($map[':status'] ?? 0);
         $Probe->body = (string) ($data['payload'] ?? '');
         $Probe->open = $H2->closed(0.2) === false;
         $H2->close();
      }
      catch (Throwable $Throwable) {
         $Probe->error = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /idle/ping HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: static function (Request $Request, Response $Response, Router $Router): Generator {
      yield from Routes::mount($Router);
   },

   test: new Assertions(Case: function (string $response) use ($Probe): Generator {
      $body = (array) json_decode($Probe->body, true);
      $evidence = json_encode(['error' => $Probe->error, 'status' => $Probe->status, 'body' => $body, 'elapsed' => $Probe->elapsed, 'open' => $Probe->open]);

      yield new Assertion(
         description: 'An HTTP/2 stream whose deferral parked past two idle windows answers 200',
         fallback: "The idle reaper cut the HTTP/2 connection: {$evidence}"
      )
         ->expect([$Probe->status, $body['protocol'] ?? ''])
         ->to->be([200, 'HTTP/2'])
         ->assert();

      yield new Assertion(
         description: 'The answer arrived when the park ended',
         fallback: "Unexpected timing: {$evidence}"
      )
         ->expect($Probe->elapsed)
         ->to->delimit(5.9, 7.5)
         ->assert();

      yield new Assertion(
         description: 'The HTTP/2 connection is still open right after the answer',
         fallback: "The connection was closed together with the answer: {$evidence}"
      )
         ->expect($Probe->open)
         ->to->be(true)
         ->assert();
   })
);
