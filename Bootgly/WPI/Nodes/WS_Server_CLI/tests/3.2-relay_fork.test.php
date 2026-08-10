<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Server_CLI\Channels;
use Bootgly\WPI\Nodes\WS_Server_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Server_CLI\Relay;
use Bootgly\WPI\Nodes\WS_Server_CLI\Session;


return new Specification(
   description: 'It should relay whole envelopes across a real fork — bus inheritance and both constructor roles',
   test: new Assertions(Case: function (): Generator {
      // ! The node's exact pre-fork bus topology (booting()): one datagram
      //   socketpair per worker, created BEFORE the fork so every process
      //   inherits every end — each worker's constructor then closes the
      //   ends it does not own, exactly as wire() does
      $buses = [];
      for ($worker = 0; $worker < 2; $worker++) {
         $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_DGRAM, 0);
         if ($pair === false) {
            break;
         }
         $buses[$worker] = $pair;
      }
      $report = stream_socket_pair(
         STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
      );

      yield new Assertion(
         description: 'the pre-fork buses and the report pipe are created',
      )
         ->expect(count($buses) === 2 && $report !== false)
         ->to->be(true)
         ->assert();
      if (count($buses) !== 2 || $report === false) {
         return;
      }
      [$Reader, $Writer] = $report;

      // ! Both frames worker 0 will relay — computed before the fork so the
      //   child knows the byte total it must collect
      $control = Frame::encode(WS::OPCODE_TEXT, 'control!');
      $big = Frame::encode(WS::OPCODE_TEXT, str_repeat('b', 20000));
      $expected = $control . $big;

      $pid = pcntl_fork();
      if ($pid === 0) {
         // # Child — worker 1: the peer worker's whole receive path over
         //   the inherited bus ends
         $Relay = new Relay($buses, 1, 2);

         // ! A local member over a stream loopback — the same real
         //   deliver() lane the 3.1 spec rides
         $streams = stream_socket_pair(
            STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP
         );
         if ($streams === false) {
            fwrite($Writer, 'member-setup-failed');
            fclose($Writer);
            posix_kill(posix_getpid(), SIGKILL);
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
         Channels::$Channels = [];
         Channels::fetch('lobby')->join(new Session($Connection));

         // @@ Drain the mailbox until the member holds every expected byte
         //    (or the deadline passes — report whatever arrived)
         $received = '';
         $target = strlen($expected);
         $deadline = time() + 5;
         while (strlen($received) < $target && time() < $deadline) {
            $reads = [$Relay->Socket];
            $writes = null;
            $excepts = null;
            $ready = stream_select($reads, $writes, $excepts, 0, 200000);
            if ($ready !== false && $ready > 0) {
               $Relay->reading($Relay->Socket);
            }

            $chunk = fread($PeerSocket, 65536);
            if (is_string($chunk) && $chunk !== '') {
               $received .= $chunk;
            }
         }

         fwrite($Writer, $received);
         fclose($Writer);

         // ! Hard exit — no shutdown handlers, no inherited output flush
         posix_kill(posix_getpid(), SIGKILL);
      }

      // # Parent — worker 0: the origin worker's whole send path
      $Relay = new Relay($buses, 0, 2);
      fclose($Writer);

      // @ One relay() per broadcast — each is exactly one datagram to the
      //   peer mailbox, through the real send-side cap guard
      $Relay->relay('lobby', $control);
      $Relay->relay('lobby', $big);

      // @ Collect the child's verdict without one blocking read — a signal
      //   in this process (the dying child's SIGCHLD included) would
      //   interrupt it and fake an empty result
      stream_set_blocking($Reader, false);
      $delivered = '';
      $deadline = time() + 8;
      while (time() < $deadline) {
         $chunk = fread($Reader, 65536);
         if (is_string($chunk) && $chunk !== '') {
            $delivered .= $chunk;
         }
         if (feof($Reader)) {
            break;
         }
         usleep(50000);
      }
      fclose($Reader);
      pcntl_waitpid($pid, $status);

      // @ Cleanup — the constructor already closed the ends this process
      //   does not own (is_resource() is false for those); release the rest
      foreach ($buses as $pair) {
         foreach ($pair as $end) {
            if (is_resource($end)) {
               fclose($end);
            }
         }
      }

      $observed = [
         'expected' => strlen($expected),
         'delivered' => strlen($delivered),
         'identical' => $delivered === $expected,
      ];

      yield new Assertion(
         description: 'both broadcasts cross the fork whole and reach the'
            . ' peer worker\'s member byte-identical',
         fallback: 'The forked relay hop broke: ' . json_encode($observed)
      )
         ->expect(
            $observed,
            Op::Identical,
            [
               'expected' => strlen($expected),
               'delivered' => strlen($expected),
               'identical' => true,
            ],
         )
         ->assert();
   })
);
