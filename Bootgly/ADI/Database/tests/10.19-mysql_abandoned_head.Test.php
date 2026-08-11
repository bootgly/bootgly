<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Test(
   description: 'MySQL: the pipeline head only leaves the wire on a protocol sync point',
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

      // # A multi-result command expired by the pool keeps the wire
      [$MySQL, $Connection, $server] = $connect();
      $Tenant = $MySQL->query('CALL tenant_a_report()');
      $Victim = $MySQL->query('SELECT id FROM tenant_b');

      $MySQL->advance($Tenant);
      $MySQL->advance($Victim);
      fread($server, 8192);

      // @ The pool expires the head out of band — exactly what Pool::advance()
      //   does on an elapsed deadline, with the server still answering.
      $Tenant->fail('Database operation timed out after 1 seconds.');

      // @ The tenant response: an OK announcing more results, then the report.
      $more = "\x00\x00\x00" . pack('v', Capabilities::STATUS_MORE_RESULTS) . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($more, 1));
      fwrite($server, $MySQL->Encoder->frame("\x01", 2));
      fwrite($server, $MySQL->Encoder->frame($column('secret', Decoder::TYPE_VAR_STRING), 3));
      fwrite($server, $MySQL->Encoder->frame($eof, 4));
      fwrite($server, $MySQL->Encoder->frame("\x09TOPSECRET", 5));
      fwrite($server, $MySQL->Encoder->frame($eof, 6));

      $MySQL->advance($Victim);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Victim->rows === [] && $Victim->columns === [],
         description: 'The expired head result set never reaches the co-located sibling'
      );

      yield assert(
         assertion: $Victim->finished === false && $Victim->Result === null && $Victim->status === '',
         description: 'The sibling is not resolved by the terminal packet of another command'
      );

      yield assert(
         assertion: str_contains($wire, 'SELECT id FROM tenant_b'),
         description: 'The sibling writes its own command once the head is retired'
      );

      yield assert(
         assertion: $MySQL->check(),
         description: 'The sibling still owns the connection while its answer is pending'
      );

      // @ Its own response then hydrates it from a clean result-set state.
      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame($column('id', Decoder::TYPE_LONG), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\x012", 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Victim);

      yield assert(
         assertion: $Victim->rows === [['id' => 2]] && $Victim->columns === ['id'],
         description: 'The sibling hydrates from its own result set'
      );

      yield assert(
         assertion: $MySQL->check() === false,
         description: 'The pipeline empties once every queued command has been answered'
      );

      fclose($server);
      $Connection->disconnect();

      // # A head expired between the prepare-OK and its definitions
      [$MySQL, $Connection, $server] = $connect();
      $First = $MySQL->query('UPDATE a SET x = ?', [1]);
      $Second = $MySQL->query('SELECT id FROM tenant_b');

      $MySQL->advance($First);
      $MySQL->advance($Second);
      fread($server, 8192);

      $First->fail('Database operation timed out after 1 seconds.');

      // @ prepare-OK for one parameter and no columns: a definition and an EOF.
      $prepared = "\x00" . pack('V', 77) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      fwrite($server, $MySQL->Encoder->frame($prepared, 1));
      fwrite($server, $MySQL->Encoder->frame($column('x', Decoder::TYPE_LONGLONG), 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));

      $MySQL->advance($Second);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Second->columns === [] && $Second->finished === false,
         description: 'Statement definition packets never describe the co-located sibling'
      );

      yield assert(
         assertion: str_contains($wire, 'SELECT id FROM tenant_b')
            && str_contains($wire, "\x17" . pack('V', 77)) === false,
         description: 'An abandoned prepare is never re-armed as a COM_STMT_EXECUTE'
      );

      fclose($server);
      $Connection->disconnect();

      // # Anti-hang guards — the ordinary terminals still retire the head
      [$MySQL, $Connection, $server] = $connect();
      $Live = $MySQL->query('UPDATE a SET x = 1');
      $MySQL->advance($Live);
      fread($server, 8192);
      fwrite($server, $MySQL->Encoder->frame("\x00\x01\x00" . pack('v', 0) . pack('v', 0), 1));
      $MySQL->advance($Live);

      yield assert(
         assertion: $Live->finished && $Live->error === null && $MySQL->check() === false,
         description: 'A terminal OK still retires the head'
      );

      $Broken = $MySQL->query('UPDATE a SET x = 2');
      $MySQL->advance($Broken);
      fread($server, 8192);
      fwrite($server, $MySQL->Encoder->frame("\xFF" . pack('v', 1064) . '#42000syntax', 1));
      $MySQL->advance($Broken);

      yield assert(
         assertion: $Broken->error === '1064: syntax' && $MySQL->check() === false,
         description: 'A terminal ERR still retires the head'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
