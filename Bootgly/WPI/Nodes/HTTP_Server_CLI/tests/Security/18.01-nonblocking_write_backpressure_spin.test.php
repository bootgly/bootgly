<?php

use function fclose;
use function fopen;
use function fwrite;
use function in_array;
use function is_resource;
use function json_encode;
use function rewind;
use function strlen;
use function str_contains;
use function stream_get_wrappers;
use function stream_wrapper_register;
use function substr;
use function time;
use function tmpfile;

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;
use Throwable;


if (! class_exists('HTTPServerCLIBackpressureStream', false)) {
   class HTTPServerCLIBackpressureStream
   {
      public static int $calls = 0;
      public static int $zeros = 0;
      public static string $written = '';

      public mixed $context;

      public static function reset (int $zeros): void
      {
         self::$calls = 0;
         self::$zeros = $zeros;
         self::$written = '';
      }

      public function stream_open (string $path, string $mode, int $options, null|string &$opened_path): bool
      {
         return true;
      }

      public function stream_write (string $data): int
      {
         self::$calls++;

         if (self::$zeros > 0) {
            self::$zeros--;
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

if (! class_exists('HTTPServerCLIBackpressureConnection', false)) {
   class HTTPServerCLIBackpressureConnection extends Connection
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
 * Recommendation #3 — server-side nonblocking writes must defer instead of close.
 *
 * Wrapper deterministically returns 0 on the first stream_write, then accepts
 * the whole buffer. Legacy implementation closed the connection after a single
 * zero-write (Finding 3 hardening) which killed legitimate slow clients.
 * The backpressure-aware implementation must:
 *  - stash the un-sent bytes in $pendingBuffer
 *  - request EVENT_WRITE notification (no-op when Server::$Event is unset
 *    in this synchronous probe; the in-memory state is the contract)
 *  - return true (NOT false) and keep the connection open
 *  - drain pendingBuffer on the next call (simulating EVENT_WRITE reentry)
 */

$probe = [
   'error' => '',
   'writingCalls' => null,
   'writingResult' => null,
   'writingClosed' => null,
   'writingPending' => null,
   'writingResumeResult' => null,
   'writingResumePending' => null,
   'writingResumeClosed' => null,
   'writingFinalBytes' => null,
   'uploadCalls' => null,
   'uploadWritten' => null,
   'uploadClosed' => null,
   'uploadPending' => null,
   'uploadResumeResult' => null,
   'uploadResumePending' => null,
   'uploadResumeClosed' => null,
   'uploadFinalBytes' => null,
];

return new Specification(
   description: 'TCP server writes must defer (not close) on zero-byte backpressure',
   Separator: new Separator(line: true),

   request: function () use (&$probe): string {
      $scheme = 'bootgly-backpressure';
      if (! in_array($scheme, stream_get_wrappers(), true)) {
         stream_wrapper_register($scheme, HTTPServerCLIBackpressureStream::class);
      }

      try {
         HTTPServerCLIBackpressureStream::reset(1);

         $writingSocket = fopen($scheme . '://writing', 'w+');
         if (! is_resource($writingSocket)) {
            $probe['error'] = 'Could not open zero-write stream for writing().';
            return "GET /backpressure-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         }

         $WritingConnection = new HTTPServerCLIBackpressureConnection($writingSocket);
         $WritingPackage = new class($WritingConnection) extends TCPPackages {
            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;

               $this->cache = true;
               $this->changed = true;
               $this->input = '';
               $this->output = '';
               $this->callbacks = [&$this->input];
               $this->expired = false;

               $this->downloading = [];
               $this->uploading = [];
               $this->closeAfterWrite = false;
            }
         };

         $payloadW = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";

         $probe['writingResult'] = $WritingPackage->writing($writingSocket, buffer: $payloadW);
         $probe['writingCalls'] = HTTPServerCLIBackpressureStream::$calls;
         $probe['writingClosed'] = $WritingConnection->closed;
         $probe['writingPending'] = ($WritingPackage->pendingBuffer !== '');

         $probe['writingResumeResult'] = $WritingPackage->writing($writingSocket, buffer: '');
         $probe['writingResumePending'] = ($WritingPackage->pendingBuffer !== '');
         $probe['writingResumeClosed'] = $WritingConnection->closed;
         $probe['writingFinalBytes'] = HTTPServerCLIBackpressureStream::$written;

         if (is_resource($writingSocket)) {
            @fclose($writingSocket);
         }

         HTTPServerCLIBackpressureStream::reset(1);

         $uploadSocket = fopen($scheme . '://upload', 'w+');
         $uploadFile = tmpfile();
         if (! is_resource($uploadSocket) || ! is_resource($uploadFile)) {
            $probe['error'] = 'Could not open zero-write stream or temp file for upload().';
            return "GET /backpressure-harness HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
         }

         $payloadU = 'UPLOAD-PAYLOAD';
         fwrite($uploadFile, $payloadU);
         rewind($uploadFile);

         $UploadConnection = new HTTPServerCLIBackpressureConnection($uploadSocket);
         $UploadPackage = new class($UploadConnection) extends TCPPackages {
            public function __construct (Connection $Connection)
            {
               $this->Connection = $Connection;

               $this->cache = true;
               $this->changed = true;
               $this->input = '';
               $this->output = '';
               $this->callbacks = [&$this->input];
               $this->expired = false;

               $this->downloading = [];
               $this->uploading = [];
               $this->closeAfterWrite = false;
            }
         };

         $payloadLength = strlen($payloadU);
         $probe['uploadWritten'] = $UploadPackage->upload(
            $uploadSocket,
            $uploadFile,
            $payloadLength,
            $payloadLength
         );
         $probe['uploadCalls'] = HTTPServerCLIBackpressureStream::$calls;
         $probe['uploadClosed'] = $UploadConnection->closed;
         $probe['uploadPending'] = ($UploadPackage->pendingBuffer !== '');

         $probe['uploadResumeResult'] = $UploadPackage->writing($uploadSocket, buffer: '');
         $probe['uploadResumePending'] = ($UploadPackage->pendingBuffer !== '');
         $probe['uploadResumeClosed'] = $UploadConnection->closed;
         $probe['uploadFinalBytes'] = HTTPServerCLIBackpressureStream::$written;

         if (is_resource($uploadSocket)) {
            @fclose($uploadSocket);
         }
         if (is_resource($uploadFile)) {
            @fclose($uploadFile);
         }
      }
      catch (Throwable $Throwable) {
         $probe['error'] = $Throwable::class . ': ' . $Throwable->getMessage();
      }

      return "GET /backpressure-harness HTTP/1.1\r\n"
         . "Host: localhost\r\n"
         . "Connection: close\r\n"
         . "\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/backpressure-harness', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'HARNESS-OK');
      }, GET);

      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function (string $response) use (&$probe): bool|string {
      if ($probe['error'] !== '') {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return $probe['error'];
      }

      if (
         $probe['writingResult'] !== true
         || $probe['writingClosed'] !== false
         || $probe['writingPending'] !== true
         || $probe['writingCalls'] !== 1
      ) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'writing() did not defer cleanly on zero-byte backpressure (must return true, keep connection open, stash pendingBuffer).';
      }

      $expectedW = "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nOK";
      if (
         $probe['writingResumeResult'] !== true
         || $probe['writingResumePending'] !== false
         || $probe['writingResumeClosed'] !== false
         || $probe['writingFinalBytes'] !== $expectedW
      ) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'writing() reentry did not drain pendingBuffer to completion.';
      }

      if (
         $probe['uploadCalls'] !== 1
         || $probe['uploadWritten'] !== 0
         || $probe['uploadClosed'] !== false
         || $probe['uploadPending'] !== true
      ) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'upload() did not defer cleanly on zero-byte backpressure (must return 0, keep connection open, stash pendingBuffer).';
      }

      if (
         $probe['uploadResumeResult'] !== true
         || $probe['uploadResumePending'] !== false
         || $probe['uploadResumeClosed'] !== false
         || $probe['uploadFinalBytes'] !== 'UPLOAD-PAYLOAD'
      ) {
         Vars::$labels = ['Probe state'];
         dump(json_encode($probe));
         return 'upload() backlog did not drain via writing() reentry.';
      }

      if (! str_contains($response, 'HARNESS-OK')) {
         Vars::$labels = ['Harness response'];
         dump(json_encode($response));
         return 'Harness request did not reach /backpressure-harness.';
      }

      return true;
   }
);
