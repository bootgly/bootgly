<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;

return new Specification(
   description: 'ACME(E2E): a preloaded full-wire cache entry never shadows the HTTP-01 responder',
   test: function () {
      // ! Keep-alive on purpose — `Connection: close` would make the request
      //   ineligible for the full-wire cache and the precedence under test
      //   would never be exercised
      $fetch = static function (string $target): array {
         $Socket = stream_socket_client('tcp://127.0.0.1:8098', $code, $message, 5);
         if ($Socket === false) {
            return ['code' => 0, 'headers' => '', 'body' => ''];
         }
         stream_set_timeout($Socket, 5);
         fwrite($Socket, "GET {$target} HTTP/1.1\r\nHost: localhost\r\n\r\n");
         $raw = '';
         while (feof($Socket) === false) {
            $chunk = fread($Socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $raw .= $chunk;

            // ? Stop at Content-Length — the worker holds the connection open
            $split = strpos($raw, "\r\n\r\n");
            if (
               $split !== false
               && preg_match('/^Content-Length:[ \t]*(\d+)/mi', substr($raw, 0, $split), $sized) === 1
               && strlen($raw) - ($split + 4) >= (int) $sized[1]
            ) {
               break;
            }
         }
         fclose($Socket);

         $split = strpos($raw, "\r\n\r\n");
         $headers = $split !== false ? substr($raw, 0, $split) : $raw;
         $body = $split !== false ? substr($raw, $split + 4) : '';
         preg_match('/^HTTP\/\S+ (\d+)/', $headers, $matches);

         return [
            'code' => (int) ($matches[1] ?? 0),
            'headers' => $headers,
            'body' => $body
         ];
      };

      // @ Plant a known token like the certifier does, so the responder is
      //   active and can authorize `e2e-Cache_Token-1`
      Challenges::save('e2e-Cache_Token-1', 'e2e-Cache_Token-1.account-thumbprint');

      // @ Plant stale full-wire entries INSIDE the worker (see the suite's
      //   `/plant` seam): an ordinary path plus a known and an unknown token
      //   under the reserved ACME namespace
      $response = $fetch('/plant');

      yield assert(
         assertion: $response['code'] === 200 && $response['body'] === 'planted',
         description: 'the worker planted the stale cache entries'
      );

      // @ Control probe — the ordinary planted path MUST serve from the
      //   cache, proving the planted entries are live in this worker
      $response = $fetch('/cached-probe');

      yield assert(
         assertion: $response['body'] === 'STALE-PROBE',
         description: 'an ordinary planted path serves the stale cached wire (cache is live)'
      );

      // @ Reserved namespace, known token — the responder must win over the
      //   stale cached wire and answer the current key authorization
      $response = $fetch('/.well-known/acme-challenge/e2e-Cache_Token-1');

      yield assert(
         assertion: $response['body'] === 'e2e-Cache_Token-1.account-thumbprint',
         description: 'a known token answers the current authorization, never the cached wire'
      );
      yield assert(
         assertion: stripos($response['headers'], 'Cache-Control: no-store') !== false,
         description: 'the authorization response stays no-store'
      );

      // @ Reserved namespace, unknown token — 404, never the cached wire
      $response = $fetch('/.well-known/acme-challenge/e2e-Cache_Unknown-1');

      yield assert(
         assertion: $response['code'] === 404 && $response['body'] !== 'STALE-UNKNOWN',
         description: 'an unknown token answers 404, never the cached wire'
      );

      Challenges::drop('e2e-Cache_Token-1');
   }
);
