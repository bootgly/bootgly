<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Modules\WS;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Message\Frame;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


return new Specification(
   description: 'It should queue direct sends through zero writes and drain them byte-exact in order',
   test: new Assertions(Case: function (): Generator {
      $Listener = stream_socket_server(
         'tcp://127.0.0.1:0',
         $code,
         $message,
         STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
      );
      $address = $Listener !== false ? stream_socket_get_name($Listener, false) : false;
      $Writer = is_string($address)
         ? stream_socket_client("tcp://{$address}", $code, $message, 1.0)
         : false;
      $Reader = $Listener !== false
         ? stream_socket_accept($Listener, 1.0)
         : false;

      $forcedZero = false;
      $queued = '';
      $received = '';
      $firstPayload = null;
      $secondPayload = null;
      $closeQueued = false;
      $closeTimerArmed = false;
      $closeTimerCancelled = false;
      $drained = false;

      if ($Writer !== false && $Reader !== false) {
         stream_set_blocking($Writer, false);
         stream_set_blocking($Reader, false);

         $Socket = socket_import_stream($Writer);
         if ($Socket !== false) {
            socket_set_option($Socket, SOL_SOCKET, SO_SNDBUF, 4096);
         }

         // ! Fill the non-blocking kernel send buffer until fwrite makes
         //   exactly zero progress. The WS sends below must stay in userspace.
         $filler = str_repeat('f', 65536);
         $filled = 0;
         for ($attempt = 0; $attempt < 4096; $attempt++) {
            $written = @fwrite($Writer, $filler);
            if ($written === false || $written === 0) {
               $forcedZero = $written === 0;
               break;
            }
            $filled += $written;
         }

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure('127.0.0.1', 1, compression: false);
         $Client->reset();

         $Connection = new Connection($Writer, Client: $Client);
         $Session = new Session($Connection, 'test-key', $Client);
         $Client->Session = $Session;

         // @ Install the production write-completion router without dialing.
         $Wire = new ReflectionMethod($Client, 'wire');
         $Wire->invoke($Client);

         $Session->send('first');
         $Session->send('second');
         $Session->close(1000, 'done');
         $queued = $Connection->output;
         $closeTimerArmed = $Session->closeTimer !== 0;

         $First = Frame::decode($queued, 0, 1024);
         $Second = $First !== null
            ? Frame::decode($queued, $First->consumed, 1024)
            : null;
         $Close = $Second !== null
            ? Frame::decode($queued, $First->consumed + $Second->consumed, 1024)
            : null;
         $firstPayload = $First?->payload;
         $secondPayload = $Second?->payload;
         $closeQueued = $Close?->opcode === WS::OPCODE_CLOSE;

         // @ Free only the filler. The readiness loop must flush the queued
         //   frames; no direct writing() retry is made by the test.
         $discarded = 0;
         $drainDeadline = hrtime(true) + 1_000_000_000;
         while ($discarded < $filled && hrtime(true) < $drainDeadline) {
            $chunk = @fread($Reader, min(65536, $filled - $discarded));
            if ($chunk === false || $chunk === '') {
               usleep(1000);
               continue;
            }
            $discarded += strlen($chunk);
         }

         TCP_Client_CLI::$Event->defer(
            (int) hrtime(true) + 100_000_000,
            static function (): void {
               TCP_Client_CLI::$Event->destroy();
            }
         );
         TCP_Client_CLI::$Event->loop();
         $drained = $Connection->output === ''
            && $Connection->status === Connection::STATUS_CLOSED;
         $closeTimerCancelled = $Session->closeTimer === 0;

         while (true) {
            $chunk = @fread($Reader, 65536);
            if ($chunk === false || $chunk === '') {
               break;
            }
            $received .= $chunk;
         }

         $Connection->close();
      }

      if (is_resource($Writer)) {
         fclose($Writer);
      }
      if (is_resource($Reader)) {
         fclose($Reader);
      }
      if (is_resource($Listener)) {
         fclose($Listener);
      }

      yield new Assertion(description: 'the probe forces an actual zero-byte socket write')
         ->expect($forcedZero)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'later sends append behind the retained suffix in order')
         ->expect($firstPayload === 'first' && $secondPayload === 'second' && $closeQueued)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'write readiness drains the queue before graceful transport close')
         ->expect($drained)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'graceful close arms and then cancels its drain deadline')
         ->expect($closeTimerArmed && $closeTimerCancelled)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'the peer receives the queued frames byte-exact')
         ->expect($received)
         ->to->be($queued)
         ->assert();
   })
);
