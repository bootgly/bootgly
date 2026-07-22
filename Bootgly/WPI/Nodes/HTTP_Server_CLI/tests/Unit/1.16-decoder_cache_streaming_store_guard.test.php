<?php


use const Bootgly\WPI;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Endpoints\Servers\Decoder\States;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Packages as TCPPackages;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Decoder_;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;

/**
 * Regression — a streaming-multipart request must never be stored as an L1
 * template. The inline full-body multipart path completes with
 * `Body->streaming === true` and populates `Request->files` DURING decode;
 * a stored template's clone scrubs `_files`, so a later byte-identical
 * multipart POST adopting it would silently lose its upload.
 */

if (! class_exists('U116Connection', false)) {
   class U116Connection extends Connection
   {
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
         $this->writes = 1;
      }
   }
}


return new Specification(
   description: 'It should not store streaming-multipart requests as byte-keyed templates',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;

      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U116 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      // ! Prime the environment pieces the multipart decoder touches.
      if (! isset($WPI->Server)) {
         /** @var HTTP_Server_CLI $Server */
         $Server = (new ReflectionClass(HTTP_Server_CLI::class))->newInstanceWithoutConstructor();
         $WPI->Server = $Server;
      }

      // ! Mirror the server boot: `$WPI->Request` aliases the worker-global
      //   static cell, so the L1-hit path (`Server::$Request = ...`) and the
      //   miss path (`$WPI->Request = ...`) land in one observable cell.
      HTTP_Server_CLI::$Request = new Request;
      $WPI->Request = &HTTP_Server_CLI::$Request;

      try {
         $Connection = new U116Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_;

         // ! A complete multipart POST (no request-line query, under the
         //   2,048-byte cacheable bound) with one small file part.
         $boundary = '----u116';
         $body = "--{$boundary}\r\n"
            . "Content-Disposition: form-data; name=\"f\"; filename=\"a.txt\"\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . "hello-upload\r\n"
            . "--{$boundary}--\r\n";
         $length = strlen($body);
         $wire = "POST /u116-upload HTTP/1.1\r\n"
            . "Host: localhost\r\n"
            . "Content-Type: multipart/form-data; boundary={$boundary}\r\n"
            . "Content-Length: {$length}\r\n"
            . "\r\n"
            . $body;
         $size = strlen($wire);

         // @ First sighting: full decode populates the upload.
         $Package->changed = true;
         $state1 = $Decoder->decode($Package, $wire, $size);
         /** @var Request $First */
         $First = $WPI->Request;

         yield new Assertion(
            description: 'The first multipart decode completes with its upload',
         )
            ->expect([$state1, $Package->consumed, $First->hasFiles, count($First->files)])
            ->to->be([States::Complete, $size, true, 1])
            ->assert();

         // @ Byte-identical repeat: a stored streaming template would be
         //   adopted here with `_files` scrubbed — the upload would vanish.
         $Package->changed = true;
         $state2 = $Decoder->decode($Package, $wire, $size);
         /** @var Request $Second */
         $Second = $WPI->Request;

         yield new Assertion(
            description: 'The byte-identical repeat still decodes its upload (no template adoption)',
         )
            ->expect([$state2, $Package->consumed, $Second->hasFiles, count($Second->files)])
            ->to->be([States::Complete, $size, true, 1])
            ->assert();
      }
      finally {
         unset($WPI->Request); // break the static alias installed above
         if ($OldRequest !== null) {
            $WPI->Request = $OldRequest;
         }
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
