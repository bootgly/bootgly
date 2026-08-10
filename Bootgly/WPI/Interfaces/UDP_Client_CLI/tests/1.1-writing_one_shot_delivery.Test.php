<?php


use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertion\Auxiliaries\Op;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Events\Select;
use Bootgly\WPI\Interfaces\UDP_Client_CLI;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections;
use Bootgly\WPI\Interfaces\UDP_Client_CLI\Connections\Connection;


return new Test(
   description: 'It should deliver a queued datagram exactly once per EVENT_WRITE arm',
   test: new Assertions(Case: function (): Generator {
      $Server = null;
      $Socket = null;
      $OldEvent = isset(UDP_Client_CLI::$Event) ? UDP_Client_CLI::$Event : null;
      $OldConnect = UDP_Client_CLI::$onClientConnect;
      $OldDisconnect = UDP_Client_CLI::$onClientDisconnect;
      $OldWrite = UDP_Client_CLI::$onDatagramWrite;

      try {
         // ! Hermetic hook state — the Connection constructor and writing()
         //   both dispatch these statics
         UDP_Client_CLI::$onClientConnect = null;
         UDP_Client_CLI::$onClientDisconnect = null;
         UDP_Client_CLI::$onDatagramWrite = null;

         // ! Boot the client statics writing() needs (no event loop run) —
         //   the Connections constructor resets every shared counter
         $Connections = new Connections();
         $Select = new Select($Connections);
         UDP_Client_CLI::$Event = $Select;

         // ! A real bound UDP peer + a "connected" client socket — loopback
         //   datagram delivery is in-kernel and immediate
         $Server = stream_socket_server(
            'udp://127.0.0.1:0', $code, $message, STREAM_SERVER_BIND
         );

         yield new Assertion(
            description: 'the loopback UDP peer is bound',
         )
            ->expect($Server !== false)
            ->to->be(true)
            ->assert();
         if ($Server === false) {
            return;
         }

         stream_set_blocking($Server, false);

         $address = stream_socket_get_name($Server, false);
         $Socket = $address !== false
            ? stream_socket_client("udp://{$address}", $code, $message, 2.0)
            : false;

         yield new Assertion(
            description: 'the client socket is connected to the peer',
         )
            ->expect($Socket !== false)
            ->to->be(true)
            ->assert();
         if ($Socket === false) {
            return;
         }

         $Connection = new Connection($Socket);
         Connections::$Connections[(int) $Socket] = $Connection;
         Connections::$stats = true;

         $id = (int) $Socket;
         $writesProperty = new ReflectionProperty(Select::class, 'writes');
         $writingProperty = new ReflectionProperty(Select::class, 'writing');
         $armed = function () use ($Select, $writesProperty, $writingProperty, $id): bool {
            return isSet($writesProperty->getValue($Select)[$id])
               || isSet($writingProperty->getValue($Select)[$id]);
         };
         // ? null = nothing on the wire within the window; '' is a REAL
         //   zero-byte datagram (the peer socket selected readable)
         $receive = function (int $microseconds) use (&$Server): null|string {
            $reads = [$Server];
            $writes = null;
            $excepts = null;
            $ready = stream_select($reads, $writes, $excepts, 0, $microseconds);
            if ($ready === false || $ready === 0) {
               return null;
            }

            $datagram = stream_socket_recvfrom($Server, 65535);

            return $datagram === false ? null : $datagram;
         };

         // # Case A — one queued datagram, two level-triggered wakeups
         $payload = 'Hello, Bootgly UDP!';
         $hooks = 0;
         UDP_Client_CLI::$onDatagramWrite = function () use (&$hooks): void {
            $hooks++;
         };

         $Connection->output = $payload;
         $Select->add($Socket, Select::EVENT_WRITE, $Connection);

         $return = $Connection->writing($Socket);
         $datagram = $receive(200000);
         $observed = [
            'return' => $return,
            'datagram' => $datagram,
            'output' => $Connection->output,
            'written' => $Connection->written,
            'armed' => $armed(),
            'hooks' => $hooks,
         ];

         yield new Assertion(
            description: 'the first wakeup delivers the datagram, consumes the'
               . ' buffer, records the sent length and drops the registration',
            fallback: 'writing() left the one-shot contract unmet: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'return' => true,
                  'datagram' => $payload,
                  'output' => '',
                  'written' => strlen($payload),
                  'armed' => false,
                  'hooks' => 1,
               ],
            )
            ->assert();

         $return = $Connection->writing($Socket);
         $duplicate = $receive(120000);
         $observed = [
            'return' => $return,
            'duplicate' => $duplicate,
            'hooks' => $hooks,
            'writes' => Connections::$writes,
            'written' => Connections::$written,
            'connectionWrites' => $Connection->writes,
         ];

         yield new Assertion(
            description: 'a second write-ready wakeup re-sends nothing and'
               . ' accounts exactly one datagram',
            fallback: 'The consumed datagram was resurrected on the wire: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'return' => true,
                  'duplicate' => null,
                  'hooks' => 1,
                  'writes' => 1,
                  'written' => strlen($payload),
                  'connectionWrites' => 1,
               ],
            )
            ->assert();

         // # Case B — a write-ready wakeup on an empty buffer
         $hooks = 0;
         $Connection->output = '';
         $Select->add($Socket, Select::EVENT_WRITE, $Connection);

         $return = $Connection->writing($Socket);
         $datagram = $receive(120000);
         $observed = [
            'return' => $return,
            'datagram' => $datagram,
            'hooks' => $hooks,
            'armed' => $armed(),
         ];

         yield new Assertion(
            description: 'an empty buffer delivers nothing — no zero-byte'
               . ' datagram, no hook, and the stale registration is dropped',
            fallback: 'A spurious wakeup reached the wire: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'return' => true,
                  'datagram' => null,
                  'hooks' => 0,
                  'armed' => false,
               ],
            )
            ->assert();

         // # Case C — the registration is dropped BEFORE the hook runs, so a
         //   hook that re-arms (the shipped Demo) survives the disarm
         $armedInsideHook = null;
         UDP_Client_CLI::$onDatagramWrite = function ($Socket, $Connection)
            use (&$armedInsideHook, $armed): void {
            $armedInsideHook = $armed();

            UDP_Client_CLI::$Event->add(
               $Socket, UDP_Client_CLI::$Event::EVENT_WRITE, $Connection
            );
         };

         $Connection->output = 'ping';
         $Select->add($Socket, Select::EVENT_WRITE, $Connection);

         $Connection->writing($Socket);
         $receive(200000);
         $observed = [
            'armedInsideHook' => $armedInsideHook,
            'armedAfterReturn' => $armed(),
         ];

         yield new Assertion(
            description: 'the disarm precedes the hook, and a hook re-arm'
               . ' survives it',
            fallback: 'The hook observed a stale registration: '
               . json_encode($observed)
         )
            ->expect(
               $observed,
               Op::Identical,
               [
                  'armedInsideHook' => false,
                  'armedAfterReturn' => true,
               ],
            )
            ->assert();
      }
      finally {
         // @ Cleanup
         if ($Socket !== null && $Socket !== false) {
            UDP_Client_CLI::$Event->del($Socket, Select::EVENT_WRITE);
            @fclose($Socket);
         }
         if ($Server !== null && $Server !== false) {
            @fclose($Server);
         }

         Connections::$Connections = [];
         Connections::$stats = false;

         UDP_Client_CLI::$onClientConnect = $OldConnect;
         UDP_Client_CLI::$onClientDisconnect = $OldDisconnect;
         UDP_Client_CLI::$onDatagramWrite = $OldWrite;
         if ($OldEvent !== null) {
            UDP_Client_CLI::$Event = $OldEvent;
         }
      }
   })
);
