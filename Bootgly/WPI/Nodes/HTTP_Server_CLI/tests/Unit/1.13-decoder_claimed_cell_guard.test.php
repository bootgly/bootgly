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
 * Regression — with `changed === false` the shared decoder reuses the
 * worker-global Request without allocating. When that cell is still CLAIMED
 * by a paused body decode of another connection (`Body->waiting === true`),
 * re-entering the claimed instance would corrupt its in-flight body state:
 * the decoder must allocate a fresh Request instead. When the cell is not
 * claimed, the allocation-free reuse fast path must be preserved.
 */

if (! class_exists('U113Connection', false)) {
   class U113Connection extends Connection
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
   description: 'It should allocate a fresh Request when the worker cell is claimed by a paused body decode',
   test: new Assertions(Case: function (): Generator {
      $WPI = WPI;
      $OldRequest = $WPI->Request ?? null;

      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U113 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      try {
         $Connection = new U113Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {};
         $Decoder = new Decoder_;

         // ! A query-bearing target is never L1-cached, so every decode of it
         //   reaches the worker-cell handling under test.
         $head = "GET /u113-claim?probe=1 HTTP/1.1\r\nHost: localhost\r\n\r\n";

         // # Connection A holds the cell with a paused body decode.
         $Claimed = new Request;
         $Claimed->Body->waiting = true;
         $Claimed->Body->raw = 'CLAIMED-BODY';
         $WPI->Request = $Claimed;

         // @ Connection C re-sends byte-identical bytes: `changed === false`.
         $Package->changed = false;
         $StateFresh = $Decoder->decode($Package, $head, strlen($head));
         $Fresh = $WPI->Request;

         yield new Assertion(
            description: 'A claimed cell forces a fresh Request and the claimed instance stays intact',
         )
            ->expect([
               $StateFresh,
               $Fresh === $Claimed,
               $Claimed->Body->raw,
               $Claimed->Body->waiting,
               $Package->consumed,
            ])
            ->to->be([States::Complete, false, 'CLAIMED-BODY', true, strlen($head)])
            ->assert();

         // @ Control: an unclaimed cell keeps the allocation-free reuse.
         $Package->changed = false;
         $StateReused = $Decoder->decode($Package, $head, strlen($head));

         yield new Assertion(
            description: 'An unclaimed cell preserves the reuse fast path (same instance, no allocation)',
         )
            ->expect([$StateReused, $WPI->Request === $Fresh])
            ->to->be([States::Complete, true])
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
