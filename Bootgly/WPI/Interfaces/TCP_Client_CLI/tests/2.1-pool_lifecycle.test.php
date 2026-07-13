<?php

use function fclose;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;
use function usleep;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Pool;


return new Specification(
   description: 'It should park, reuse, release and drop pooled connections (idle-first lifecycle)',
   test: new Assertions(Case: function (): Generator {
      // ! Boot the client statics (Client::$Event) that Connection->close() uses
      new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      // ! A real established loopback pair — Connection requires a socket with
      //   a peer name (unix socketpairs have none and self-close on construct)
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      $address = stream_socket_get_name($Server, false);
      $Socket = stream_socket_client("tcp://{$address}");
      $Peer = stream_socket_accept($Server);
      $Connection = new Connection($Socket);

      yield new Assertion(description: 'the loopback connection was established')
         ->expect($Connection->status === Connection::STATUS_ESTABLISHED)
         ->to->be(true)
         ->assert();

      $Pool = new Pool(['min' => 0, 'max' => 2]);

      // @ attach: park the fresh connection as idle
      $Pool->attach($Connection);

      yield new Assertion(description: 'attach() parks the connection idle and counts it as created')
         ->expect($Pool->created === 1 && isSet($Pool->idle[$Connection->id]))
         ->to->be(true)
         ->assert();

      // @ acquire: idle-first reuse
      $Acquired = $Pool->acquire();

      yield new Assertion(description: 'acquire() returns the parked connection itself')
         ->expect($Acquired === $Connection)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the acquired connection moved from idle to busy')
         ->expect(isSet($Pool->idle[$Connection->id]) === false && isSet($Pool->busy[$Connection->id]))
         ->to->be(true)
         ->assert();

      // @ release: park it back
      $Pool->release($Connection);

      yield new Assertion(description: 'release() parks the connection back into the idle pool')
         ->expect(isSet($Pool->idle[$Connection->id]) && isSet($Pool->busy[$Connection->id]) === false)
         ->to->be(true)
         ->assert();

      // @ acquire again: same live connection, nothing new created
      $Reacquired = $Pool->acquire();

      yield new Assertion(description: 'a second acquire() reuses the same connection without creating another')
         ->expect($Reacquired === $Connection && $Pool->created === 1)
         ->to->be(true)
         ->assert();

      // @ drop: forget the connection entirely
      $Pool->drop($Connection);

      yield new Assertion(description: 'drop() removes the connection and restores the created count')
         ->expect($Pool->created === 0 && $Pool->idle === [] && $Pool->busy === [])
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'acquire() on an empty pool yields null')
         ->expect($Pool->acquire() === null)
         ->to->be(true)
         ->assert();

      // ---

      // ! A second pair whose remote end dies while the connection is parked
      $Socket2 = stream_socket_client("tcp://{$address}");
      $Peer2 = stream_socket_accept($Server);
      $Connection2 = new Connection($Socket2);

      $Pool->attach($Connection2);

      // @ Kill the remote end — the parked connection is now half-dead
      fclose($Peer2);
      usleep(20000);

      yield new Assertion(description: 'check() detects the peer-closed parked connection as dead')
         ->expect($Pool->check($Connection2))
         ->to->be(false)
         ->assert();

      yield new Assertion(description: 'acquire() skips + drops the dead idle connection and yields null')
         ->expect($Pool->acquire() === null && $Pool->created === 0)
         ->to->be(true)
         ->assert();

      // @ Cleanup
      $Connection->close();
      fclose($Peer);
      fclose($Server);
   })
);
