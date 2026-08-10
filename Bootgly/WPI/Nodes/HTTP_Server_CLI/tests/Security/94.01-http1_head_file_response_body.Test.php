<?php

use const Bootgly\WPI;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Separator;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as TCPServer;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


if (! class_exists('M2HeadFileStream', false)) {
   class M2HeadFileStream
   {
      public static string $written = '';

      public mixed $context;

      public static function reset (): void
      {
         self::$written = '';
      }

      public function stream_open (
         string $path,
         string $mode,
         int $options,
         null|string &$opened_path
      ): bool {
         return true;
      }

      public function stream_write (string $data): int
      {
         self::$written .= $data;

         return strlen($data);
      }

      public function stream_eof (): bool
      {
         return false;
      }

      /** @return array<string,mixed> */
      public function stream_stat (): array
      {
         return [];
      }
   }
}

if (! class_exists('M2HeadFileConnection', false)) {
   class M2HeadFileConnection extends Connection
   {
      public bool $closed = false;

      /** @param resource $Socket */
      public function __construct (mixed &$Socket)
      {
         $this->Socket = $Socket;
         $this->timers = [];
         $this->expiration = 15;
         $this->ip = '127.0.0.1';
         $this->port = 12345;
         $this->encrypted = false;
         $this->handshaking = false;
         $this->handshakeTimer = 0;
         $this->status = Connections::STATUS_ESTABLISHED;
         $this->started = time();
         $this->used = time();
         $this->writes = 0;
      }

      public function close (): true
      {
         $this->closed = true;
         $this->status = Connections::STATUS_CLOSED;

         if (is_resource($this->Socket)) {
            @fclose($this->Socket);
         }

         return true;
      }
   }
}

/**
 * PoC — a HEAD request for a file response must carry the Content-Length a GET
 * would carry, and no body at all (RFC 9110 §9.3.2).
 *
 * `Raw::encode()` suppresses the buffered body for HEAD, but that only reaches
 * `$Body->raw`. A file response's bytes are not there: they are queued for the
 * transport, and `Raw::encode()` installs that queue with no method guard. So
 * HEAD answers with a full head — Content-Length included, taken from
 * `measure()` — and then streams the whole file behind it. HTTP/2 gets this
 * right: `Encoder_HTTP2::frame()` computes the size first, then clears both the
 * body and the DATA chunks for HEAD.
 *
 * The inline leg measures the emitted bytes directly. The wire leg is what
 * makes the severity concrete: with `HEAD` and `GET` pipelined on one
 * connection, a client that honours the advertised Content-Length consumes the
 * file bytes as HEAD's body and is then off by one response for the rest of the
 * connection — reading the GET's head as the GET's body, and so on. When the
 * served tree is writable by the requester, the "file" can be a forged HTTP
 * response and the desync becomes a response-smuggling primitive.
 *
 * Controls: the same route over GET must still stream the complete file, and
 * a HEAD for a non-file (buffered) response must keep behaving as it does now.
 */
$probe = [
   'error' => '',
   'inline' => [],
   'wire' => [],
];

return new Test(
   description: 'HTTP/1 HEAD must not emit a file-response body',
   Separator: new Separator(line: true),

   request: static function (string $hostPort, int $testIndex) use (&$probe): string {
      $scheme = 'bootgly-m2-head-file';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, M2HeadFileStream::class);
      }

      $token = bin2hex(random_bytes(8));
      $statics = BOOTGLY_PROJECT->path . 'statics/';
      $name = "m2-head-{$token}.bin";
      $target = $statics . $name;
      $relative = "statics/{$name}";
      $content = str_repeat('FILEBODY', 512);

      $OldEvent = TCPServer::$Event;
      $WPI = WPI;
      $OldRequest = $WPI->Request;

      TCPServer::$Event = new class extends Select {
         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }
      };

      try {
         @unlink($target);
         if (@file_put_contents($target, $content, LOCK_EX) !== strlen($content)) {
            throw new RuntimeException('Could not create the HEAD fixture.');
         }
         clearstatcache(true, $target);

         // ! Drive one complete response cycle through the production encoder
         //   and transport for a given method, and return exactly what reached
         //   the socket.
         $Run = static function (string $method) use ($scheme, $relative): array {
            M2HeadFileStream::reset();

            $Socket = fopen($scheme . '://probe', 'w+');
            if (! is_resource($Socket)) {
               throw new RuntimeException('Could not allocate the M2 transport fixture.');
            }

            $WPI = WPI;
            $Old = $WPI->Request;
            try {
               $Connection = new M2HeadFileConnection($Socket);
               $Package = new class($Connection) extends TCPPackages {
                  public function __construct (Connection $Connection)
                  {
                     $this->Connection = $Connection;

                     $this->cache = true;
                     $this->changed = true;
                     $this->input = '';
                     $this->output = '';
                     $this->callbacks = [&$this->input];
                     $this->expired = false;
                     $this->consumed = 0;
                     $this->rejected = false;

                     $this->downloading = [];
                     $this->uploading = [];
                     $this->closeAfterWrite = false;
                  }
               };

               $Request = new Request;
               $Request->method = $method;
               $Request->protocol = 'HTTP/1.1';
               $WPI->Request = $Request;

               $Response = new Response;
               $Response->reset($Package, $Request);
               $Response->upload($relative, close: false);

               $length = null;
               $head = $Response->encode($Package, $length);
               $queued = count($Package->uploading) + count($Package->stagedUploading);

               $Package->writing($Socket, length: $length, buffer: $head);
               $rounds = 0;
               while (
                  ($Package->pendingBuffer !== '' || $Package->uploading !== [])
                  && $rounds < 16
                  && ! $Connection->closed
               ) {
                  $rounds++;
                  $Package->writing($Socket, buffer: '');
               }

               $advertised = null;
               if (preg_match('/\r\nContent-Length:\s*([0-9]+)\r\n/i', $head, $matches) === 1) {
                  $advertised = (int) $matches[1];
               }

               return [
                  'head' => $head,
                  'advertised' => $advertised,
                  'queued' => $queued,
                  'wire' => M2HeadFileStream::$written,
                  'body' => substr(M2HeadFileStream::$written, strlen($head)),
                  'closed' => $Connection->closed,
               ];
            }
            finally {
               $WPI->Request = $Old;
               if (is_resource($Socket)) {
                  @fclose($Socket);
               }
            }
         };

         $probe['inline'] = [
            'size' => strlen($content),
            'GET' => $Run('GET'),
            'HEAD' => $Run('HEAD'),
         ];

         // # Wire leg — HEAD and GET pipelined on one live connection. A client
         //   that trusts the advertised Content-Length reads the file bytes as
         //   HEAD's body and is then permanently off by one response.
         $Socket = @stream_socket_client(
            "tcp://{$hostPort}",
            $errorNumber,
            $errorMessage,
            timeout: 3,
         );
         if (! is_resource($Socket)) {
            throw new RuntimeException(
               "Could not open the M2 wire socket: {$errorNumber} {$errorMessage}"
            );
         }
         try {
            stream_set_blocking($Socket, true);
            stream_set_timeout($Socket, 3);
            $Head = static function (string $method) use ($testIndex, $token): string {
               return "{$method} /m2-head-file HTTP/1.1\r\n"
                  . "X-Bootgly-Test: {$testIndex}\r\n"
                  . "X-M2-Token: {$token}\r\n"
                  . "Host: localhost\r\n"
                  . "Connection: keep-alive\r\n\r\n";
            };
            @fwrite($Socket, $Head('HEAD') . $Head('GET'));

            stream_set_blocking($Socket, false);
            $wire = '';
            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
               $chunk = @fread($Socket, 65535);
               if ($chunk !== false && $chunk !== '') {
                  $wire .= $chunk;
                  // ? Enough to decide: the HEAD head, plus whatever follows it.
                  if (strlen($wire) > strlen($content)) {
                     break;
                  }
                  continue;
               }
               if (@feof($Socket)) {
                  break;
               }
               usleep(5_000);
            }
         }
         finally {
            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }

         $separator = strpos($wire, "\r\n\r\n");
         $first = $separator === false ? '' : substr($wire, 0, $separator);
         $after = $separator === false ? '' : substr($wire, $separator + 4);
         $probe['wire'] = [
            'first_head' => $first,
            'advertised' => preg_match('/\r\nContent-Length:\s*([0-9]+)/i', $first, $m) === 1
               ? (int) $m[1]
               : null,
            // ? What a compliant client would attribute to the HEAD response.
            'claimed_body' => substr($after, 0, strlen($content)),
            'after_len' => strlen($after),
            'starts_with_file' => str_starts_with($after, substr($content, 0, 64)),
            'starts_with_status' => str_starts_with($after, 'HTTP/1.1'),
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         $WPI->Request = $OldRequest;
         TCPServer::$Event = $OldEvent;
         @unlink($target);
      }

      return "GET /m2-head-harness HTTP/1.1\r\n"
         . "X-Bootgly-Test: {$testIndex}\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      $Relative = static function (Request $Request): null|string {
         $token = $Request->Header->get('X-M2-Token') ?? '';
         if (preg_match('/^[a-f0-9]{16}$/D', $token) !== 1) {
            return null;
         }

         return "statics/m2-head-{$token}.bin";
      };

      // ! Registered without a method list on purpose — that is the shape an
      //   application writes when it does not think about HEAD, and it is the
      //   shape the finding is about.
      yield $Router->route(
         '/m2-head-file',
         static function (Request $Request, Response $Response) use ($Relative): Response {
            $relative = $Relative($Request);
            if ($relative === null) {
               return $Response->code(400);
            }

            return $Response->upload($relative, close: false);
         }
      );

      yield $Router->route(
         '/m2-head-harness',
         static function (Request $Request, Response $Response): Response {
            return $Response(code: 200, body: 'HARNESS-OK');
         },
         GET
      );

      yield $Router->route('/*', static function (Request $Request, Response $Response): Response {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: static function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['M2 HEAD PoC state'];
         dump(json_encode(['error' => $probe['error']], JSON_UNESCAPED_SLASHES));

         return 'M2 HEAD PoC harness failed before reaching a verdict: ' . $probe['error'];
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M2 HEAD PoC harness request did not reach its control route.';
      }

      $size = $probe['inline']['size'];
      $GET = $probe['inline']['GET'];
      $HEAD = $probe['inline']['HEAD'];

      $Evidence = static function (string $label, array $data): void {
         Vars::$labels = [$label];
         dump(json_encode($data, JSON_UNESCAPED_SLASHES));
      };

      // @ Control — GET must still stream the complete file. A fix that simply
      //   stops queueing files would pass every HEAD assertion below.
      if (
         $GET['advertised'] !== $size
         || $GET['queued'] !== 1
         || $GET['body'] !== str_repeat('FILEBODY', 512)
         || $GET['closed'] !== false
      ) {
         $Evidence('M2 HEAD PoC control', [
            'advertised' => $GET['advertised'],
            'expected' => $size,
            'queued' => $GET['queued'],
            'body_len' => strlen($GET['body']),
            'closed' => $GET['closed'],
         ]);

         return 'M2 HEAD PoC control failed: GET did not stream the complete file, so the '
            . 'HEAD assertions cannot be trusted.';
      }

      // @ HEAD must advertise the same Content-Length a GET would (RFC 9110
      //   §9.3.2 keeps the representation metadata) ...
      if ($HEAD['advertised'] !== $size) {
         $Evidence('M2 HEAD PoC', ['advertised' => $HEAD['advertised'], 'expected' => $size]);

         return 'M2 HEAD regression: HEAD no longer advertises the Content-Length a GET '
            . "would ({$HEAD['advertised']} instead of {$size}). The fix must drop the body, "
            . 'not the representation metadata.';
      }

      $W = $probe['wire'];
      $findings = [];
      if ($HEAD['body'] !== '') {
         $findings[] = 'the transport emitted ' . strlen($HEAD['body'])
            . ' body bytes after the HEAD head (queued=' . $HEAD['queued'] . ')';
      }
      if (($W['starts_with_file'] ?? false) === true) {
         $findings[] = 'on a live worker, the bytes following the HEAD head were the FILE, '
            . 'so a pipelined client attributes them to HEAD and is then off by one response '
            . 'for the rest of the connection';
      }

      if ($findings !== []) {
         $Evidence('M2 HEAD PoC evidence', [
            'inline_head_body_len' => strlen($HEAD['body']),
            'inline_queued' => $HEAD['queued'],
            'wire_advertised' => $W['advertised'] ?? null,
            'wire_after_len' => $W['after_len'] ?? null,
            'wire_starts_with_file' => $W['starts_with_file'] ?? null,
            'wire_starts_with_status' => $W['starts_with_status'] ?? null,
         ]);

         return 'CONFIRMED: a HEAD request for a file response emits a body — '
            . implode('; ', $findings) . '.';
      }

      // @ Secure: no body inline, and on the wire the next bytes are the GET's
      //   own status line rather than file content.
      if (($W['starts_with_status'] ?? false) !== true) {
         $Evidence('M2 HEAD PoC wire', $W);

         return 'M2 HEAD PoC is inconclusive: the inline leg is clean, but the live worker '
            . 'did not follow the HEAD head with the pipelined GET response, so the '
            . 'desync claim was not exercised.';
      }

      return true;
   }
);
