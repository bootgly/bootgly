<?php

use function json_encode;
use function str_contains;
use function substr;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Decoder_Waiting never resets `self::$decoded` / `self::$read`.
 *
 * Both fields are initialised with `??=` (see Decoder_Waiting.php) and are
 * never cleared between requests. The first slow/non-multipart upload writes
 * these statics once; every subsequent upload inherits the stale timestamp and
 * read offset, which breaks the 60-second body timeout and the
 * "received-more-than-content-length" guard.
 *
 * PoC sends two sequential, complete non-multipart POSTs on the same keep-alive
 * connection; the second one arrives long after the first was served. Under a
 * correctly per-request (or per-connection) decoder, both handlers see their
 * own bodies. Under the current shared-static implementation the second
 * request's timeout/read accounting is distorted — the most common visible
 * effect is that the second handler's body is mis-sized or the socket is
 * closed early.
 *
 * The test asserts the second request is answered with the exact echoed body.
 */

return new Specification(
   description: 'Decoder_Waiting must not share $decoded/$read across requests',
   Separator: new Separator(line: true),

   requests: [
      function (): string {
         $body = 'FIRST-BODY-PAYLOAD';
         $len  = strlen($body);
         return
            "POST /echo HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$len}\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . $body;
      },
      function (): string {
         $body = 'SECOND-BODY-PAYLOAD-AFTER-RESET';
         $len  = strlen($body);
         return
            "POST /echo HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Length: {$len}\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . $body;
      },
   ],

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/echo', function (Request $Request, Response $Response) {
         return $Response(body: $Request->Body->raw);
      }, POST);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (array $responses) {
      $first  = $responses[0] ?? '';
      $second = $responses[1] ?? '';

      if ( ! str_contains($first, 'FIRST-BODY-PAYLOAD')) {
         Vars::$labels = ['First response:'];
         dump(json_encode(substr($first, 0, 300)));
         return 'First /echo did not return its body';
      }

      if ( ! str_contains($second, 'SECOND-BODY-PAYLOAD-AFTER-RESET')) {
         Vars::$labels = ['Second response:'];
         dump(json_encode(substr($second, 0, 300)));
         return 'Second /echo body was truncated or corrupted — '
              . 'Decoder_Waiting static state leaked from the first request.';
      }

      return true;
   }
);
