<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Account;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\ACME_Client\Exceptions\ServerException;

return new Specification(
   description: 'ACME protocol faults: badNonce provenance and unsigned problems survive real TLS transport',
   test: function () {
      $certificate = __DIR__
         . '/../../../HTTP_Client_CLI/tests/E2E_SSL/localhost.cert.pem';
      $key = __DIR__
         . '/../../../HTTP_Client_CLI/tests/E2E_SSL/localhost.key.pem';

      /**
       * Run one action against a scripted self-signed HTTPS peer.
       *
       * @param array<int,string|Closure(string):string> $responses
       * @param Closure(string):mixed $Action
       * @return array{mixed, null|Throwable, array<int,array{line:string,body:string}>}
       */
      $Run = static function (array $responses, Closure $Action) use ($certificate, $key): array {
         $Context = stream_context_create([
            'ssl' => [
               'local_cert' => $certificate,
               'local_pk' => $key,
               'verify_peer' => false
            ]
         ]);
         $Listener = stream_socket_server(
            'tls://127.0.0.1:0',
            $code,
            $message,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
            $Context
         );
         if ($Listener === false) {
            return [null, new RuntimeException("TLS fixture failed: {$message}", $code), []];
         }

         $address = stream_socket_get_name($Listener, false);
         $separator = is_string($address) ? strrpos($address, ':') : false;
         $port = $separator === false ? 0 : (int) substr($address, $separator + 1);
         $capture = tempnam(sys_get_temp_dir(), 'bootgly-acme-fault-');
         $PID = $port > 0 ? pcntl_fork() : -1;

         if ($PID === 0) {
            $requests = [];
            foreach ($responses as $script) {
               $Peer = @stream_socket_accept($Listener, 10.0);
               if ($Peer === false) {
                  break;
               }
               stream_set_timeout($Peer, 5);

               $input = '';
               while (! str_contains($input, "\r\n\r\n") && ! feof($Peer)) {
                  $chunk = @fread($Peer, 8192);
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $input .= $chunk;
               }

               $headEnd = strpos($input, "\r\n\r\n");
               $head = $headEnd === false ? $input : substr($input, 0, $headEnd);
               $body = $headEnd === false ? '' : substr($input, $headEnd + 4);
               preg_match('/^Content-Length:\s*(\d+)\s*$/mi', $head, $matches);
               $length = (int) ($matches[1] ?? 0);
               while (strlen($body) < $length && ! feof($Peer)) {
                  $chunk = @fread($Peer, $length - strlen($body));
                  if ($chunk === false || $chunk === '') {
                     break;
                  }
                  $body .= $chunk;
               }

               $lineEnd = strpos($head, "\r\n");
               $requests[] = [
                  'line' => $lineEnd === false ? $head : substr($head, 0, $lineEnd),
                  'body' => $body
               ];

               $rawResponse = $script instanceof Closure
                  ? $script("https://127.0.0.1:{$port}")
                  : $script;
               $offset = 0;
               while ($offset < strlen($rawResponse)) {
                  $written = @fwrite($Peer, substr($rawResponse, $offset));
                  if ($written === false || $written === 0) {
                     break;
                  }
                  $offset += $written;
               }
               @fflush($Peer);
               @fclose($Peer);
            }

            file_put_contents((string) $capture, json_encode($requests));
            fclose($Listener);
            exit(0);
         }

         fclose($Listener);
         if ($PID < 1 || $port < 1) {
            @unlink((string) $capture);
            return [null, new RuntimeException('TLS fixture could not fork.'), []];
         }

         $value = null;
         $error = null;
         try {
            $value = $Action("https://127.0.0.1:{$port}");
         }
         catch (Throwable $caught) {
            $error = $caught;
         }

         pcntl_waitpid($PID, $status);
         $encoded = file_get_contents((string) $capture);
         @unlink((string) $capture);
         $decoded = is_string($encoded) ? json_decode($encoded, true) : null;

         return [$value, $error, is_array($decoded) ? $decoded : []];
      };

      $HTTP = static function (
         int $code,
         string $status,
         string $body,
         array $headers = []
      ): string {
         $fields = [
            "HTTP/1.1 {$code} {$status}",
            'Content-Length: ' . strlen($body),
            'Connection: close'
         ];
         foreach ($headers as $name => $value) {
            $fields[] = "{$name}: {$value}";
         }

         return implode("\r\n", $fields) . "\r\n\r\n{$body}";
      };

      $problem = json_encode([
         'type' => 'urn:ietf:params:acme:error:badNonce',
         'detail' => 'use the nonce carried by this response'
      ]);
      $success = json_encode(['status' => 'valid']);

      [$result, $error, $requests] = $Run([
         $HTTP(400, 'Bad Request', (string) $problem, [
            'Content-Type' => 'application/problem+json',
            'Replay-Nonce' => 'retry-response-nonce'
         ]),
         $HTTP(200, 'OK', (string) $success, [
            'Content-Type' => 'application/json',
            'Replay-Nonce' => 'future-response-nonce'
         ])
      ], static function (string $base): array {
         $path = sys_get_temp_dir() . '/bootgly-acme-badnonce-' . getmypid() . '/';
         $Client = new ACME_Client(
            new Account($path),
            "{$base}/directory",
            verify: false,
            allowPrivate: true
         );

         $Property = new ReflectionProperty($Client, 'Nonces');
         $Nonces = $Property->getValue($Client);
         $Nonces->store('stale-pooled-nonce');
         $Nonces->store('initial-request-nonce');

         $Post = new ReflectionMethod($Client, 'post');
         $response = $Post->invoke($Client, "{$base}/order", ['identifiers' => []]);
         $remaining = [$Nonces->take(), $Nonces->take()];

         foreach (glob("{$path}*") ?: [] as $file) {
            @unlink($file);
         }
         @rmdir($path);

         return [$response, $remaining];
      });

      $nonces = [];
      foreach ($requests as $request) {
         $JWS = json_decode($request['body'] ?? '', true);
         $protected = is_array($JWS) && is_string($JWS['protected'] ?? null)
            ? $JWS['protected']
            : '';
         $padding = (4 - strlen($protected) % 4) % 4;
         $header = json_decode(
            (string) base64_decode(strtr($protected, '-_', '+/') . str_repeat('=', $padding), true),
            true
         );
         $nonces[] = is_array($header) ? ($header['nonce'] ?? null) : null;
      }

      yield assert(
         assertion: $error === null
            && is_array($result)
            && ($result[0]['code'] ?? null) === 200
            && $nonces === ['initial-request-nonce', 'retry-response-nonce'],
         description: 'badNonce retries exactly with that error response nonce, never a stale pooled nonce'
      );
      yield assert(
         assertion: is_array($result)
            && ($result[1] ?? null) === ['future-response-nonce', null],
         description: 'the suspect pool is cleared while the successful response nonce remains available'
      );

      $unsigned = json_encode([
         'type' => 'urn:ietf:params:acme:error:rateLimited',
         'detail' => 'directory temporarily unavailable'
      ]);
      [$value, $unsignedError, $unsignedRequests] = $Run([
         $HTTP(503, 'Service Unavailable', (string) $unsigned, [
            'Content-Type' => 'application/problem+json; charset=utf-8',
            'Retry-After' => '17'
         ])
      ], static function (string $base): mixed {
         $path = sys_get_temp_dir() . '/bootgly-acme-unsigned-' . getmypid() . '/';
         $Client = new ACME_Client(
            new Account($path),
            "{$base}/directory",
            verify: false,
            allowPrivate: true
         );

         return (new ReflectionMethod($Client, 'connect'))->invoke($Client);
      });

      yield assert(
         assertion: $value === null
            && $unsignedError instanceof ServerException
            && $unsignedError->type === 'urn:ietf:params:acme:error:rateLimited'
            && $unsignedError->detail === 'directory temporarily unavailable'
            && $unsignedError->status === 503
            && $unsignedError->retryAfter === 17
            && count($unsignedRequests) === 1
            && str_starts_with($unsignedRequests[0]['line'] ?? '', 'GET /directory '),
         description: 'an unsigned directory problem preserves type, detail, status and Retry-After'
      );

      // ? A legal HEAD response has no body even when its Content-Type is
      //   problem+json. The typed server failure must still retain status and
      //   Retry-After, with safe fallbacks for the unavailable JSON members.
      [$headValue, $headError, $headRequests] = $Run([
         static function (string $base) use ($HTTP): string {
            $directory = json_encode([
               'newAccount' => "{$base}/new-account",
               'newNonce' => "{$base}/new-nonce",
               'newOrder' => "{$base}/new-order"
            ]);

            return $HTTP(200, 'OK', (string) $directory, [
               'Content-Type' => 'application/json'
            ]);
         },
         $HTTP(429, 'Too Many Requests', '', [
            'Content-Type' => 'application/problem+json',
            'Retry-After' => '23'
         ])
      ], static function (string $base): mixed {
         $Client = new ACME_Client(
            new Account(sys_get_temp_dir() . '/bootgly-acme-head-' . getmypid() . '/'),
            "{$base}/directory",
            verify: false,
            allowPrivate: true
         );

         return (new ReflectionMethod($Client, 'fetch'))->invoke($Client);
      });

      yield assert(
         assertion: $headValue === null
            && $headError instanceof ServerException
            && $headError->type === 'about:blank'
            && str_contains($headError->detail, 'newNonce')
            && $headError->status === 429
            && $headError->retryAfter === 23
            && count($headRequests) === 2
            && str_starts_with($headRequests[0]['line'] ?? '', 'GET /directory ')
            && str_starts_with($headRequests[1]['line'] ?? '', 'HEAD /new-nonce '),
         description: 'a bodyless newNonce HEAD problem keeps typed status and Retry-After with safe fallbacks'
      );
   }
);
