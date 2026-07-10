<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Route response cache + Early Hints: the interim 103 bytes are stored at
// the front of the cache entry, so warm hits replay them exactly like the
// cold request — cache warmth must not change Early Hints behavior.

// ! Expected wire bytes per request (test mode: no `Date` preset) — the
//   interim head goes out in its own write, so byte-exact lengths keep the
//   runner reading until the final response arrives
$interim = "HTTP/1.1 103 Early Hints\r\n"
   . "Link: </app.css>; rel=preload; as=style\r\n"
   . "\r\n";
$final = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 11\r\n"
   . "\r\n"
   . "hinted-page";
$expected = $interim . $final;

return new Specification(
   description: 'It should replay Early Hints on route-cache hits',

   requests: [
      function () {
         return "GET /hinted/cached HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /hinted/cached HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [strlen($expected), strlen($expected)],
   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/hinted/cached', function ($Request, $Response) {
         $Response->hint('</app.css>; rel=preload; as=style');

         return $Response(body: 'hinted-page');
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) use ($expected) {
      [$cold, $warm] = $responses;

      // @ Assert — the cold request emits 103 + 200; the warm hit replays
      //   the exact same wire from the route cache
      if ($cold !== $expected || $warm !== $expected) {
         Vars::$labels = ['Cold:', 'Warm:', 'Expected:'];
         dump(json_encode($cold), json_encode($warm), json_encode($expected));
         return 'Early Hints not replayed identically on the cache hit';
      }

      return true;
   }
);
