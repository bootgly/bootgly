<?php

use function json_encode;
use function str_contains;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Raw\Header;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use Throwable;


/**
 * PoC — Header field names are not fully case-insensitive internally.
 *
 * `Header::build()` stores fields with their original spelling, so a request
 * carrying `AUTHORIZATION:`, `ORIGIN:`, or `COOKIE:` (uppercase) is invisible
 * to `Header::get('Authorization')`, `get('Origin')`, and to the cookie parser
 * which only checks `Cookie`/`cookie`. Middleware (CORS, auth, sessions) can
 * be silently bypassed depending on attacker-controlled header casing.
 */

$probe = [
   'error'         => '',
   'authorization' => null,
   'origin'        => null,
   'forwardedFor'  => null,
   'sessionId'     => null,
];

return new Specification(
   description: 'Header field lookup must be case-insensitive (uppercase variants must resolve)',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      try {
         $Header = new Header;
         $Header->define(
            "AUTHORIZATION: Bearer abc123\r\n"
            . "ORIGIN: https://example.test\r\n"
            . "X-FORWARDED-FOR: 198.51.100.7\r\n"
            . "COOKIE: sid=ZXKQ; theme=dark"
         );

         $probe['authorization'] = $Header->get('Authorization');
         $probe['origin']        = $Header->get('Origin');
         $probe['forwardedFor']  = $Header->get('X-Forwarded-For');
         $probe['sessionId']     = $Header->Cookies->get('sid');
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /header-case-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response) {
      return $Response(code: 200, body: 'HARNESS-OK');
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if ($probe['authorization'] !== 'Bearer abc123') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::get("Authorization") missed the uppercase AUTHORIZATION field.';
      }

      if ($probe['origin'] !== 'https://example.test') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::get("Origin") missed the uppercase ORIGIN field — CORS bypass surface.';
      }

      if ($probe['forwardedFor'] !== '198.51.100.7') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Header::get("X-Forwarded-For") missed uppercase X-FORWARDED-FOR.';
      }

      if ($probe['sessionId'] !== 'ZXKQ') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'Cookies::get("sid") missed cookies sent under uppercase COOKIE: header.';
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode($response));
         return 'Harness request did not reach /header-case-harness.';
      }

      return true;
   }
);
