<?php

use function fclose;
use function stream_socket_accept;
use function stream_socket_client;
use function stream_socket_get_name;
use function stream_socket_server;

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Connections\Connection;
use Bootgly\WPI\Interfaces\TCP_Client_CLI\Pool;


return new Specification(
   description: 'It should multiplex acquisitions on one connection up to its stream capacity (h2) and honor cap()',
   test: new Assertions(Case: function (): Generator {
      // ! Boot the client statics (Client::$Event) that Connection->close() uses
      new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      // ! A real established loopback pair
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      $address = stream_socket_get_name($Server, false);
      $Socket = stream_socket_client("tcp://{$address}");
      $Peer = stream_socket_accept($Server);
      $Connection = new Connection($Socket);

      $Pool = new Pool(['max' => 1]);

      // @ attach: one h2-like connection carrying up to 2 concurrent streams
      $Pool->attach($Connection, capacity: 2);

      $First = $Pool->acquire();

      yield new Assertion(description: 'the first acquire() returns the multiplexed connection')
         ->expect($First === $Connection)
         ->to->be(true)
         ->assert();

      $Second = $Pool->acquire();

      yield new Assertion(description: 'the second acquire() co-locates on the SAME connection (spare h2 stream)')
         ->expect($Second === $Connection)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the multiplexed connection stays busy, not idle')
         ->expect(isSet($Pool->busy[$Connection->id]) && isSet($Pool->idle[$Connection->id]) === false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'a third acquire() exhausts the 2-stream capacity')
         ->expect($Pool->acquire() === null)
         ->to->be(true)
         ->assert();

      // @ release one of the two in-flight streams
      $Pool->release($Connection);

      yield new Assertion(description: 'after one release the connection stays busy (one stream still in flight)')
         ->expect(isSet($Pool->busy[$Connection->id]) && isSet($Pool->idle[$Connection->id]) === false)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'the freed stream slot is immediately acquirable again')
         ->expect($Pool->acquire() === $Connection)
         ->to->be(true)
         ->assert();

      // @ drain both in-flight streams
      $Pool->release($Connection);
      $Pool->release($Connection);

      yield new Assertion(description: 'releasing the last stream parks the connection idle')
         ->expect(isSet($Pool->idle[$Connection->id]) && isSet($Pool->busy[$Connection->id]) === false)
         ->to->be(true)
         ->assert();

      // ---

      // @ cap() lowers the capacity to 1 — co-location is no longer allowed
      $Pool->cap($Connection, 1);

      yield new Assertion(description: 'the capped connection is still acquirable once')
         ->expect($Pool->acquire() === $Connection)
         ->to->be(true)
         ->assert();

      yield new Assertion(description: 'cap(1) blocks a second concurrent acquire')
         ->expect($Pool->acquire() === null)
         ->to->be(true)
         ->assert();

      // @ Cleanup
      $Pool->release($Connection);
      $Connection->close();
      fclose($Peer);
      fclose($Server);
   })
);
