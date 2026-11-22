<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// A request that asked to close never seeds the route cache: its wire carries
// `Connection: close`, which every keep-alive hit would then replay. The next
// keep-alive request is a miss that stores its own wire, and the hit after it
// replays that one — no `Connection: close` on either.

// ! Each handler run answers its own ordinal, so a hit is told from a miss
$Counter = new class {
   public int $runs = 0;
};

// ! Expected wire bytes per request (test mode: no `Date` preset, and the
//   connection stays open — the runner keeps one persistent connection)
$closing = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Connection: close\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 2\r\n"
   . "\r\n"
   . "r1";
$stored = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 2\r\n"
   . "\r\n"
   . "r2";

return new Test(
   description: 'It should never store the wire of a request that asked to close in the route cache',

   requests: [
      function () {
         return "GET /cached/close-seed HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
      },
      function () {
         return "GET /cached/close-seed HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/close-seed HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   responseLengths: [null, null, null],
   response: function (Request $Request, Response $Response, Router $Router) use ($Counter) {
      yield $Router->route('/cached/close-seed', function ($Request, $Response) use ($Counter) {
         $Counter->runs++;

         return $Response(body: "r{$Counter->runs}");
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) use ($closing, $stored) {
      [$seed, $miss, $hit] = $responses;

      // @ Assert — the closing request is answered fresh, announcing the close
      if ($seed !== $closing) {
         Vars::$labels = ['Closing request:', 'Expected:'];
         dump(json_encode($seed), json_encode($closing));
         return 'The request that asked to close was not answered with `Connection: close`';
      }

      // @ Assert — the next keep-alive request is a miss (nothing was stored)
      //   and its own wire carries no `Connection: close`
      if ($miss !== $stored) {
         Vars::$labels = ['First keep-alive request:', 'Expected:'];
         dump(json_encode($miss), json_encode($stored));
         return 'A keep-alive request replayed the wire stored by a request that asked to close';
      }

      // @ Assert — the route cache still works: the keep-alive wire replays
      if ($hit !== $stored) {
         Vars::$labels = ['Second keep-alive request:', 'Expected:'];
         dump(json_encode($hit), json_encode($stored));
         return 'The keep-alive wire was not replayed from the route cache';
      }

      return true;
   }
);
