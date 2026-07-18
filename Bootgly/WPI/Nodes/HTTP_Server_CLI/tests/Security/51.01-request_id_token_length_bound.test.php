<?php

use function preg_match;
use function preg_match_all;
use function str_contains;
use function str_repeat;
use function strlen;
use function substr;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * Security PoC L2 — client-supplied request IDs must have a conservative
 * token grammar and length bound before entering response/log correlation.
 *
 * The probe traverses the native HTTP parser, RequestId middleware and HTTP
 * response encoder. Its attacker ID is both over 128 bytes and contains an
 * internal space. A handler-owned body marker controls for routing and
 * middleware fixture failures.
 */
$attackPrefix = 'l2-attacker-controlled-correlation-label ';
$attackID = $attackPrefix . str_repeat('A', 768);

return new Specification(
   description: 'Client request IDs must be token-shaped and length-bounded',

   request: static function () use ($attackID): string {
      return "GET /l2-request-id-probe HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "X-Request-Id: {$attackID}\r\n"
         . "Connection: close\r\n\r\n";
   },

   middlewares: [new RequestId],

   response: static function (Request $Request, Response $Response): Response {
      return $Response(body: 'L2 handler control');
   },

   test: static function (string $response) use (
      $attackID,
      $attackPrefix,
   ): bool|string {
      if (! str_contains($response, 'HTTP/1.1 200 OK')) {
         return 'L2 fixture did not receive the selected handler status. Response: '
            . json_encode(substr($response, 0, 256));
      }
      if (! str_contains($response, 'L2 handler control')) {
         return 'L2 fixture response omitted the handler control body; bytes='
            . strlen($response) . ', tail=' . json_encode(substr($response, -256));
      }

      $count = preg_match_all(
         '/^X-Request-Id:[\t ]*([^\r\n]*)\r?$/mi',
         $response,
         $matches,
      );
      if ($count !== 1 || ! isset($matches[1][0])) {
         return 'L2 fixture did not receive exactly one response request-ID header.';
      }

      $responseID = $matches[1][0];
      if (
         $responseID === $attackID
         && str_contains($responseID, $attackPrefix)
      ) {
         return 'CONFIRMED L2: the complete overlong, non-token client request ID was reflected into the response.';
      }

      if (str_contains($response, $attackPrefix)) {
         return 'L2 response still contains the attacker-controlled correlation label outside the expected header.';
      }

      if (
         preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $responseID,
         ) !== 1
      ) {
         return 'L2 middleware did not replace the invalid client value with a bounded server request ID.';
      }

      return true;
   },
);
