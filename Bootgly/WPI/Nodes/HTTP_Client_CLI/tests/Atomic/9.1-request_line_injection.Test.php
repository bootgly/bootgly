<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Session;


/**
 * Security PoC H3 — method/request-target bytes must be rejected before an
 * HTTP/1 connection or HTTP/2 stream can carry them.
 *
 * The child is a real TCP origin. A safe GET proves the complete public
 * Request -> Encoder_ -> socket path first. Two attacker legs then place a
 * second request-line inside the URI and method. The secure behavior is a
 * synchronous InvalidArgumentException with no second accepted connection.
 */
return new Test(
   description: 'HTTP client request-line inputs must be rejected before wire',
   test: function () {
      $Listener = @stream_socket_server(
         'tcp://127.0.0.1:0',
         $errorCode,
         $errorMessage,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      $Channel = @stream_socket_pair(
         STREAM_PF_UNIX,
         STREAM_SOCK_STREAM,
         STREAM_IPPROTO_IP
      );
      $address = is_resource($Listener)
         ? stream_socket_get_name($Listener, false)
         : false;
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr($address, $separator + 1);

      if (
         is_resource($Listener) === false
         || is_array($Channel) === false
         || $port < 1
      ) {
         is_resource($Listener) && fclose($Listener);
         if (is_array($Channel)) {
            fclose($Channel[0]);
            fclose($Channel[1]);
         }

         yield assert(
            assertion: false,
            description: "H3 fixture could not create its loopback origin: {$errorMessage} ({$errorCode})"
         );
         return;
      }

      $PID = pcntl_fork();
      if ($PID < 0) {
         fclose($Listener);
         fclose($Channel[0]);
         fclose($Channel[1]);

         yield assert(
            assertion: false,
            description: 'H3 fixture could not fork its loopback origin'
         );
         return;
      }

      if ($PID === 0) {
         fclose($Channel[0]);
         $wires = [];

         // @@ One control plus at most two attacker connections. On secure
         //    code the second accept times out because both inputs fail before
         //    connect(); on vulnerable code all three reach the origin.
         for ($attempt = 0; $attempt < 3; $attempt++) {
            $Peer = @stream_socket_accept($Listener, $attempt === 0 ? 3 : 1);
            if ($Peer === false) {
               break;
            }

            stream_set_timeout($Peer, 1);
            $wire = '';
            while (strlen($wire) < 65536) {
               $chunk = @fread($Peer, 65536 - strlen($wire));
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $wire .= $chunk;

               if (str_contains($wire, "\r\n\r\n")) {
                  // @ The injected second head is part of the same client
                  //   write. Drain any immediately readable suffix so the
                  //   transcript does not depend on TCP packet boundaries.
                  stream_set_blocking($Peer, false);
                  $deadline = microtime(true) + 0.1;
                  do {
                     $read = [$Peer];
                     $write = null;
                     $except = null;
                     $selected = @stream_select($read, $write, $except, 0, 10000);
                     if ($selected === 1) {
                        $suffix = @fread($Peer, 65536 - strlen($wire));
                        if (is_string($suffix) && $suffix !== '') {
                           $wire .= $suffix;
                        }
                     }
                  }
                  while ($selected === 1 && microtime(true) < $deadline);
                  break;
               }
            }
            $wires[] = $wire;

            $body = $attempt === 0 ? 'H3-control' : 'H3-attack';
            @fwrite(
               $Peer,
               "HTTP/1.1 200 OK\r\nContent-Length: " . strlen($body)
                  . "\r\nConnection: close\r\n\r\n{$body}"
            );
            @fclose($Peer);
         }

         @fwrite($Channel[1], (string) json_encode(['wires' => $wires]));
         fclose($Channel[1]);
         fclose($Listener);
         exit(0);
      }

      fclose($Channel[1]);
      fclose($Listener);

      $URI = "/first HTTP/1.1\r\nHost: internal\r\n\r\nPOST /admin";
      $method = "GET /first HTTP/1.1\r\nHost: internal\r\n\r\nDELETE";
      $controlError = '';
      $URIError = '';
      $methodError = '';
      $Control = null;

      try {
         $Client = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
         $Client->configure('127.0.0.1', $port);
         $Client->connectTimeout = 2;
         $Client->timeout = 2;

         try {
            $Control = $Client->request('GET', '/h3-control');
         }
         catch (Throwable $Throwable) {
            $controlError = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         try {
            $Client->request('GET', $URI);
         }
         catch (Throwable $Throwable) {
            $URIError = $Throwable::class;
         }

         try {
            $Client->request($method, '/method');
         }
         catch (Throwable $Throwable) {
            $methodError = $Throwable::class;
         }
      }
      finally {
         pcntl_waitpid($PID, $status);
      }

      $encoded = stream_get_contents($Channel[0]);
      fclose($Channel[0]);
      $evidence = is_string($encoded) ? json_decode($encoded, true) : null;
      $wires = is_array($evidence) && is_array($evidence['wires'] ?? null)
         ? $evidence['wires']
         : [];

      $control = $Control instanceof Response
         && $Control->code === 200
         && $Control->Body->raw === 'H3-control'
         && count($wires) >= 1
         && str_starts_with((string) $wires[0], "GET /h3-control HTTP/1.1\r\n");

      yield assert(
         assertion: $control,
         description: 'H3 control must traverse Request -> Encoder_ -> socket: '
            . json_encode([
               'error' => $controlError,
               'code' => $Control instanceof Response ? $Control->code : null,
               'body' => $Control instanceof Response ? $Control->Body->raw : null,
               'wires' => count($wires),
            ])
      );
      if ($control === false) {
         return;
      }

      $URIInjected = false;
      $methodInjected = false;
      foreach (array_slice($wires, 1) as $wire) {
         $URIInjected = $URIInjected
            || str_contains((string) $wire, "\r\n\r\nPOST /admin HTTP/1.1\r\n");
         $methodInjected = $methodInjected
            || str_contains((string) $wire, "\r\n\r\nDELETE /method HTTP/1.1\r\n");
      }

      $secure = $URIError === InvalidArgumentException::class
         && $methodError === InvalidArgumentException::class
         && count($wires) === 1
         && $URIInjected === false
         && $methodInjected === false;

      if ($secure === false && ($URIInjected === false || $methodInjected === false)) {
         yield assert(
            assertion: false,
            description: 'H3 fixture did not prove both injection legs: '
               . json_encode([
                  'URI_error' => $URIError,
                  'method_error' => $methodError,
                  'URI_injected' => $URIInjected,
                  'method_injected' => $methodInjected,
                  'wires' => array_map('base64_encode', $wires),
               ])
         );
         return;
      }

      yield assert(
         assertion: $secure,
         description: $secure
            ? 'H3 secure behavior: invalid method and request-target stop before wire'
            : 'CONFIRMED H3: malicious method and request-target reached the HTTP client wire and injected second request-lines; evidence='
               . json_encode([
                  'URI_error' => $URIError,
                  'method_error' => $methodError,
                  'wires' => array_map('base64_encode', $wires),
               ])
      );

      // # Defense in depth — direct public sinks must enforce the same rule.
      $encoderRejected = [];
      foreach ([
         ['GET', $URI, 'HTTP/1.1'],
         [$method, '/method', 'HTTP/1.1'],
         ['GET', '/protocol', "HTTP/1.1\r\nX-Injected: yes"],
      ] as [$probeMethod, $probeURI, $probeProtocol]) {
         try {
            $length = null;
            Encoder_::encode(
               $probeMethod,
               $probeURI,
               $probeProtocol,
               '',
               length: $length
            );
            $encoderRejected[] = false;
         }
         catch (InvalidArgumentException) {
            $encoderRejected[] = true;
         }
      }

      $Snapshot = static fn (Session $Session): array => [
         $Session->outbox,
         $Session->next,
         $Session->opened,
         $Session->Streams,
      ];
      $HTTP2Rejected = [];
      $HTTP2Atomic = [];
      foreach ([['GET', $URI], [$method, '/method']] as [$probeMethod, $probeURI]) {
         $Session = new Session;
         $before = $Snapshot($Session);
         try {
            $Session->open($probeMethod, 'http', 'example.test', $probeURI, []);
            $HTTP2Rejected[] = false;
         }
         catch (InvalidArgumentException) {
            $HTTP2Rejected[] = true;
         }
         $HTTP2Atomic[] = $Snapshot($Session) === $before;
      }

      yield assert(
         assertion: $encoderRejected === [true, true, true]
            && $HTTP2Rejected === [true, true]
            && $HTTP2Atomic === [true, true],
         description: 'H3 sink guards reject direct HTTP/1 and HTTP/2 use before mutation'
      );

      // # Compatibility controls — extension methods and RFC asterisk-form
      //   remain valid when no request-line delimiter can be injected.
      $length = null;
      $wire = Encoder_::encode(
         'PURGE',
         '/cache?key=a%20b',
         'HTTP/1.1',
         '',
         length: $length
      );
      $Options = new Session;
      $stream = $Options->open('OPTIONS', 'http', 'example.test', '*', []);
      $Resolver = (new ReflectionClass($Client))->getMethod('resolve');
      $resolved = $Resolver->invoke(
         $Client,
         '#fragment',
         '*',
         'OPTIONS',
         'HTTP/1.1'
      );

      yield assert(
         assertion: Request::check('PURGE', '/cache?key=a%20b', 'HTTP/1.1')
            && Request::check('OPTIONS', '*')
            && str_starts_with($wire, "PURGE /cache?key=a%20b HTTP/1.1\r\n")
            && $stream === 1
            && $Options->opened === 1
            && is_array($resolved)
            && $resolved['path'] === '*',
         description: 'H3 controls preserve extension methods, encoded spaces, OPTIONS * and fragment redirects'
      );

      // # Memo integrity — the public fields are diagnostic mirrors, never
      //   the authority for bytes reused after an application callback.
      $MemoClient = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_TEST);
      $MemoClient->configure('example.test', 80);
      $MemoRequest = new Request;
      $MemoRequest('GET', '/memo');
      $memoLength = null;
      $canonical = Encoder_::encode(
         'GET',
         '/memo',
         'HTTP/1.1',
         '',
         host: 'example.test',
         length: $memoLength
      );
      $Reflection = new ReflectionClass($MemoClient);
      $Remember = $Reflection->getMethod('remember');
      $Recall = $Reflection->getMethod('recall');
      $Remember->invoke($MemoClient, $MemoRequest, $canonical);
      $initial = $Recall->invoke($MemoClient, $MemoRequest);

      $MemoRequest->encoded = "GET /safe HTTP/1.1\r\n\r\nDELETE /admin HTTP/1.1\r\n\r\n";
      $tampered = $Recall->invoke($MemoClient, $MemoRequest);
      $MemoRequest->encoded = $canonical;
      $MemoRequest->Header->set('X-Changed', 'yes');
      $changed = $Recall->invoke($MemoClient, $MemoRequest);

      yield assert(
         assertion: $initial === $canonical
            && $tampered === null
            && $changed === null,
         description: 'H3 private memo rejects replaced wire bytes and changed request inputs'
      );
   }
);
