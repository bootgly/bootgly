<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Audit F-1: the request-line `protocol` token must be validated. Any version
 * other than exactly `HTTP/1.1` or `HTTP/1.0` must be rejected with
 * `505 HTTP Version Not Supported` BEFORE any framing decision.
 *
 * Without this, the protocol is consumed only via exact equality, so a bogus
 * version (`HTTP/9.9`) silently disables both the mandatory-Host guard and the
 * `$allowedHosts` allowlist, and the request is still dispatched.
 */
return new Specification(
   description: 'It should reject an unsupported HTTP version with 505 HTTP Version Not Supported',

   request: function () {
      return "GET / HTTP/9.9\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      return $Response(body: 'Should not reach here');
   },

   test: function ($response) {
      // @ Assert
      if ($response === '') {
         return true; // Connection was rejected (closed before response)
      }

      if (str_contains($response, '505 HTTP Version Not Supported')) {
         return true;
      }

      Vars::$labels = ['HTTP Response:'];
      dump(json_encode($response));
      return 'Should have rejected the unsupported version with 505 HTTP Version Not Supported';
   }
);
