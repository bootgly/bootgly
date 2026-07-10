<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// The route cache stores HTTP/1.1 wire only, so its read path must also be
// HTTP/1.1-only: an HTTP/1.0 keep-alive GET on a warmed URI may never
// replay the cached HTTP/1.1 bytes — that would leak interim 103 heads
// hint() explicitly never sends to HTTP/1.0 clients.

// ! Expected wire bytes per request (test mode: no `Date` preset)
$interim = "HTTP/1.1 103 Early Hints\r\n"
   . "Link: </app.css>; rel=preload; as=style\r\n"
   . "\r\n";
$warm = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 11\r\n"
   . "\r\n"
   . "hinted-page";
$fresh = "HTTP/1.0 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 11\r\n"
   . "\r\n"
   . "hinted-page";

return new Specification(
   description: 'It should never replay a cached HTTP/1.1 entry to an HTTP/1.0 client',

   requests: [
      function () {
         return "GET /hinted/poison HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /hinted/poison HTTP/1.0\r\nHost: localhost\r\nConnection: keep-alive\r\n\r\n";
      },
   ],
   responseLengths: [strlen($interim . $warm), strlen($fresh)],
   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/hinted/poison', function ($Request, $Response) {
         $Response->hint('</app.css>; rel=preload; as=style');

         return $Response(body: 'hinted-page');
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) use ($interim, $warm, $fresh) {
      [$first, $second] = $responses;

      // @ Assert — request 1 (HTTP/1.1) warms the cache with 103 + 200
      if ($first !== $interim . $warm) {
         Vars::$labels = ['HTTP/1.1 response:', 'Expected:'];
         dump(json_encode($first), json_encode($interim . $warm));
         return 'HTTP/1.1 warming request did not produce the hinted wire';
      }

      // @ Assert — request 2 (HTTP/1.0 keep-alive, same URI) bypasses the
      //   cache: fresh HTTP/1.0 final response, no interim bytes
      if ($second !== $fresh) {
         Vars::$labels = ['HTTP/1.0 response:', 'Expected:'];
         dump(json_encode($second), json_encode($fresh));
         return 'HTTP/1.0 request must get a fresh response, never the cached HTTP/1.1 wire';
      }

      return true;
   }
);
