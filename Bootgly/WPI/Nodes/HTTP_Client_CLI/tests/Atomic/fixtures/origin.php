<?php
/*
 * A scripted origin for the redirect-policy cases (10.x).
 *
 * Returns a closure: `(string $address, Closure $Answer, string $log, array $TLS = []) => int`
 * that forks one origin listening on `$address` (`127.0.0.1:19900`,
 * `[::1]:19901`, ...) — TLS when `$TLS` holds server `ssl` context options —
 * and returns its PID. Every request it reads (head and Content-Length body)
 * is appended to `$log` as one JSON line: `method`, `target`, `headers`
 * (lowercased names), `body`, `connection` (the accept count, so a reused
 * keep-alive connection is visible) and `certificate` (the client
 * certificate's CN, when one was presented). `$Answer(array $request)`
 * returns the raw response; the connection stays open for the next request
 * unless that response says `Connection: close`.
 *
 * It serves until SIGTERM — the caller kills and reaps it — or until its
 * parent is gone. A failed fork throws instead of returning a PID.
 */


return static function (string $address, Closure $Answer, string $log, array $TLS = []): int {
   $parent = getmypid();
   $PID = pcntl_fork();
   // ? A failed fork must never hand back -1: the caller would signal every process it owns
   if ($PID < 0) {
      throw new RuntimeException("The origin fixture could not fork for {$address}.");
   }
   if ($PID > 0) {
      return $PID;
   }

   $scheme = $TLS === [] ? 'tcp' : 'tls';
   $Context = stream_context_create($TLS === [] ? [] : ['ssl' => $TLS]);
   $Server = @stream_socket_server(
      "{$scheme}://{$address}",
      $errno,
      $error,
      STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
      $Context
   );
   if ($Server === false) {
      file_put_contents($log, json_encode(['error' => "bind {$address}: {$error}"]) . "\n", FILE_APPEND);
      exit(1);
   }

   $connection = 0;
   while (true) {
      // ? Orphaned — the spec died before reaping it: free the port and go
      if (posix_getppid() !== $parent) {
         exit(0);
      }

      $Peer = @stream_socket_accept($Server, 1);
      if ($Peer === false) {
         continue;
      }
      $connection++;
      // ! One connection at a time: an idle keep-alive peer must not hold the
      //   next client in the backlog for long
      stream_set_timeout($Peer, 1);

      $certificate = null;
      $parameters = stream_context_get_params($Peer);
      $peer = $parameters['options']['ssl']['peer_certificate'] ?? null;
      if ($peer !== null) {
         $parsed = openssl_x509_parse($peer);
         $certificate = is_array($parsed) ? ($parsed['subject']['CN'] ?? '?') : '?';
      }

      $input = '';
      while (true) {
         // @ One request: the head, then its Content-Length body
         while (($end = strpos($input, "\r\n\r\n")) === false) {
            $chunk = @fread($Peer, 65535);
            if ($chunk === false || $chunk === '') {
               break 2;
            }
            $input .= $chunk;
         }

         $head = substr($input, 0, $end);
         $input = substr($input, $end + 4);
         $length = preg_match('/\r\ncontent-length:\s*(\d+)/i', $head, $matches) === 1 ? (int) $matches[1] : 0;
         while (strlen($input) < $length) {
            $chunk = @fread($Peer, 65535);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $input .= $chunk;
         }
         $body = substr($input, 0, $length);
         $input = substr($input, $length);

         $lines = explode("\r\n", $head);
         $line = explode(' ', $lines[0]);
         $headers = [];
         foreach (array_slice($lines, 1) as $field) {
            $colon = strpos($field, ':');
            if ($colon !== false) {
               $headers[strtolower(substr($field, 0, $colon))] = trim(substr($field, $colon + 1));
            }
         }

         $request = [
            'method' => $line[0],
            'target' => $line[1] ?? '',
            'headers' => $headers,
            'body' => $body,
            'connection' => $connection,
            'certificate' => $certificate,
         ];
         file_put_contents($log, json_encode($request) . "\n", FILE_APPEND);

         $response = $Answer($request);
         @fwrite($Peer, $response);

         if (stripos($response, "\r\nConnection: close\r\n") !== false) {
            usleep(20000);
            break;
         }
      }

      @fclose($Peer);
   }
};
