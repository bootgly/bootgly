<?php

use Bootgly\ACI\Tests\Assertion;
use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Interfaces\TCP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Events;


return new Test(
   description: 'It should adopt a host reactor without ever owning, destroying or restarting it',
   test: new Assertions(Case: function (): Generator {
      // ! A "host" reactor (stands in for a server worker's Select)
      $Host = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);

      // ! Client subclass exposing the protected halt() for the R8 probe
      $make = function (int $mode) {
         return new class($mode) extends TCP_Client_CLI {
            public function probe (): void
            {
               $this->halt();
            }
         };
      };

      // @ Adoption
      $Emb = $make(TCP_Client_CLI::MODE_EMBEDDED);
      $Emb->react($Host->Event);

      yield new Assertion(
         description: 'react() adopts the host reactor and drops ownership',
         fallback: 'Adoption did not take the host reactor / release ownership!'
      )
         ->expect($Emb->Event === $Host->Event && $Emb->owned === false)
         ->to->be(true)
         ->assert();

      // @ R8 — halt() on an adopted reactor must never destroy it
      $Emb->probe();

      yield new Assertion(
         description: 'halt() never destroys an adopted reactor',
         fallback: 'An idle embedded client destroyed the HOST reactor!'
      )
         ->expect($Host->Event->loop)
         ->to->be(true)
         ->assert();

      // @ Control — an owned client still halts its own reactor
      $Own = $make(TCP_Client_CLI::MODE_TEST);
      $Own->probe();

      yield new Assertion(
         description: 'halt() still destroys an owned reactor',
         fallback: 'The owned-mode halt() contract changed!'
      )
         ->expect($Own->Event->loop)
         ->to->be(false)
         ->assert();

      // @ start() on an adopted reactor refuses to run
      $started = null;
      try {
         $Emb->start();
      }
      catch (LogicException) {
         $started = 'refused';
      }

      yield new Assertion(
         description: 'start() throws on an adopted reactor',
         fallback: 'Event-driven start() ran on an adopted reactor!'
      )
         ->expect($started)
         ->to->be('refused')
         ->assert();

      // @ on() (event-driven mode) is barred after adoption
      $H = new HTTP_Client_CLI(HTTP_Client_CLI::MODE_EMBEDDED);
      $H->react($Host->Event);
      $eventDriven = null;
      try {
         $H->on(Events::ResponseReceive, static function (): void {});
      }
      catch (LogicException) {
         $eventDriven = 'refused';
      }

      yield new Assertion(
         description: 'on() throws on an adopted-reactor client',
         fallback: 'An embedded client entered event-driven mode!'
      )
         ->expect($eventDriven)
         ->to->be('refused')
         ->assert();

      // @ react() after a live connection refuses
      $Server = stream_socket_server('tcp://127.0.0.1:0');
      $address = stream_socket_get_name($Server, false);
      $Live = new TCP_Client_CLI(TCP_Client_CLI::MODE_TEST);
      [$host, $port] = explode(':', $address);
      $Live->configure(host: $host, port: (int) $port);
      $Socket = $Live->connect();
      $Peer = stream_socket_accept($Server);
      $adopted = null;
      try {
         $Live->react($Host->Event);
      }
      catch (LogicException) {
         $adopted = 'refused';
      }

      yield new Assertion(
         description: 'react() throws once a connection is open',
         fallback: 'A client with live sockets swapped reactors!'
      )
         ->expect($adopted)
         ->to->be('refused')
         ->assert();

      // @ Cleanup the live pair
      foreach ($Live->Connections->Connections as $Connection) {
         $Connection->close();
      }
      fclose($Peer);
      fclose($Server);

      // @ The reactor is not reentrant: a nested loop() fails loud (last —
      //   this probe ends by destroying the Host reactor)
      $caught = false;
      $Event = $Host->Event;
      $Event->defer(microtime(true), function () use ($Event, &$caught): void {
         try {
            $Event->loop();
         }
         catch (LogicException) {
            $caught = true;
         }

         $Event->destroy();
      });
      $Event->loop();

      yield new Assertion(
         description: 'A nested loop() throws instead of killing the outer loop',
         fallback: 'The reactor accepted a reentrant loop()!'
      )
         ->expect($caught)
         ->to->be(true)
         ->assert();
   })
);
