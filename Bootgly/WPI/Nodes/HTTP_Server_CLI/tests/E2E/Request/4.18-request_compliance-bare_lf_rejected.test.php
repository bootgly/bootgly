<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Audit F-1: a bare-LF in the request head (a `\n` not preceded by `\r`) must
 * be rejected with `400 Bad Request` BEFORE the request line is parsed.
 *
 * The head is split on CRLF only, so a lone LF lets a tolerant upstream proxy
 * and Bootgly disagree on line boundaries (request smuggling) and can fold a
 * pseudo-header into the request-line `protocol` token. Here `Host: localhost`
 * is terminated by a bare LF, smuggling an `Evil:` pseudo-header.
 */
return new Specification(
   description: 'It should reject a bare-LF (LF not preceded by CR) in the head with 400',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\nEvil: smuggled\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      // @ Assert
      if ($response === '') {
         return true; // Connection was rejected (closed before response)
      }

      if (str_contains($response, '400 Bad Request')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have rejected the bare-LF head with 400 Bad Request';
   }
);
