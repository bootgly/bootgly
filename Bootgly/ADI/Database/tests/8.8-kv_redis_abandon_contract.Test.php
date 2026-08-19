<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV\Drivers\Redis;


return new Test(
   description: 'KV(Redis): an abandoned command is reconciled on the wire, never revived',
   test: function () {
      /**
       * Builds a Redis driver over a socketpair standing in for the server.
       *
       * @return array{Redis, Connection, resource}
       */
      $connect = static function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'redis', 'secure' => ['mode' => 'disable']]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new Redis($Config, $Connection), $Connection, $server];
      };
      $expired = 'Database operation timed out after 1 seconds.';

      // # A — the abandoned slot still owes a reply, and Redis answers in order.
      //   Dropping the slot would hand the sibling the abandoned command's value.
      [$Redis, $Connection, $server] = $connect();

      $Head = $Redis->command('GET', ['tenant-a']);
      $Sibling = $Redis->command('GET', ['tenant-b']);
      $Redis->advance($Head);
      $Redis->advance($Sibling);
      fread($server, 8192);

      $Head->fail($expired);
      $Redis->abandon($Head);

      fwrite($server, "\$8\r\nSECRET-A\r\n\$8\r\nSECRET-B\r\n");
      $Redis->advance($Sibling);

      yield assert(
         assertion: $Sibling->finished && $Sibling->response === 'SECRET-B',
         description: 'The surviving sibling resolves with its OWN reply, not the abandoned '
            . "command's; got " . var_export($Sibling->response, true)
      );

      yield assert(
         assertion: $Head->state === OperationStates::Failed
            && $Head->error === $expired
            && $Head->response === null,
         description: 'The abandoned command is never revived by its own late reply'
      );

      yield assert(
         assertion: $Connection->connected === true && is_resource($Connection->socket),
         description: 'A session with a reader left to drain the abandoned reply is kept'
      );

      fclose($server);

      // # A2 — the same shape after a fallback pool retries the abandoned
      //   command. retry() clears `finished`, so leaving the operation in the
      //   FIFO would make it eligible again and a late reply from the connection
      //   it was taken off would resolve work another pool now owns.
      [$Redis, $Connection, $server] = $connect();

      $Retried = $Redis->command('GET', ['tenant-a']);
      $Reader = $Redis->command('GET', ['tenant-b']);
      $Redis->advance($Retried);
      $Redis->advance($Reader);
      fread($server, 8192);

      $Retried->fail($expired);
      $Redis->abandon($Retried);

      // @ What Pool::fallback() does before re-assigning to a replica pool
      $Retried->retry();

      fwrite($server, "\$8\r\nSECRET-A\r\n\$8\r\nSECRET-B\r\n");
      $Redis->advance($Reader);

      yield assert(
         assertion: $Retried->response === null
            && $Retried->finished === false
            && $Retried->state === OperationStates::Pending,
         description: 'A retried command is not revived by the reply owed to the connection it '
            . 'was taken off; got ' . var_export($Retried->response, true) . ' / '
            . $Retried->state->name
      );

      yield assert(
         assertion: $Reader->response === 'SECRET-B',
         description: 'and the reader still gets its own reply, got '
            . var_export($Reader->response, true)
      );

      fclose($server);

      // # B — nothing is left to pump the socket, so the reply can never be
      //   drained and the session cannot be handed on
      [$Redis, $Connection, $server] = $connect();

      $Lonely = $Redis->command('GET', ['lonely']);
      $Redis->advance($Lonely);
      fread($server, 8192);

      $Lonely->fail($expired);
      $Redis->abandon($Lonely);

      yield assert(
         assertion: $Redis->check() === false
            && $Connection->connected === false
            && is_resource($Connection->socket) === false,
         description: 'An abandoned command with no reader left drops the session instead of '
            . 'leaving an undrainable reply on a recycled connection'
      );

      fclose($server);

      // # C — POOL-4's shape: the deadline elapses mid-write, so the server is
      //   reading a bulk string that never ends and every later command handed
      //   this connection is consumed as its payload
      [$Redis, $Connection, $server] = $connect();

      $Partial = $Redis->command('SET', ['big', str_repeat('x', 4 * 1024 * 1024)]);
      $Redis->advance($Partial);

      yield assert(
         assertion: $Partial->write !== '' && $Redis->check() === false,
         description: 'The command is still going out and has not joined the FIFO'
      );

      $Partial->fail($expired);
      $Redis->abandon($Partial);

      yield assert(
         assertion: $Connection->connected === false
            && is_resource($Connection->socket) === false,
         description: 'A command abandoned mid-write drops the session rather than handing the '
            . 'connection back with an unfinished frame on it'
      );

      fclose($server);

      // # D — nothing was ever written for it, so nothing is owed
      [$Redis, $Connection, $server] = $connect();

      $Untouched = $Redis->command('GET', ['untouched']);
      $Untouched->fail($expired);
      $Redis->abandon($Untouched);

      yield assert(
         assertion: $Connection->connected === true
            && is_resource($Connection->socket)
            && $Redis->check() === false,
         description: 'A command that never reached the wire leaves the session alone'
      );

      fclose($server);
   }
);
