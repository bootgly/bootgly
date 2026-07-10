<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Unsupported final statuses FAIL LOUD: informational codes (1xx) can never
// terminate an exchange (hint() is the only interim path), and below-100 /
// unmapped values must never silently become the current status. Every
// public entry point funnels through code() — all of them must throw.

// ! Expected wire bytes (test mode: no `Date` preset)
$expected = "HTTP/1.1 200 OK\r\n"
   . "Server: Bootgly\r\n"
   . "Content-Type: text/html; charset=UTF-8\r\n"
   . "Content-Length: 8\r\n"
   . "\r\n"
   . "caught=6";

return new Specification(
   description: 'It should fail loud on 1xx and invalid codes through every entry point',

   request: function () {
      return "GET /code-1xx HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   responseLength: strlen($expected),
   response: function (Request $Request, Response $Response): Response {
      $caught = 0;

      // @ Fluent call — informational
      try {
         $Response->code(103);
      }
      catch (InvalidArgumentException) {
         $caught++;
      }
      // @ Fluent call — below-100 garbage (never a silent 200)
      try {
         $Response->code(0);
      }
      catch (InvalidArgumentException) {
         $caught++;
      }
      // @ Fluent call — unmapped final value
      try {
         $Response->code(999);
      }
      catch (InvalidArgumentException) {
         $caught++;
      }
      // @ Constructor entry point
      try {
         new Response(100);
      }
      catch (InvalidArgumentException) {
         $caught++;
      }
      // @ Invokable entry point (protocol switches stay out of this API)
      try {
         $Response(code: 101);
      }
      catch (InvalidArgumentException) {
         $caught++;
      }
      // @ Property writes are not an entry point at all — there is no
      //   magic setter (one-way: code() is the single write path), so PHP
      //   itself denies the write on the protected property
      try {
         /** @phpstan-ignore assign.readOnlyProperty (the runtime guard under test) */
         $Response->code = 600;
      }
      catch (Error) {
         $caught++;
      }

      return $Response(body: "caught={$caught}");
   },

   test: function ($response) use ($expected) {
      // @ Assert — byte-exact: all five rejections threw and the final
      //   status stayed 200
      if ($response !== $expected) {
         Vars::$labels = ['HTTP Response:', 'Expected:'];
         dump(json_encode($response), json_encode($expected));
         return 'Unsupported status codes must throw through every entry point';
      }

      return true;
   }
);
