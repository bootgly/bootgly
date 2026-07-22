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
 * Regression — the L1 request-template cache must only store reads that were
 * exactly one complete request. A pipelined batch used to be stored under the
 * WHOLE batch bytes as key while representing only its first request; a later
 * hit then reported the full batch as consumed, so N pipelined requests
 * received one response and the tail was silently dropped.
 */

if (! class_exists('U114Connection', false)) {
   class U114Connection extends Connection
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
   description: 'It should never store a pipelined batch as a request template nor serve it as one',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;

      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U114 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      try {
         $Connection = new U114Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_;

         $single = "GET /u114-batch HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $batch = "{$single}{$single}";
         $length = strlen($single);

         // @ First batch read: decodes only the first request of the batch.
         $WPI->Request = new Request;
         $Package->changed = true;
         $StateBatch1 = $Decoder->decode($Package, $batch, strlen($batch));
         $consumedBatch1 = $Package->consumed;

         // @ Second, byte-identical batch read: a stored batch template would
         //   now hit and report the WHOLE batch as consumed (the dropped-tail
         //   defect signature). It must decode the first request again.
         $Package->changed = true;
         $StateBatch2 = $Decoder->decode($Package, $batch, strlen($batch));
         $consumedBatch2 = $Package->consumed;

         yield new Assertion(
            description: 'A pipelined batch is never served from the template cache',
         )
            ->expect([$StateBatch1, $consumedBatch1, $StateBatch2, $consumedBatch2])
            ->to->be([States::Complete, $length, States::Complete, $length])
            ->assert();

         // ? The per-connection L0 mirrors the batch guard: a partially
         //   consumed read (consumed < size) must never build the
         //   connection template either — a batch hit would drop its tail.
         yield new Assertion(
            description: 'A pipelined batch never builds the connection template',
         )
            ->expect($Package->Template === null)
            ->to->be(true)
            ->assert();

         // @ Control: an exactly-one-request read still stores its template
         //   and later byte-identical reads hit it.
         $Package->changed = true;
         $StateSingle1 = $Decoder->decode($Package, $single, $length);
         $consumedSingle1 = $Package->consumed;

         $Package->changed = true;
         $StateSingle2 = $Decoder->decode($Package, $single, $length);
         $consumedSingle2 = $Package->consumed;

         yield new Assertion(
            description: 'A complete single-request read stores and serves its template',
         )
            ->expect([
               $StateSingle1,
               $consumedSingle1,
               $StateSingle2,
               $consumedSingle2,
               $Package->decoded instanceof Request,
               HTTP_Server_CLI::$Request === $Package->decoded,
            ])
            ->to->be([States::Complete, $length, States::Complete, $length, true, true])
            ->assert();
      }
      finally {
         if ($OldRequest !== null) {
            $WPI->Request = $OldRequest;
         }
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
