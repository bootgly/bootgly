<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// The Plaintext resource sets the per-response default media type (Header->type =
// 'text/plain') instead of a header field. On the persistent worker that value MUST
// be reset by clean() every request, and MUST take part in build()'s content-cache
// key — otherwise the next response (a default text/html route) would inherit
// text/plain. Sequence: plaintext -> default html -> plaintext, all the same second.

return new Specification(
   requests: [
      function () {
         return "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /html HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /plain HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/plain', function ($Request, $Response) {
         return $Response->Plaintext->send('hi');
      }, GET);

      yield $Router->route('/html', function ($Request, $Response) {
         return $Response(body: 'page');
      }, GET);
   },

   test: function (array $responses) {
      [$plain1, $html, $plain2] = $responses;

      if (! str_contains($plain1, "Content-Type: text/plain\r\n")) {
         Vars::$labels = ['Response plain1:'];
         dump(json_encode($plain1));
         return 'First /plain response missing text/plain';
      }
      // The default route must restore text/html — never leak text/plain from /plain.
      if (
         ! str_contains($html, "Content-Type: text/html; charset=UTF-8\r\n")
         || str_contains($html, 'text/plain')
      ) {
         Vars::$labels = ['Response html:'];
         dump(json_encode($html));
         return 'Contamination: /html response leaked text/plain (type not reset / cache key missing type)';
      }
      // And /plain still works after the reset.
      if (! str_contains($plain2, "Content-Type: text/plain\r\n")) {
         Vars::$labels = ['Response plain2:'];
         dump(json_encode($plain2));
         return 'Second /plain response missing text/plain after html reset';
      }

      return true;
   }
);
