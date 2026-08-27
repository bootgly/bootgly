<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV;


return new Test(
   description: 'KV(Redis): transport failures clear the pipeline and drop the connection',
   test: function () {
      /**
       * Opens a KV pool of one connection over a socketpair standing in for the
       * server. No live Redis is needed: every branch under test is a transport
       * failure, and a socketpair can be closed or corrupted on demand.
       *
       * @return array{KV, resource}
       */
      $open = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $KV = new KV([
            'driver'  => 'redis',
            'timeout' => 2.0,
            'secure'  => ['mode' => 'disable'],
            'pool'    => ['min' => 0, 'max' => 1],
         ]);
         $KV->Connection->attach($client);

         return [$KV, $server];
      };

      // # Peer close while reading — the head and its pipelined sibling both fail
      [$KV, $server] = $open();

      $First = $KV->command('GET', ['first']);
      $KV->advance($First);
      fread($server, 8192);

      $Second = $KV->command('GET', ['second']);
      $KV->advance($Second);
      fread($server, 8192);

      $Protocol = $First->Protocol;

      yield assert(
         assertion: $Protocol !== null && $Protocol->check(),
         description: 'Two commands are pipelined before the transport dies'
      );

      fclose($server);
      $KV->advance($First);

      yield assert(
         assertion: $First->finished && $First->error === 'Redis socket closed.'
            && $Second->finished && $Second->error === 'Redis socket closed.'
            && $First->quarantine && $Second->quarantine,
         description: 'A peer close fails the head and every pipelined sibling, and quarantines '
            . 'them so pool health can see it'
      );

      yield assert(
         assertion: $Protocol->check() === false
            && $KV->Connection->connected === false
            && is_resource($KV->Connection->socket) === false
            && $KV->Pool->busy === []
            && $KV->Pool->idle === []
            && $KV->Pool->created === 0,
         description: 'The pipeline empties and the pool drops the dead connection, so its slot '
            . 'is not held for the worker lifetime behind the check() gate'
      );

      // # Write failure — the peer is gone before the command is flushed
      [$KV, $server] = $open();
      fclose($server);

      $Write = $KV->command('SET', ['key', 'value']);
      $KV->advance($Write);

      yield assert(
         assertion: $Write->finished && $Write->error !== null && $Write->quarantine,
         description: 'A command written to a dead peer fails and quarantines, got: '
            . var_export($Write->error, true)
      );

      yield assert(
         assertion: $KV->Connection->connected === false && $KV->Pool->created === 0,
         description: 'A write failure drops the connection too'
      );

      // # Error reply — the server disagreed, but the session is perfectly fine
      [$KV, $server] = $open();

      $Refused = $KV->command('GET', ['key']);
      $KV->advance($Refused);
      fread($server, 8192);

      fwrite($server, "-WRONGTYPE Operation against a key holding the wrong kind of value\r\n");
      $KV->advance($Refused);

      yield assert(
         assertion: $Refused->finished
            && $Refused->error === 'WRONGTYPE Operation against a key holding the wrong kind of value'
            && $KV->Connection->connected === true
            && $KV->Pool->created === 1,
         description: 'An error REPLY fails only its own command and keeps the connection, so '
            . 'the teardown is reserved for transport failures'
      );

      fclose($server);

      // # Malformed frame — a desynchronised RESP stream cannot be resynchronised,
      //   so every later reply would resolve the wrong command
      [$KV, $server] = $open();

      $Broken = $KV->command('GET', ['key']);
      $KV->advance($Broken);
      fread($server, 8192);

      fwrite($server, "Z not a RESP type byte\r\n");
      $KV->advance($Broken);

      yield assert(
         assertion: $Broken->finished && $Broken->error === 'Unknown RESP type byte: Z'
            && $KV->Connection->connected === false
            && $KV->Pool->created === 0,
         description: 'A malformed frame drops the session instead of leaving the decoder '
            . 'holding a partial frame forever, got: ' . var_export($Broken->error, true)
      );

      if (is_resource($server)) {
         fclose($server);
      }

      // # A dead transport found while a sibling holds the write stream. The
      //   pool disconnects from outside the driver (Pool::release), so the
      //   readiness re-arm is where the death surfaces — and the command already
      //   queued behind it must not be left in flight forever.
      [$KV, $server] = $open();

      $Queued = $KV->command('GET', ['queued']);
      $KV->advance($Queued);
      fread($server, 8192);

      // ! A payload past the socketpair buffer keeps the write stream held
      $Held = $KV->command('SET', ['big', str_repeat('x', 4 * 1024 * 1024)]);
      $KV->advance($Held);

      $Blocked = $KV->command('GET', ['blocked']);
      $KV->advance($Blocked);

      yield assert(
         assertion: $Queued->state === OperationStates::Reading
            && $Held->write !== ''
            && $Blocked->state === OperationStates::Querying,
         description: 'One command is queued, one holds the write stream and one waits behind it'
      );

      $KV->Connection->disconnect();
      $KV->advance($Blocked);

      yield assert(
         assertion: $Blocked->error === 'Redis socket is not available.'
            && $Queued->finished && $Queued->error === 'Redis socket is not available.'
            && $Queued->quarantine,
         description: 'A death found at the readiness re-arm also fails the command queued '
            . 'behind it, instead of leaving it at error=NULL state=Reading forever; got '
            . var_export($Queued->error, true) . ' state ' . $Queued->state->name
      );

      if (is_resource($server)) {
         fclose($server);
      }

      // # A refused AUTH/SELECT preamble. The reply loop had already consumed
      //   the command's own reply from the same read, and returning there left
      //   the FIFO shifted by one for the rest of the connection's life — every
      //   later command resolving with its predecessor's value, reported as
      //   success. Driven over a real listening socket, because the preamble is
      //   only composed on the Connecting transition an attached socket skips.
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $listenError);

      if (is_resource($Listener)) {
         [$listenHost, $listenPort] = explode(':', stream_socket_get_name($Listener, false));
         stream_set_blocking($Listener, false);

         $KV = new KV([
            'driver'   => 'redis',
            'host'     => $listenHost,
            'port'     => (int) $listenPort,
            'timeout'  => 2.0,
            'secure'   => ['mode' => 'disable'],
            // ! Any numeric, non-zero index composes a SELECT preamble
            'database' => '20',
            'pool'     => ['min' => 0, 'max' => 1],
         ]);

         $Preamble = $KV->command('GET', ['alice']);
         $KV->advance($Preamble);

         $Peer = null;

         for ($attempt = 0; $attempt < 50 && $Peer === null; $attempt++) {
            $Accepted = @stream_socket_accept($Listener, 0.05);
            $Peer = is_resource($Accepted) ? $Accepted : null;
            $KV->advance($Preamble);
         }

         if (is_resource($Peer)) {
            stream_set_blocking($Peer, false);

            for ($attempt = 0; $attempt < 20; $attempt++) {
               $KV->advance($Preamble);
               usleep(10000);
            }

            fread($Peer, 65536);

            // ! The refusal and the command's own reply arrive in one read, so
            //   the decoder consumes both before the loop sees the first.
            fwrite($Peer, "-ERR DB index is out of range\r\n\$12\r\nALICE-SECRET\r\n");

            for ($attempt = 0; $attempt < 20 && $Preamble->finished === false; $attempt++) {
               $KV->advance($Preamble);
               usleep(10000);
            }

            yield assert(
               assertion: $Preamble->finished
                  && $Preamble->error === 'ERR DB index is out of range'
                  && $Preamble->response === null,
               description: 'A refused preamble fails the command with the server cause and '
                  . 'never resolves it with a value, got '
                  . var_export($Preamble->error, true) . ' / ' . var_export($Preamble->response, true)
            );

            yield assert(
               assertion: $KV->Connection->connected === false && $KV->Pool->created === 0,
               description: 'A refused preamble drops the session, so no later command can be '
                  . 'resolved against a reply queue shifted by the discarded one'
            );

            fclose($Peer);
         }

         fclose($Listener);
      }

      // # The shared socket dies between connect() and the readiness transition,
      //   which is what a co-located sibling's teardown does. transition()
      //   rejects a connection without a socket, so without a guard here the
      //   driver raises an InvalidArgumentException out of advance() instead of
      //   failing the operation.
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $listenError);

      if (is_resource($Listener)) {
         [$listenHost, $listenPort] = explode(':', stream_socket_get_name($Listener, false));
         stream_set_blocking($Listener, false);

         $KV = new KV([
            'driver'  => 'redis',
            'host'    => $listenHost,
            'port'    => (int) $listenPort,
            'timeout' => 2.0,
            'secure'  => ['mode' => 'disable'],
            'pool'    => ['min' => 0, 'max' => 1],
         ]);

         $Racing = $KV->command('GET', ['racing']);
         $KV->advance($Racing);

         yield assert(
            assertion: $Racing->state === OperationStates::Connecting,
            description: 'The command owns a connecting socket before the teardown'
         );

         // @ A sibling's abort() drops the shared transport mid-connect
         $KV->Connection->disconnect();

         $raised = null;

         try {
            $KV->advance($Racing);
         }
         catch (Throwable $Throwable) {
            $raised = $Throwable::class . ': ' . $Throwable->getMessage();
         }

         yield assert(
            assertion: $raised === null
               && $Racing->finished
               && $Racing->error === 'Redis socket is not available.',
            description: 'A socket lost mid-connect fails the operation instead of raising out '
               . 'of advance(), got ' . var_export($raised, true) . ' / '
               . var_export($Racing->error, true)
         );

         fclose($Listener);
      }

      // # Recovery — the pool serves the next command over a fresh transport
      [$KV, $server] = $open();

      $Dead = $KV->command('GET', ['gone']);
      $KV->advance($Dead);
      fread($server, 8192);
      fclose($server);
      $KV->advance($Dead);

      yield assert(
         assertion: $Dead->finished && $KV->Pool->created === 0,
         description: 'The pool is empty after the transport dies'
      );

      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);
      $KV->Connection->attach($client);

      $Next = $KV->command('GET', ['again']);
      $KV->advance($Next);

      yield assert(
         assertion: $Next->finished === false
            && fread($server, 8192) === "*2\r\n\$3\r\nGET\r\n\$5\r\nagain\r\n",
         description: 'A later command reaches the wire over the replacement transport, so the '
            . 'failure was transient rather than terminal for the worker'
      );

      fclose($server);
   }
);
