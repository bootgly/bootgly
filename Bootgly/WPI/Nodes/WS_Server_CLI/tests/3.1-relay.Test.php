<?php


use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Channels;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Relay;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


return new Test(
   description: 'It should deliver whole relay envelopes to local members and drop partial ones',
   test: new Assertions(Case: function (): Generator {
      $mailboxRead = null;
      $mailboxWrite = null;
      $decoyRead = null;
      $Socket = null;
      $PeerSocket = null;
      $ChannelsBefore = Channels::$Channels;
      $InstanceBefore = Relay::$Instance;

      try {
         // ! Hermetic shared state — the channel registry and the Relay
         //   instance are mutable statics
         Timer::del();
         Channels::$Channels = [];
         Relay::$Instance = null;

         // ! The real mailbox datagram pair + a decoy send end: the Relay
         //   constructor always closes buses[index][1], so the true writer
         //   must survive OUTSIDE the bus array
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
         $decoy = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);

         yield new Assertion(
            description: 'the datagram socket pairs are created',
         )
            ->expect($pair !== false && $decoy !== false)
            ->to->be(true)
            ->assert();
         if ($pair === false || $decoy === false) {
            return;
         }

         [$mailboxRead, $mailboxWrite] = $pair;
         [$decoyRead, $decoyWrite] = $decoy;

         $Relay = new Relay([0 => [$mailboxRead, $decoyWrite]], 0, 1);

         // ! A real member Session over a stream loopback pair — deliver()
         //   rides the real TCP writer fast lane, synchronous on an idle
         //   socket
         $streams = stream_socket_pair(
            STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
         );

         yield new Assertion(
            description: 'the member loopback pair is created',
         )
            ->expect($streams !== false)
            ->to->be(true)
            ->assert();
         if ($streams === false) {
            return;
         }

         [$Socket, $PeerSocket] = $streams;
         stream_set_blocking($PeerSocket, false);

         $Connection = new class($Socket, '127.0.0.1', 1) extends Connection {
            // ! No event loop in this process — Connection::close()
            //   dereferences the uninitialized Server::$Event
            public function close (): true
            {
               $this->status = Connections::STATUS_CLOSED;

               return true;
            }
         };
         $Connection->status = Connections::STATUS_ESTABLISHED;

         $Session = new Session($Connection);
         Channels::fetch('lobby')->join($Session);

         // ? Everything deliver() wrote to the member, drained off the peer
         //   end of its loopback pair
         $drain = function () use (&$PeerSocket): string {
            $received = '';
            while (true) {
               $reads = [$PeerSocket];
               $writes = null;
               $excepts = null;
               $ready = stream_select($reads, $writes, $excepts, 0, 120000);
               if ($ready === false || $ready === 0) {
                  break;
               }

               $chunk = fread($PeerSocket, 65536);
               if ($chunk === false || $chunk === '') {
                  break;
               }
               $received .= $chunk;
            }

            return $received;
         };

         // # Case A — control: a small envelope crosses whole in any world,
         //   proving the harness itself (the failure below is the bug)
         $frame = Frame::encode(WS::OPCODE_TEXT, 'control!');
         fwrite($mailboxWrite, pack('N', 5) . 'lobby' . $frame);
         $Relay->reading($Relay->Socket);
         $delivered = $drain();

         yield new Assertion(
            description: 'a small cross-worker envelope reaches the local'
               . ' member intact (control)',
            fallback: 'The control envelope broke: '
               . json_encode(['delivered' => strlen($delivered)])
         )
            ->expect($delivered === $frame)
            ->to->be(true)
            ->assert();

         // # Case B — a >8 KiB envelope must arrive whole, not as an
         //   8192-byte prefix with the tail discarded by the kernel
         $frame = Frame::encode(WS::OPCODE_TEXT, str_repeat('a', 20000));
         fwrite($mailboxWrite, pack('N', 5) . 'lobby' . $frame);
         $Relay->reading($Relay->Socket);
         $delivered = $drain();
         $observed = [
            'declared' => strlen($frame),
            'delivered' => strlen($delivered),
            'identical' => $delivered === $frame,
         ];

         yield new Assertion(
            description: 'a 20 KB broadcast frame is delivered byte-identical'
               . ' to the peer-worker member',
            fallback: 'The mailbox read truncated the datagram: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'declared' => strlen($frame),
                  'delivered' => strlen($frame),
                  'identical' => true,
               ],
            )
            ->assert();

         // # Case C — an envelope too short for even a frame header must be
         //   undeliverable, never dispatched as a partial frame
         fwrite($mailboxWrite, pack('N', 5) . 'lobby' . 'X');
         $Relay->reading($Relay->Socket);
         $delivered = $drain();

         yield new Assertion(
            description: 'a truncated envelope is dropped instead of'
               . ' dispatched as a partial frame',
            fallback: 'A partial frame reached the member: '
               . json_encode(['delivered' => $delivered])
         )
            ->expect($delivered === '')
            ->to->be(true)
            ->assert();
      }
      finally {
         // @ Cleanup
         Channels::$Channels = $ChannelsBefore;
         Relay::$Instance = $InstanceBefore;
         Timer::del();

         foreach ([$mailboxRead, $mailboxWrite, $decoyRead, $Socket, $PeerSocket] as $resource) {
            if ($resource !== null && $resource !== false) {
               @fclose($resource);
            }
         }
      }
   })
);
