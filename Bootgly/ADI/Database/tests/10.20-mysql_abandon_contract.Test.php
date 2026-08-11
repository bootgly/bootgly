<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Test(
   description: 'MySQL: an abandoned operation is reconciled on the wire, never revived',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new MySQL($Config, $Connection), $Connection, $server];
      };
      $column = fn (string $name, int $type): string =>
         "\x03def\x02db\x05table\x05table"
         . chr(strlen($name)) . $name
         . chr(strlen($name)) . $name
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr($type) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $expired = 'Database operation timed out after 1 seconds.';

      // # A — the head drains behind a sibling and is never revived
      [$MySQL, $Connection, $server] = $connect();
      $Head = $MySQL->query('SELECT secret FROM tenant_a');
      $Sibling = $MySQL->query('SELECT id FROM tenant_b');
      $MySQL->advance($Head);
      $MySQL->advance($Sibling);
      fread($server, 8192);

      $Head->fail($expired);
      $MySQL->abandon($Head);

      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame($column('secret', Decoder::TYPE_VAR_STRING), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\x09TOPSECRET", 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Sibling);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Head->state === OperationStates::Failed
            && $Head->error === $expired
            && $Head->Result === null
            && $Head->rows === []
            && $Head->columns === [],
         description: 'The abandoned operation is never revived by its own late response'
      );

      yield assert(
         assertion: $MySQL->drain() === [] && $Connection->connected,
         description: 'A drained response releases nothing twice and keeps the session alive'
      );

      yield assert(
         assertion: str_contains($wire, 'SELECT id FROM tenant_b') && $Sibling->rows === [],
         description: 'The sibling takes the wire with its own command'
      );

      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame($column('id', Decoder::TYPE_LONG), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\x012", 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Sibling);

      yield assert(
         assertion: $Sibling->rows === [['id' => 2]] && $MySQL->check() === false,
         description: 'The sibling resolves from its own result set and empties the queue'
      );

      fclose($server);
      $Connection->disconnect();

      // # B — an abandoned prepare is never executed
      [$MySQL, $Connection, $server] = $connect();
      $Head = $MySQL->query('UPDATE a SET x = ?', [1]);
      $Sibling = $MySQL->query('SELECT id FROM tenant_b');
      $MySQL->advance($Head);
      $MySQL->advance($Sibling);
      fread($server, 8192);

      $Head->fail($expired);
      $MySQL->abandon($Head);

      $prepared = "\x00" . pack('V', 77) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($column('x', Decoder::TYPE_LONGLONG), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Sibling);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: str_contains($wire, "\x17" . pack('V', 77)) === false
            && str_contains($wire, 'SELECT id FROM tenant_b'),
         description: 'An abandoned prepared statement is never executed on the wire'
      );

      yield assert(
         assertion: $Head->error === $expired && $Head->prepared === false && $Connection->connected,
         description: 'The abandoned operation carries no state from the prepare it left behind'
      );

      fclose($server);
      $Connection->disconnect();

      // # C — nobody left to pump the response: the session is dropped
      [$MySQL, $Connection, $server] = $connect();
      $Alone = $MySQL->query('SELECT secret FROM tenant_a');
      $MySQL->advance($Alone);
      fread($server, 8192);

      $Alone->fail($expired);
      $MySQL->abandon($Alone);

      yield assert(
         assertion: $Connection->connected === false && $MySQL->check() === false,
         description: 'An abandoned command with no reader tears the session down'
      );

      yield assert(
         assertion: $Alone->error === $expired && $Alone->quarantine === false,
         description: 'Tearing the session down neither rewrites nor penalizes the abandoned operation'
      );

      fclose($server);

      // # D — a queued sibling that never wrote leaves the FIFO
      [$MySQL, $Connection, $server] = $connect();
      $Head = $MySQL->query('UPDATE a SET x = 1');
      $Queued = $MySQL->query('UPDATE b SET x = 2');
      $MySQL->advance($Head);
      $MySQL->advance($Queued);
      fread($server, 8192);

      $Queued->fail($expired);
      $MySQL->abandon($Queued);

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Head);

      yield assert(
         assertion: $Head->finished && $Head->error === null && $MySQL->check() === false,
         description: 'An abandoned queued sibling stops holding the connection busy'
      );

      yield assert(
         assertion: fread($server, 8192) === '' && $Connection->connected,
         description: 'The abandoned sibling command never reaches the wire'
      );

      fclose($server);
      $Connection->disconnect();

      // # E — the last reader of an abandoned head is abandoned too
      [$MySQL, $Connection, $server] = $connect();
      $Head = $MySQL->query('SELECT secret FROM tenant_a');
      $Sibling = $MySQL->query('SELECT id FROM tenant_b');
      $MySQL->advance($Head);
      $MySQL->advance($Sibling);
      fread($server, 8192);

      $Head->fail($expired);
      $MySQL->abandon($Head);
      $Sibling->fail($expired);
      $MySQL->abandon($Sibling);

      yield assert(
         assertion: $Connection->connected === false && $MySQL->check() === false,
         description: 'Losing the last reader of an abandoned response drops the session'
      );

      fclose($server);

      // # F — an operation that never reached this wire is inert
      [$MySQL, $Connection, $server] = $connect();
      $Head = $MySQL->query('UPDATE a SET x = 1');
      $MySQL->advance($Head);
      fread($server, 8192);

      $Loose = $MySQL->query('UPDATE c SET x = 3');
      $Loose->fail($expired);
      $MySQL->abandon($Loose);

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Head);

      yield assert(
         assertion: $Head->finished && $Head->error === null && $Connection->connected,
         description: 'Abandoning an operation this connection never queued changes nothing'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
