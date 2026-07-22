<?php


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
 * L0 lifecycle — the per-connection consecutive-repeat template:
 *  - first sighting keys the connection (`known`) without cloning;
 *  - the SECOND consecutive sighting of a query-bearing target (refused by
 *    the shared L1) builds the template;
 *  - the third adopts it (hit: the template instance survives untouched —
 *    a re-decode would have replaced it with a fresh clone);
 *  - the compare lives in trimmed space, so a repeat behind stray CRLF
 *    padding still hits with exact consumed accounting;
 *  - alternating targets never build a template (no clone churn);
 *  - reassembled (carried) events — refused by the L1 — may key and hit
 *    the L0 (fragmenting repeat clients converge on their own connection).
 */

if (! class_exists('U119Connection', false)) {
   class U119Connection extends Connection
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
   description: 'It should build and adopt the per-connection template on consecutive identical requests',
   test: new Assertions(Case: function (): Generator {
      $Socket = fopen('php://memory', 'w+');
      if (! is_resource($Socket)) {
         yield new Assertion(description: 'U119 probe stream opens')
            ->expect(false)
            ->to->be(true)
            ->assert();
         return;
      }

      try {
         $Connection = new U119Connection($Socket);
         $Package = new class($Connection) extends TCPPackages {
            public function pretend (bool $carried): void
            {
               $this->carried = $carried;
            }
         };
         $Decoder = new Decoder_;

         // ! Query-bearing target: the shared L1 refuses it — only the L0
         //   can accelerate its repeats.
         $wire = "GET /u119?id=1 HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $size = strlen($wire);

         // @ 1st sighting: keys the connection, no template yet.
         $Package->changed = true;
         $state1 = $Decoder->decode($Package, $wire, $size);

         yield new Assertion(
            description: 'The first sighting keys the connection without building a template',
         )
            ->expect([$state1, $Package->known, $Package->Template])
            ->to->be([States::Complete, $wire, null])
            ->assert();

         // @ 2nd consecutive sighting: decodes and builds the template.
         $Package->changed = true;
         $state2 = $Decoder->decode($Package, $wire, $size);
         $Template = $Package->Template;

         yield new Assertion(
            description: 'The second sighting builds the template as a detached clone',
         )
            ->expect([
               $state2,
               $Template instanceof Request,
               $Template === $Package->decoded,
            ])
            ->to->be([States::Complete, true, false])
            ->assert();

         // @ 3rd: adopts the template — its instance survives untouched
         //   (a re-decode would have replaced it with a fresh clone).
         $Package->changed = true;
         $state3 = $Decoder->decode($Package, $wire, $size);

         yield new Assertion(
            description: 'The third sighting hits: same template instance, published owned Request',
         )
            ->expect([
               $state3,
               $Package->Template === $Template,
               HTTP_Server_CLI::$Request === $Package->decoded,
               $Package->consumed,
            ])
            ->to->be([States::Complete, true, true, $size])
            ->assert();

         // @ Padding repeat: leading CRLFs, key compare in trimmed space.
         $padded = "\r\n\r\n{$wire}";
         $state4 = $Decoder->decode($Package, $padded, strlen($padded));

         yield new Assertion(
            description: 'A padded repeat still hits, consuming padding plus request',
         )
            ->expect([$state4, $Package->Template === $Template, $Package->consumed])
            ->to->be([States::Complete, true, strlen($padded)])
            ->assert();

         // @ Leak probe: handler-style mutations on the owned Request must
         //   not survive an L0-hit adoption (assume() scrubs, same contract
         //   as the shared-cache hit).
         /** @var Request $Owned */
         $Owned = $Package->decoded;
         $Owned->handled = 'leak-probe';   // __set → attributes
         $Owned->username = 'root';        // hook → private auth state
         $Owned->Body->raw = 'leaked-raw';
         $state5 = $Decoder->decode($Package, $wire, $size);

         yield new Assertion(
            description: 'An L0 hit scrubs handler mutations from the owned Request',
         )
            ->expect([
               $state5,
               isSet($Owned->handled),
               $Owned->username,
               $Owned->Body->raw,
            ])
            ->to->be([States::Complete, false, '', ''])
            ->assert();

         // @ Alternating targets: the template drops and never rebuilds.
         $other = "GET /u119?id=2 HTTP/1.1\r\nHost: localhost\r\n\r\n";
         $Decoder->decode($Package, $other, strlen($other));
         $afterOther = $Package->Template;
         $Decoder->decode($Package, $wire, $size);
         $afterBack = $Package->Template;

         yield new Assertion(
            description: 'Alternating targets churn the key and never build a template',
         )
            ->expect([$afterOther, $afterBack, $Package->known])
            ->to->be([null, null, $wire])
            ->assert();

         // @ Carried (reassembled) repeats — refused by the L1 — converge
         //   on the L0: 2nd carried sighting stores, 3rd hits.
         $Package->pretend(true);
         $Decoder->decode($Package, $wire, $size);          // repeat of known → store
         $CarriedTemplate = $Package->Template;
         $stateCarried = $Decoder->decode($Package, $wire, $size); // hit

         yield new Assertion(
            description: 'Carried repeats build and adopt the connection template',
         )
            ->expect([
               $CarriedTemplate instanceof Request,
               $stateCarried,
               $Package->Template === $CarriedTemplate,
               $Package->consumed,
            ])
            ->to->be([true, States::Complete, true, $size])
            ->assert();
      }
      finally {
         if (is_resource($Socket)) {
            @fclose($Socket);
         }
      }
   })
);
