<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use const Bootgly\WPI;
use Bootgly\ABI\Debugging\Data\Throwables;
use Bootgly\ABI\Events\Emission;
use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Environments;
use Bootgly\API\Workables\Server as SAPI;
use Bootgly\API\Workables\Server\Middlewares;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\HTTP2;
use Bootgly\WPI\Modules\HTTP2\Frame;
use Bootgly\WPI\Modules\HTTP2\HPACK;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies\Refusal;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_Downloading\Downloads;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_HTTP2;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Encoder_Testing;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Events as RequestEvents;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/**
 * BUGS ENC-12 — a `Request\Events::Received` listener that throws refuses the
 * request; it never kills the worker.
 *
 * Received runs before the routing catch, and its Throwable used to leave
 * `encode()` and reach the reactor: the worker died with every connection it
 * served. The events guide documents throwing from a pre-routing listener as
 * the way to refuse a request, so any client the gate refused could kill a
 * worker. Driven over a real connection on a socket pair, the request decoded
 * by `Decoder_`, through the production encoder (`Encoder_`) and its
 * Test-environment mirror (`Encoder_Testing`), with a recording `Throwables`
 * reporter. Pinned, for both encoders:
 *
 * - a listener that throws an application error: `reading()` returns without
 *   a Throwable, the wire is `500 Internal Server Error`, the error is
 *   reported once, the connection is closed and the handler never runs;
 * - a listener that throws a budget `Decoders\Bodies\Refusal`: the wire is
 *   `503 Service Unavailable`, nothing is reported, the connection is closed;
 * - a listener that does not throw: the handler answers `200`;
 * - a refused request is shed: its URI and head are gone after the refusal;
 * - the report names the `Received` phase and the path only: the query
 *   string (`?token=secret`) never reaches a reporter — not even from a
 *   request-target that starts with `?`, on HTTP/1 and HTTP/2, at the
 *   Received catch and at the routing catch (`Catcher`);
 * - a refused multipart upload (500 and 503) takes its upload with it: the
 *   temp file the decoder wrote to `BOOTGLY_UPLOADS_DIR` is gone, the
 *   cross-worker `Downloads` counter is back to where it was and the
 *   tracking map gained nothing. Run in a forked child bound to a private
 *   controller, so the runner's counter binding is never touched; the child
 *   cannot hang the run or outlive it (an alarm, a reaped or killed child,
 *   none of the runner's output pipes) and the uploads it reports are swept
 *   whatever it ends with;
 * - a refused HTTP/2 stream (500 and 503) is denied alone: a HEADERS frame
 *   with its status and END_STREAM on that stream, written with the read
 *   that refused it (not held until the client's next frame), no raw
 *   HTTP/1.1 bytes and no GOAWAY on the connection, which stays open — its
 *   sibling stream, in flight when the refusal happened, is answered `200`
 *   afterwards, and a complete stream that follows the refused one in the
 *   same read is answered `200` with that read. The refused stream is
 *   released (no longer tracked or counted open), its HEADERS frame carries
 *   END_HEADERS, and its Request keeps nothing of what the gate read: body,
 *   decoded input, URI, head and bearer credential.
 */
return new Test(
   description: 'ENC-12: a throwing Received listener refuses the request and never kills the worker',
   test: new Assertions(Case: function (): Generator {
      // ! Statics this case writes — directly or through the encoders
      $OldEmitter = Emitter::$Instance;
      $OldDecoder = TCP_Server_CLI::$Decoder;
      $OldEncoder = TCP_Server_CLI::$Encoder;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldContext = isset(TCP_Server_CLI::$context) ? TCP_Server_CLI::$context : null;
      $OldRequest = isset(HTTP_Server_CLI::$Request) ? HTTP_Server_CLI::$Request : null;
      $OldResponse = isset(HTTP_Server_CLI::$Response) ? HTTP_Server_CLI::$Response : null;
      $OldRouter = isset(HTTP_Server_CLI::$Router) ? HTTP_Server_CLI::$Router : null;
      $OldHandler = isset(SAPI::$Handler) ? SAPI::$Handler : null;
      $OldMiddlewares = isset(SAPI::$Middlewares) ? SAPI::$Middlewares : null;
      $OldEnvironment = SAPI::$Environment;
      $oldIndex = SAPI::$testIndexHeader;
      $OldCatcher = Catcher::$Environment;
      $OldReporters = Throwables::$reporters;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;

      $Sockets = [];
      $Connections = [];
      $reports = [];
      $handled = 0;
      $port = 40060;
      // ! The upload the gate saw, recorded before it refused
      $seen = [];
      $Tracked = new ReflectionProperty(Downloads::class, 'tracked');
      $token = bin2hex(random_bytes(4));
      $directory = BOOTGLY_STORAGE_DIR . "tests/u168-{$token}/";
      $controller = "{$directory}u168.lock.downloads";
      $temporaries = [];
      // ! The request the gate refused, and what it read from an HTTP/2 one
      $Refused = null;
      $gated = [];
      // ! The forked child's upload reporter (null in the runner)
      $Spill = null;

      // ! A fresh connection: a refusal closes its own
      $Open = static function () use (&$Sockets, &$Connections, &$port): array {
         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U168 could not open a socket pair.');
         }
         [$Server, $Client] = $Pair;
         stream_set_blocking($Server, false);
         stream_set_blocking($Client, false);
         $Sockets[] = $Server;
         $Sockets[] = $Client;
         $Connection = new Connection($Server, '127.0.0.1', ++$port);
         $Connections[] = $Connection;

         return [$Server, $Client, $Connection];
      };
      // @ The listener's Throwable must not escape the transport
      $Read = static function (Connection $Connection, mixed $Server): null|string {
         try {
            $Connection->reading($Server);
         }
         catch (Throwable $Throwable) {
            return $Throwable::class;
         }

         return null;
      };
      $Drain = static function (mixed $Client): string {
         $wire = '';
         while (($chunk = @fread($Client, 65_536)) !== false && $chunk !== '') {
            $wire .= $chunk;
         }

         return $wire;
      };

      // ! No SIGALRM while this case runs: an idle timer would arm the
      //   reactor's alarm with no handler installed here — the runner killed
      //   mid-case, its cleanup never run. A pending one waits until the end.
      $oldAlarm = pcntl_alarm(0);

      try {
         TCP_Server_CLI::$connectionIdleTimeout = 0;
         // ! Plain connections: a TLS context an earlier suite left behind
         //   would make every socket pair start a handshake and close
         TCP_Server_CLI::$context = [];
         TCP_Server_CLI::$Decoder = new Decoder_;
         HTTP_Server_CLI::$enableHTTP2 = true;
         SAPI::$Environment = Environments::Test;
         Catcher::$Environment = null;
         Throwables::$reporters = [
            static function (Throwable $Throwable, array $context) use (&$reports): void {
               $reports[] = [
                  'class' => $Throwable::class,
                  'phase' => $context['phase'] ?? null,
                  'URI' => $context['URI'] ?? null,
                  'query' => preg_match('/token|secret/', (string) json_encode($context)) === 1,
               ];
            },
         ];
         SAPI::$Handler = static function (Request $Request, Response $Response) use (&$handled, &$mode): Response {
            $handled++;

            // ! The routing-catch legs: the route itself fails
            if ($mode === 'throw') {
               throw new RuntimeException('U168 route failed');
            }

            return $Response(body: 'answered');
         };

         // ! What the listener does with each request to the gate (`/gate`,
         //   or a request-target that starts with `?`); any other path (an
         //   HTTP/2 sibling stream) always passes
         $mode = 'pass';
         Emitter::$Instance = new Emitter;
         Emitter::$Instance->listen(
            RequestEvents::Received,
            static function (Emission $Emission) use (&$mode, &$seen, &$Refused, &$gated, &$Spill, $Tracked): void {
               $Request = $Emission->payload[0] ?? null;
               if (
                  $Request instanceof Request === false
                  || (str_starts_with($Request->URI, '/gate') === false && str_starts_with($Request->URI, '?') === false)
               ) {
                  return;
               }

               if ($Request->hasFiles) {
                  $file = $Request->files['upload'] ?? [];
                  $temporary = is_string($file['tmp_name'] ?? null) ? $file['tmp_name'] : '';
                  clearstatcache();
                  $seen = [
                     'temporary' => $temporary,
                     'exists' => $temporary !== '' && is_file($temporary),
                     'size' => $file['size'] ?? null,
                     'peek' => Downloads::peek(),
                     'tracked' => $Tracked->getValue(),
                  ];
                  // ! The forked child reports each upload the moment the
                  //   gate sees it: a refusal that hangs still has it swept
                  if ($Spill !== null && $temporary !== '') {
                     $Spill($temporary);
                  }
               }

               if ($mode === 'fail' || $mode === 'refuse') {
                  $Refused = $Request;
                  // ! What the gate reads from an HTTP/2 stream it refuses:
                  //   its body, decoded input, URI and bearer credential
                  if ($Request->stream !== 0) {
                     $gated = [
                        'raw' => $Request->Body->raw,
                        'input' => $Request->input,
                        'URI' => $Request->URI,
                        'token' => $Request->token,
                     ];
                  }
               }
               if ($mode === 'fail') {
                  throw new RuntimeException('U168 gate refused the request');
               }
               if ($mode === 'refuse') {
                  throw new Refusal(Bodies::REFUSAL);
               }
            }
         );
         $Prepare = static function (string $leg) use (&$mode, &$reports, &$handled, &$seen, &$Refused, &$gated): void {
            HTTP_Server_CLI::$Response = new Response;
            HTTP_Server_CLI::$Router = new Router;
            SAPI::$Middlewares = new Middlewares;
            SAPI::$testIndexHeader = null;
            $mode = $leg;
            $reports = [];
            $handled = 0;
            $seen = [];
            $Refused = null;
            $gated = [];
         };
         $encoders = [Encoder_::class, Encoder_Testing::class];

         // # HTTP/1 — the gate refuses, or lets the handler answer
         $rows = [];
         $contexts = [];
         foreach ($encoders as $class) {
            TCP_Server_CLI::$Encoder = new $class;
            $short = substr($class, strrpos($class, '\\') + 1);
            foreach (['fail', 'refuse', 'pass'] as $leg) {
               $Prepare($leg);
               [$Server, $Client, $Connection] = $Open();

               fwrite($Client, "GET /gate?token=secret HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $escaped = $Read($Connection, $Server);
               $wire = $Drain($Client);

               $rows["{$short} {$leg}"] = [
                  'escaped' => $escaped,
                  'status' => strstr($wire, "\r\n", true) ?: $wire,
                  'reports' => array_column($reports, 'class'),
                  'handled' => $handled,
                  'closed' => $Connection->status > Connections::STATUS_ESTABLISHED,
                  // ! The refused request is shed: no URI, no head
                  'shed' => $Refused instanceof Request
                     ? $Refused->URI === '' && $Refused->headers === []
                     : null,
               ];
               if ($leg === 'fail') {
                  $contexts[$short]['h1 fail /gate?token=secret'] = $reports;
               }
            }

            // @ The report context of a refusal and of a routing failure,
            //   for a request-target that starts with `?` too
            $legs = [
               ['fail', '?token=secret'],
               ['throw', '/x?token=secret'],
               ['throw', '?token=secret'],
            ];
            foreach ($legs as [$leg, $target]) {
               $Prepare($leg);
               [$Server, $Client, $Connection] = $Open();

               fwrite($Client, "GET {$target} HTTP/1.1\r\nHost: localhost\r\n\r\n");
               $Read($Connection, $Server);
               $Drain($Client);
               $contexts[$short]["h1 {$leg} {$target}"] = $reports;
            }
            foreach (['fail', 'throw'] as $leg) {
               $Prepare($leg);
               [$Server, $Client, $Connection] = $Open();

               fwrite($Client, HTTP2::PREFACE
                  . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
                  . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 1, HPACK::encode([
                     [':method', 'GET'],
                     [':scheme', 'http'],
                     [':path', '?token=secret'],
                     [':authority', 'localhost'],
                  ])));
               $Read($Connection, $Server);
               $Drain($Client);
               $contexts[$short]["h2 {$leg} ?token=secret"] = $reports;
            }
         }
         $evidence = json_encode($rows);
         $failed = ['escaped' => null, 'status' => 'HTTP/1.1 500 Internal Server Error', 'reports' => [RuntimeException::class], 'handled' => 0, 'closed' => true, 'shed' => true];
         $refused = ['escaped' => null, 'status' => 'HTTP/1.1 503 Service Unavailable', 'reports' => [], 'handled' => 0, 'closed' => true, 'shed' => true];
         $passed = ['escaped' => null, 'status' => 'HTTP/1.1 200 OK', 'reports' => [], 'handled' => 1, 'closed' => false, 'shed' => null];

         yield assert(
            assertion: $rows === [
               'Encoder_ fail' => $failed,
               'Encoder_ refuse' => $refused,
               'Encoder_ pass' => $passed,
               'Encoder_Testing fail' => $failed,
               'Encoder_Testing refuse' => $refused,
               'Encoder_Testing pass' => $passed,
            ],
            description: "A throwing Received listener refuses its request — 500 reported once, or 503 unreported for a budget refusal — closes the connection, sheds the request and never reaches the reactor; a passing one lets the handler answer: {$evidence}",
         );

         // ---
         $evidence = json_encode($contexts);
         $Report = static fn (null|string $phase, string $URI): array => [
            ['class' => RuntimeException::class, 'phase' => $phase, 'URI' => $URI, 'query' => false],
         ];
         $context = [
            'h1 fail /gate?token=secret' => $Report('Received', '/gate'),
            'h1 fail ?token=secret' => $Report('Received', ''),
            'h1 throw /x?token=secret' => $Report(null, '/x'),
            'h1 throw ?token=secret' => $Report(null, ''),
            'h2 fail ?token=secret' => $Report('Received', ''),
            'h2 throw ?token=secret' => $Report(null, ''),
         ];

         yield assert(
            assertion: $contexts === ['Encoder_' => $context, 'Encoder_Testing' => $context],
            description: "A refusal is reported in the Received phase, and a routing failure by the Catcher, with the path only — the query string never reaches a reporter, not even from a request-target that starts with '?': {$evidence}",
         );

         // # Multipart upload — refused with its temp file on disk and its
         //   bytes on the cross-worker counter. A forked child binds a private
         //   controller: the runner's binding (and its counter) stay untouched.
         @mkdir($directory, 0755, true);
         @chmod($directory, 0755);
         $content = str_repeat('U168', 1024);
         $bytes = strlen($content);
         $boundary = '----u168';
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"upload\"; filename=\"u168.bin\"\r\n"
            . "Content-Type: application/octet-stream\r\n"
            . "\r\n"
            . "{$content}\r\n"
            . "--{$boundary}--\r\n";
         $length = strlen($body);
         $upload = "POST /gate?token=secret HTTP/1.1\r\nHost: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: {$length}\r\n"
            . "\r\n"
            . $body;

         $Pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         if ($Pair === false) {
            throw new RuntimeException('U168 could not open the child report pair.');
         }
         [$Reader, $Writer] = $Pair;
         $PID = pcntl_fork();
         if ($PID === 0) {
            // # Child — every upload leg on a private copy of the statics
            $report = [];
            try {
               fclose($Reader);
               // ! Never outlive the run: an alarm ends a refusal that hangs,
               //   and the child holds none of the runner's output pipes (its
               //   descriptors 1 and 2 reopen on /dev/null, so no file it
               //   opens later can take them)
               pcntl_signal(SIGALRM, SIG_DFL);
               pcntl_alarm(20);
               fclose(STDOUT);
               $Output = fopen('/dev/null', 'w');
               fclose(STDERR);
               $Errors = fopen('/dev/null', 'w');
               // ! Each upload the gate sees is reported at once
               $Spill = static function (string $temporary) use ($Writer): void {
                  @fwrite($Writer, "upload {$temporary}\n");
               };
               // ! The worker-global cell the body decoders read
               $WPI = WPI;
               if (isset($WPI->Server) === false) {
                  $WPI->Server = new ReflectionClass(HTTP_Server_CLI::class)->newInstanceWithoutConstructor();
               }
               $report['init'] = Downloads::init(path: $controller);
               foreach ($encoders as $class) {
                  TCP_Server_CLI::$Encoder = new $class;
                  $short = substr($class, strrpos($class, '\\') + 1);
                  foreach (['fail', 'refuse'] as $leg) {
                     $Prepare($leg);
                     $peek = Downloads::peek();
                     $tracked = $Tracked->getValue();
                     [$Server, $Client, $Connection] = $Open();

                     fwrite($Client, $upload);
                     $escaped = $Read($Connection, $Server);
                     $wire = $Drain($Client);

                     $temporary = $seen['temporary'] ?? '';
                     $report['temporaries'][] = $temporary;
                     clearstatcache();
                     $report['rows']["{$short} {$leg}"] = [
                        'escaped' => $escaped,
                        'status' => strstr($wire, "\r\n", true) ?: $wire,
                        'handled' => $handled,
                        'closed' => $Connection->status > Connections::STATUS_ESTABLISHED,
                        'during' => [
                           'exists' => $seen['exists'] ?? null,
                           'size' => $seen['size'] ?? null,
                           'charged' => isset($seen['peek']) ? $seen['peek'] - $peek : null,
                           'tracked' => isset($seen['tracked'])
                              ? array_keys(array_diff_key($seen['tracked'], $tracked)) === [$temporary]
                              : null,
                        ],
                        'after' => [
                           'exists' => $temporary !== '' && is_file($temporary),
                           'charged' => Downloads::peek() - $peek,
                           'tracked' => count(array_diff_key($Tracked->getValue(), $tracked)),
                        ],
                     ];
                  }
               }
            }
            catch (Throwable $Throwable) {
               $report['error'] = "{$Throwable->getMessage()} @ {$Throwable->getFile()}:{$Throwable->getLine()}";
            }
            finally {
               @fwrite($Writer, 'report ' . (json_encode($report) ?: '{}') . "\n");
               @fclose($Writer);
               // ! Hard exit — no shutdown handlers, no inherited output flush
               posix_kill(posix_getpid(), SIGKILL);
            }
         }

         // # Parent
         fclose($Writer);
         stream_set_blocking($Reader, false);
         $lines = '';
         $deadline = time() + 30;
         while (time() < $deadline) {
            $chunk = fread($Reader, 65_536);
            if (is_string($chunk) && $chunk !== '') {
               $lines .= $chunk;
            }
            if (feof($Reader)) {
               break;
            }
            usleep(10_000);
         }
         fclose($Reader);
         if ($PID > 0) {
            // ? A child that outlived the deadline never holds the run
            if (pcntl_waitpid($PID, $status, WNOHANG) === 0) {
               posix_kill($PID, SIGKILL);
               pcntl_waitpid($PID, $status);
            }
         }
         // ! The child's lines: each upload the gate saw, then its report
         $report = [];
         $uploads = [];
         foreach (explode("\n", $lines) as $line) {
            if (str_starts_with($line, 'upload ')) {
               $uploads[] = substr($line, 7);
            }
            else if (str_starts_with($line, 'report ')) {
               $decoded = json_decode(substr($line, 7), true);
               $report = is_array($decoded) ? $decoded : [];
            }
         }
         $Uploaded = static fn (mixed $temporary): bool => is_string($temporary)
            && str_starts_with($temporary, BOOTGLY_UPLOADS_DIR);
         $reported = array_filter(
            is_array($report['temporaries'] ?? null) ? $report['temporaries'] : [],
            $Uploaded,
         );
         // ! Everything the child reported is swept, whatever it ended with
         $temporaries = array_unique([...$reported, ...array_filter($uploads, $Uploaded)]);

         $evidence = json_encode(['report' => $report, 'uploads' => $uploads]);
         $during = ['exists' => true, 'size' => $bytes, 'charged' => $bytes, 'tracked' => true];
         $after = ['exists' => false, 'charged' => 0, 'tracked' => 0];
         $uploadFailed = ['escaped' => null, 'status' => 'HTTP/1.1 500 Internal Server Error', 'handled' => 0, 'closed' => true, 'during' => $during, 'after' => $after];
         $uploadRefused = array_replace($uploadFailed, ['status' => 'HTTP/1.1 503 Service Unavailable']);

         yield assert(
            assertion: ($report['init'] ?? null) === true
               && count($reported) === 4
               && count($uploads) === 4
               && ($report['rows'] ?? null) === [
                  'Encoder_ fail' => $uploadFailed,
                  'Encoder_ refuse' => $uploadRefused,
                  'Encoder_Testing fail' => $uploadFailed,
                  'Encoder_Testing refuse' => $uploadRefused,
               ],
            description: "A refused multipart upload is answered 500/503 and closed, and takes its upload with it — the temp file the gate saw is gone, the Downloads counter it was charged to is back where it was and the tracking map gained nothing: {$evidence}",
         );

         // # HTTP/2 — stream 1 is in flight (its body pending) when the gate
         //   refuses stream 3 (a POST with a body and a bearer credential);
         //   stream 5, complete, follows it in the same read; the body then
         //   completes stream 1
         $rows = [];
         foreach ($encoders as $class) {
            TCP_Server_CLI::$Encoder = new $class;
            $short = substr($class, strrpos($class, '\\') + 1);
            foreach (['fail' => '500', 'refuse' => '503'] as $leg => $code) {
               $Prepare($leg);
               [$Server, $Client, $Connection] = $Open();

               fwrite($Client, HTTP2::PREFACE
                  . Frame::pack(HTTP2::FRAME_SETTINGS, 0, 0, '')
                  . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 1, HPACK::encode([
                     [':method', 'POST'],
                     [':scheme', 'http'],
                     [':path', '/sibling'],
                     [':authority', 'localhost'],
                  ]))
                  . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS, 3, HPACK::encode([
                     [':method', 'POST'],
                     [':scheme', 'http'],
                     [':path', '/gate?token=secret'],
                     [':authority', 'localhost'],
                     ['content-type', 'application/json'],
                     ['authorization', 'Bearer u168-token'],
                  ]))
                  . Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 3, '{"u168":"secret body"}')
                  . Frame::pack(HTTP2::FRAME_HEADERS, HTTP2::FLAG_END_HEADERS | HTTP2::FLAG_END_STREAM, 5, HPACK::encode([
                     [':method', 'GET'],
                     [':scheme', 'http'],
                     [':path', '/next'],
                     [':authority', 'localhost'],
                  ])));
               $escaped = $Read($Connection, $Server);
               $refusal = $Drain($Client);
               $open = $Connection->status === Connections::STATUS_ESTABLISHED;
               $refusals = array_column($reports, 'class');
               // ! The refused stream is released: neither tracked nor counted
               //   open — only the in-flight sibling stays
               $H2 = $Connection->decoded;
               $released = $H2 instanceof Decoder_HTTP2
                  ? ['tracked' => isset($H2->Streams[3]), 'streams' => array_keys($H2->Streams), 'opened' => $H2->opened]
                  : null;
               // ! The refused Request keeps nothing the gate read
               $scrubbed = $Refused instanceof Request
                  ? [
                     'raw' => $Refused->Body->raw,
                     'input' => $Refused->Body->input,
                     'URI' => $Refused->URI,
                     'headers' => $Refused->headers,
                     'token' => $Refused->token,
                  ]
                  : null;
               $read = $gated;

               // @ The sibling's body arrives after the refusal (a regression
               //   closed the connection: the write may find it gone)
               @fwrite($Client, Frame::pack(HTTP2::FRAME_DATA, HTTP2::FLAG_END_STREAM, 1, 'x'));
               $sibling = $Read($Connection, $Server);
               $wire = $refusal . $Drain($Client);

               // @ Every stream-level answer and connection-level error, in order
               $HPACK = new HPACK(4096);
               $frames = [];
               $goaways = 0;
               $offset = 0;
               // ! Whether the refusal left with the read that refused it — not
               //   held until the client's next frame (a spent send window)
               $prompt = null;
               // ! The stream frames that left with that read
               $first = [];
               while (strlen($wire) - $offset >= 9) {
                  $size = (ord($wire[$offset]) << 16) | (ord($wire[$offset + 1]) << 8) | ord($wire[$offset + 2]);
                  if (strlen($wire) - $offset < 9 + $size) {
                     break;
                  }
                  $type = ord($wire[$offset + 3]);
                  $flags = ord($wire[$offset + 4]);
                  $stream = ((ord($wire[$offset + 5]) & 0x7f) << 24) | (ord($wire[$offset + 6]) << 16)
                     | (ord($wire[$offset + 7]) << 8) | ord($wire[$offset + 8]);
                  $payload = substr($wire, $offset + 9, $size);
                  if ($type === HTTP2::FRAME_HEADERS && $stream === 3) {
                     $prompt = $offset < strlen($refusal);
                  }
                  if ($stream !== 0 && $offset < strlen($refusal)) {
                     $first[] = "{$stream}:{$type}";
                  }
                  $offset += 9 + $size;

                  if ($type === HTTP2::FRAME_GOAWAY) {
                     $goaways++;
                  }
                  else if ($type === HTTP2::FRAME_HEADERS) {
                     $status = null;
                     foreach ($HPACK->decode($payload, PHP_INT_MAX) ?? [] as [$name, $value]) {
                        if ($name === ':status') {
                           $status = $value;
                        }
                     }
                     $frames[] = [
                        $stream,
                        'HEADERS',
                        $status,
                        ($flags & HTTP2::FLAG_END_STREAM) !== 0,
                        ($flags & HTTP2::FLAG_END_HEADERS) !== 0,
                     ];
                  }
                  else if ($type === HTTP2::FRAME_DATA) {
                     $frames[] = [$stream, 'DATA', $payload, ($flags & HTTP2::FLAG_END_STREAM) !== 0];
                  }
                  else if ($type === HTTP2::FRAME_RST_STREAM) {
                     $frames[] = [$stream, 'RST_STREAM', null, false];
                  }
               }

               $rows["{$short} {$leg}"] = [
                  'escaped' => [$escaped, $sibling],
                  'raw' => str_contains($wire, 'HTTP/1.1'),
                  'open' => [$open, $Connection->status === Connections::STATUS_ESTABLISHED],
                  'goaways' => $goaways,
                  'trailing' => strlen($wire) - $offset,
                  'frames' => $frames,
                  'prompt' => $prompt,
                  'first' => $first,
                  'released' => $released,
                  'read' => $read,
                  'scrubbed' => $scrubbed,
                  'reports' => $refusals,
                  'handled' => $handled,
               ];
            }
         }
         $evidence = json_encode($rows);
         $Expected = static fn (string $code, array $reports): array => [
            'escaped' => [null, null],
            'raw' => false,
            'open' => [true, true],
            'goaways' => 0,
            'trailing' => 0,
            'frames' => [
               [3, 'HEADERS', $code, true, true],
               [5, 'HEADERS', '200', false, true],
               [5, 'DATA', 'answered', true],
               [1, 'HEADERS', '200', false, true],
               [1, 'DATA', 'answered', true],
            ],
            'prompt' => true,
            // ! The refusal and the stream behind it, in one read
            'first' => [
               '3:' . HTTP2::FRAME_HEADERS,
               '5:' . HTTP2::FRAME_HEADERS,
               '5:' . HTTP2::FRAME_DATA,
            ],
            'released' => ['tracked' => false, 'streams' => [1], 'opened' => 1],
            'read' => [
               'raw' => '{"u168":"secret body"}',
               'input' => '{"u168":"secret body"}',
               'URI' => '/gate?token=secret',
               'token' => 'u168-token',
            ],
            'scrubbed' => ['raw' => '', 'input' => null, 'URI' => '', 'headers' => [], 'token' => ''],
            'reports' => $reports,
            'handled' => 2,
         ];

         yield assert(
            assertion: $rows === [
               'Encoder_ fail' => $Expected('500', [RuntimeException::class]),
               'Encoder_ refuse' => $Expected('503', []),
               'Encoder_Testing fail' => $Expected('500', [RuntimeException::class]),
               'Encoder_Testing refuse' => $Expected('503', []),
            ],
            description: "A refused HTTP/2 stream is denied alone — its status in a HEADERS frame with END_STREAM and END_HEADERS, no raw HTTP/1.1 bytes, no GOAWAY, written with the read that refused it — released and emptied of its body, input, head and credential, and the connection lives: the stream behind it in the same read is answered with that read, the sibling in flight afterwards: {$evidence}",
         );
      }
      finally {
         foreach ($Connections as $Connection) {
            try {
               $Connection->close();
            }
            catch (Throwable) {
               // Teardown only.
            }
         }
         foreach ($Sockets as $Socket) {
            if (is_resource($Socket)) {
               fclose($Socket);
            }
         }
         // ! What a regression would leave behind: the uploads, this run's
         //   directory and the directories a dead run left (never one a
         //   concurrent run still uses)
         foreach ($temporaries as $temporary) {
            @unlink($temporary);
         }
         $directories = glob(BOOTGLY_STORAGE_DIR . 'tests/u168-*', GLOB_ONLYDIR) ?: [];
         foreach ($directories as $stale) {
            $stale = "{$stale}/";
            if ($stale !== $directory && @filemtime($stale) > time() - 600) {
               continue;
            }
            foreach (scandir($stale) ?: [] as $entry) {
               if (is_file("{$stale}{$entry}")) {
                  @unlink("{$stale}{$entry}");
               }
            }
            @rmdir($stale);
         }

         Emitter::$Instance = $OldEmitter;
         Throwables::$reporters = $OldReporters;
         Catcher::$Environment = $OldCatcher;
         SAPI::$Environment = $OldEnvironment;
         SAPI::$testIndexHeader = $oldIndex;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         if ($OldHandler !== null) {
            SAPI::$Handler = $OldHandler;
         }
         if ($OldMiddlewares !== null) {
            SAPI::$Middlewares = $OldMiddlewares;
         }
         TCP_Server_CLI::$Decoder = $OldDecoder;
         TCP_Server_CLI::$Encoder = $OldEncoder;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         if ($oldContext !== null) {
            TCP_Server_CLI::$context = $oldContext;
         }
         if ($oldAlarm > 0) {
            pcntl_alarm($oldAlarm);
         }
         if ($OldRequest !== null) {
            HTTP_Server_CLI::$Request = $OldRequest;
         }
         if ($OldResponse !== null) {
            HTTP_Server_CLI::$Response = $OldResponse;
         }
         if ($OldRouter !== null) {
            HTTP_Server_CLI::$Router = $OldRouter;
         }
      }
   })
);
