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


if (! class_exists('M2FileBackpressureStream', false)) {
   class M2FileBackpressureStream
   {
      public static int $calls = 0;
      public static int $zeroAt = 0;
      public static string $written = '';

      public mixed $context;

      public static function reset (int $zeroAt): void
      {
         self::$calls = 0;
         self::$zeroAt = $zeroAt;
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
         self::$calls++;

         if (self::$zeroAt === self::$calls) {
            return 0;
         }

         $length = strlen($data);
         self::$written .= substr($data, 0, $length);

         return $length;
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

if (! class_exists('M2FileBackpressureConnection', false)) {
   class M2FileBackpressureConnection extends Connection
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
 * M2 PoC — file-response streaming must preserve disk-backed state when a
 * nonblocking socket stalls between 1 MiB chunks.
 *
 * The deterministic stream accepts the HTTP head, returns zero on the first
 * file write, then accepts all later writes. The production transport must
 * resume the complete 2 MiB body before a second keep-alive response. A
 * no-stall control proves the fixture and upload metadata are otherwise valid.
 */
$probe = [
   'error' => '',
   'fast' => [],
   'stalled' => [],
];

return new Test(
   description: 'HTTP/1 file uploads must retain disk state across write backpressure',
   Separator: new Separator(line: true),

   request: static function () use (&$probe): string {
      $scheme = 'bootgly-m2-file-backpressure';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, M2FileBackpressureStream::class);
      }

      $chunkSize = 1 * 1024 * 1024;
      $bodyLength = 2 * $chunkSize;
      $prefix = 'PREFIX';
      $file = BOOTGLY_PROJECT->path . 'statics/screenshot.gif';
      $body = file_get_contents($file, false, null, 0, $bodyLength);
      if (! is_string($body) || strlen($body) !== $bodyLength) {
         $probe['error'] = 'Could not read the tracked 2 MiB upload fixture.';

         return "GET /m2-file-backpressure-harness HTTP/1.1\r\n"
            . "Host: localhost\r\nConnection: close\r\n\r\n";
      }

      $Run = static function (int $zeroAt) use (
         $scheme,
         $body,
         $bodyLength,
         $chunkSize,
         $prefix
      ): array {
         M2FileBackpressureStream::reset($zeroAt);

         $Socket = fopen($scheme . '://probe', 'w+');
         if (! is_resource($Socket)) {
            throw new RuntimeException('Could not allocate the M2 transport fixture.');
         }

         $WPI = WPI;
         $OldRequest = $WPI->Request;
         try {
            $Connection = new M2FileBackpressureConnection($Socket);
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

            // ! Build both wires through the public response API and the real
            //   HTTP/1 encoder. This supplies Content-Length and the transport
            //   upload queue exactly as an application route would.
            $Request = new Request;
            $Request->method = 'GET';
            $Request->protocol = 'HTTP/1.1';
            $WPI->Request = $Request;

            $FileResponse = new Response;
            $FileResponse->reset($Package, $Request);
            $FileResponse(body: $prefix);
            $FileResponse->upload(
               'statics/screenshot.gif',
               length: $bodyLength,
               close: false
            );
            $headerLength = null;
            $header = $FileResponse->encode($Package, $headerLength);
            $queuedBeforeWrite = count($Package->uploading);

            $NextResponse = new Response;
            $NextResponse->reset($Package, $Request);
            $NextResponse(body: 'NEXT');
            $nextLength = null;
            $next = $NextResponse->encode($Package, $nextLength);

            if (
               preg_match(
                  '/(?:^|\r\n)Content-Length:\s*([0-9]+)\r\n/i',
                  $header,
                  $matches
               ) !== 1
            ) {
               throw new RuntimeException('Production encoder omitted the file Content-Length.');
            }
            $advertisedBody = (int) $matches[1];

            $expected = $header . $body . $next;
            $vulnerable = $header . substr($body, 0, $chunkSize) . $next;

            $firstResult = $Package->writing(
               $Socket,
               length: $headerLength,
               buffer: $header
            );
            $pendingAfterStall = strlen($Package->pendingBuffer);
            $queuedAfterStall = count($Package->uploading);
            $closedAfterStall = $Connection->closed;

            // ! Model the actual keep-alive pipeline: the next response reaches
            //   writing() while the file slice is still pending. A partial fix
            //   that retains the disk cursor but appends NEXT before resuming
            //   later file chunks must still fail this regression.
            $nextResult = $Package->writing($Socket, buffer: $next);

            $resumeRounds = 0;
            while (
               ($Package->pendingBuffer !== '' || $Package->uploading !== [])
               && $resumeRounds < 16
               && ! $Connection->closed
            ) {
               $resumeRounds++;
               $Package->writing($Socket, buffer: '');
            }

            $wire = M2FileBackpressureStream::$written;

            return [
               'first_result' => $firstResult,
               'next_result' => $nextResult,
               'calls' => M2FileBackpressureStream::$calls,
               'pending_after_stall' => $pendingAfterStall,
               'queued_after_stall' => $queuedAfterStall,
               'pending_final' => strlen($Package->pendingBuffer),
               'queued_final' => count($Package->uploading),
               'closed_after_stall' => $closedAfterStall,
               'closed_final' => $Connection->closed,
               'resume_rounds' => $resumeRounds,
               'queued_before_write' => $queuedBeforeWrite,
               'advertised_body' => $advertisedBody,
               'encoded_prefix_length' => $headerLength,
               'actual_prefix_length' => strlen($header),
               'body_hash' => hash('sha256', $body),
               'expected_length' => strlen($expected),
               'expected_hash' => hash('sha256', $expected),
               'vulnerable_length' => strlen($vulnerable),
               'vulnerable_hash' => hash('sha256', $vulnerable),
               'wire_length' => strlen($wire),
               'wire_hash' => hash('sha256', $wire),
            ];
         }
         finally {
            $WPI->Request = $OldRequest;

            if (is_resource($Socket)) {
               @fclose($Socket);
            }
         }
      };

      $OldEvent = TCPServer::$Event;
      // ! The deterministic backpressure wrapper is not a selectable OS
      //   socket. Isolate this upload-state regression from descriptor
      //   admission while preserving the historical writable registration.
      TCPServer::$Event = new class extends Select {
         public function __construct () {}

         public function add ($Socket, int $flag, mixed $payload): bool
         {
            return true;
         }
      };

      try {
         $fast = $Run(0);
         $stalled = $Run(2);

         $probe = [
            'error' => '',
            'body_length' => $bodyLength,
            'prefix_length' => strlen($prefix),
            'chunk_size' => $chunkSize,
            'fast' => $fast,
            'stalled' => $stalled,
         ];
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }
      finally {
         TCPServer::$Event = $OldEvent;
      }

      return "GET /m2-file-backpressure-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: static function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route(
         '/m2-file-backpressure-harness',
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
         Vars::$labels = ['M2 PoC state'];
         dump(json_encode($probe));

         return 'M2 PoC harness failed before reaching the production upload path: '
            . $probe['error'];
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         return 'M2 PoC harness request did not reach its control route.';
      }

      $bodyLength = $probe['body_length'];
      $representationLength = $bodyLength + $probe['prefix_length'];
      $chunkSize = $probe['chunk_size'];
      $fast = $probe['fast'];
      $stalled = $probe['stalled'];

      if (
         $fast['first_result'] !== true
         || $fast['next_result'] !== true
         || $fast['queued_before_write'] !== 1
         || $fast['advertised_body'] !== $representationLength
         || $fast['encoded_prefix_length'] !== $fast['actual_prefix_length']
         || $fast['wire_length'] !== $fast['expected_length']
         || $fast['wire_hash'] !== $fast['expected_hash']
         || $fast['pending_final'] !== 0
         || $fast['queued_final'] !== 0
         || $fast['closed_final'] !== false
      ) {
         Vars::$labels = ['M2 PoC state'];
         dump(json_encode($probe));

         return 'M2 PoC control failed: the no-stall path did not emit the complete '
            . 'file and following response.';
      }

      if (
         $stalled['first_result'] !== true
         || $stalled['next_result'] !== true
         || $stalled['queued_before_write'] !== 1
         || $stalled['advertised_body'] !== $representationLength
         || $stalled['encoded_prefix_length'] !== $stalled['actual_prefix_length']
         || $stalled['closed_after_stall'] !== false
         || $stalled['pending_final'] !== 0
         || $stalled['queued_final'] !== 0
      ) {
         Vars::$labels = ['M2 PoC state'];
         dump(json_encode($probe));

         return 'M2 PoC was inconclusive: the deterministic stall did not reach '
            . 'a cleanly drained transport state.';
      }

      if (
         $stalled['wire_length'] === $stalled['expected_length']
         && $stalled['wire_hash'] === $stalled['expected_hash']
      ) {
         return true;
      }

      $missing = $stalled['expected_length'] - $stalled['wire_length'];
      $vulnerable = $stalled['pending_after_stall'] === $chunkSize
         && $stalled['queued_after_stall'] === 0
         && $missing === $chunkSize
         && $stalled['wire_length'] === $stalled['vulnerable_length']
         && $stalled['wire_hash'] === $stalled['vulnerable_hash'];

      Vars::$labels = ['M2 PoC state'];
      dump(json_encode($probe));

      if ($vulnerable) {
         return 'CONFIRMED M2: file-response backpressure retained only the '
            . 'in-memory 1 MiB slice, discarded the remaining disk-backed '
            . '1 MiB, and emitted the next HTTP response before satisfying '
            . 'the first response Content-Length '
            . "(advertised_body={$stalled['advertised_body']}, "
            . "pending_after_stall={$stalled['pending_after_stall']}, "
            . "queued_after_stall={$stalled['queued_after_stall']}, "
            . "wire_shortfall={$missing}, next_marker=true, "
            . "fixture_sha256={$stalled['body_hash']}).";
      }

      return 'M2 PoC produced an unexpected wire mismatch; review the captured '
         . 'lengths and hashes before assigning finding status.';
   }
);
