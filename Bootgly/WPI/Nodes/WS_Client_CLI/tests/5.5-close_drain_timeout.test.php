<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Session;


return new Specification(
   description: 'It should bound a stalled graceful close and never enqueue after closing starts',
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

      $armed = false;
      $duplicateWasIdempotent = false;
      $lateFramesWereRejected = false;
      $supervisorWasQuiet = false;
      $expired = false;
      $elapsed = 0.0;

      if ($Writer !== false && $Reader !== false) {
         stream_set_blocking($Writer, false);
         stream_set_blocking($Reader, false);

         $Client = new WS_Client_CLI(WS_Client_CLI::MODE_TEST);
         $Client->configure(
            host: '127.0.0.1',
            port: 1,
            heartbeatInterval: 1,
            compression: false,
            closeTimeout: 0.02
         );
         $Client->reset();

         $Connection = new Connection($Writer, Client: $Client);
         $Session = new Session($Connection, 'test-key', $Client);
         $Client->Session = $Session;

         // @ Install production write/disconnect routing without dialing.
         $Wire = new ReflectionMethod($Client, 'wire');
         $Wire->invoke($Client);

         // ! Model an already-retained suffix. Removing write readiness after
         //   close() makes the peer deterministically unable to drain it while
         //   leaving the monotonic guard as the only teardown path.
         $Connection->output = 'retained-suffix';
         $Session->close(1000, 'bounded');
         $queued = $Connection->output;
         $armed = $Session->closeTimer !== 0 && $Session->closeAfterWrite;

         $again = $Session->close(1001, 'duplicate');
         $duplicateWasIdempotent = $again && $Connection->output === $queued;

         $lateSend = $Session->send('late');
         $latePing = $Session->ping('late-ping');
         $lateFramesWereRejected = $lateSend === false
            && $latePing === false
            && $Connection->output === $queued
            && $Session->awaitingPong === false;

         $Session->lastActivity = 0;
         $Session->supervise();
         $supervisorWasQuiet = $Connection->output === $queued
            && $Session->awaitingPong === false;

         TCP_Client_CLI::$Event->del(
            $Connection->Socket,
            TCP_Client_CLI::$Event::EVENT_WRITE
         );
         $started = (int) hrtime(true);
         TCP_Client_CLI::$Event->loop();
         $elapsed = ((int) hrtime(true) - $started) / 1_000_000_000;
         $expired = $Session->disconnected
            && $Session->closeAfterWrite === false
            && $Session->closeTimer === 0
            && $Connection->status === Connection::STATUS_CLOSED;

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

      yield new Assertion(description: 'a stalled close arms its monotonic deadline')
         ->expect($armed)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'a duplicate close queues no second close frame')
         ->expect($duplicateWasIdempotent)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'ordinary sends and pings are rejected after closing starts')
         ->expect($lateFramesWereRejected)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'the heartbeat supervisor emits nothing after closing starts')
         ->expect($supervisorWasQuiet)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'the monotonic deadline force-closes the stalled transport')
         ->expect($expired)
         ->to->be(true)
         ->assert();
      yield new Assertion(description: 'the close bound fires promptly without wall-clock polling')
         ->expect($elapsed >= 0.01 && $elapsed < 0.3)
         ->to->be(true)
         ->assert();
   })
);
