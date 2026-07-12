<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Challenges;

return new Specification(
   description: 'ACME(E2E): built-in HTTP-01 responder wins over the user handler',
   test: function () {
      $fetch = static function (string $target): array {
         $Socket = stream_socket_client('tcp://127.0.0.1:8098', $code, $message, 5);
         if ($Socket === false) {
            return ['code' => 0, 'headers' => '', 'body' => ''];
         }
         stream_set_timeout($Socket, 5);
         fwrite($Socket, "GET {$target} HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n");
         $raw = '';
         while (feof($Socket) === false) {
            $chunk = fread($Socket, 8192);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $raw .= $chunk;

            // ? Stop at Content-Length — the worker may hold the connection
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

      // @ Plant a token exactly like the certifier does
      Challenges::save('e2e-Test_Token-1', 'e2e-Test_Token-1.account-thumbprint');

      $response = $fetch('/.well-known/acme-challenge/e2e-Test_Token-1');

      yield assert(
         assertion: $response['code'] === 200
            && $response['body'] === 'e2e-Test_Token-1.account-thumbprint',
         description: 'a known token answers 200 with the exact key authorization'
      );
      yield assert(
         assertion: stripos($response['headers'], 'Cache-Control: no-store') !== false,
         description: 'the validation response is never cacheable (no-store)'
      );
      yield assert(
         assertion: stripos($response['headers'], 'Content-Type: text/plain') !== false,
         description: 'the key authorization is served as text/plain'
      );

      // @ The hook wins over the user handler (which answers `handler`)
      yield assert(
         assertion: $response['body'] !== 'handler',
         description: 'the responder short-circuits before the user handler'
      );

      $response = $fetch('/.well-known/acme-challenge/unknown-token');

      yield assert(
         assertion: $response['code'] === 404,
         description: 'an unknown token answers 404 (the path is ACME-reserved)'
      );

      $response = $fetch('/anything-else');

      yield assert(
         assertion: $response['code'] === 200 && $response['body'] === 'handler',
         description: 'any other path still reaches the user handler'
      );

      Challenges::drop('e2e-Test_Token-1');
   }
);
