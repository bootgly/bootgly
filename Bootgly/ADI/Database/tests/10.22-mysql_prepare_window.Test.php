<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


return new Test(
   description: 'MySQL: a sibling created inside the prepare window never prepares the statement again',
   test: function () {
      $connect = function (int $statements = 256): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config([
            'driver' => 'mysql',
            'statements' => $statements,
            'secure' => ['mode' => 'disable'],
         ]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new MySQL($Config, $Connection), $Connection, $server];
      };

      // ! Split the client stream into command bytes — counting raw needles
      //   would also match payload data that happens to carry the same byte.
      $commands = function (string $wire): array {
         $commands = [];
         $offset = 0;

         while ($offset + 4 <= strlen($wire)) {
            $header = unpack('V', substr($wire, $offset, 3) . "\x00");
            $length = $header[1] ?? 0;
            $payload = substr($wire, $offset + 4, $length);
            $commands[] = $payload[0] ?? '';
            $offset += 4 + $length;
         }

         return $commands;
      };

      $prepared = fn (int $id): string =>
         "\x00" . pack('V', $id) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);

      $SQL = 'SELECT ? AS v';


      // # The window: two operations created before either reaches the wire
      [$MySQL, $Connection, $server] = $connect();

      $Owner = $MySQL->query($SQL, [1]);
      $Sibling = $MySQL->query($SQL, [2]);

      yield assert(
         assertion: $Sibling->write === '',
         description: 'A sibling created inside the prepare window encodes no command of its own'
      );

      // @ Drive the owner: COM_STMT_PREPARE out, prepare-OK back, EXECUTE out.
      $MySQL->advance($Owner);
      fwrite($server, $MySQL->Encoder->frame($prepared(77), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Owner);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Owner);

      // @ The sibling is the head now — it re-derives against the warm cache.
      $MySQL->advance($Sibling);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Sibling);

      $wire = (string) fread($server, 65536);
      $written = $commands($wire);

      yield assert(
         assertion: count(array_keys($written, Encoder::COM_STMT_PREPARE, true)) === 1,
         description: 'Only one COM_STMT_PREPARE reaches the server for both operations'
      );

      yield assert(
         assertion: $written === [
            Encoder::COM_STMT_PREPARE,
            Encoder::COM_STMT_EXECUTE,
            Encoder::COM_STMT_EXECUTE,
         ],
         description: 'The sibling reaches the wire as a COM_STMT_EXECUTE'
      );

      yield assert(
         assertion: str_contains($wire, Encoder::COM_STMT_EXECUTE . pack('V', 77))
            && $MySQL->statements[$SQL]['statement'] === 77,
         description: 'The sibling executes the statement id the prepare-OK returned'
      );

      yield assert(
         assertion: $Owner->finished && $Owner->error === null
            && $Sibling->finished && $Sibling->error === null,
         description: 'Both operations resolve without an error'
      );

      $Ledger = new ReflectionProperty(MySQL::class, 'pending');

      yield assert(
         assertion: $Ledger->getValue($MySQL) === [],
         description: 'The pending ledger drains once the prepare-OK lands'
      );

      fclose($server);
      $Connection->disconnect();


      // # Liveness: a failed prepare must never strand the sibling
      [$MySQL, $Connection, $server] = $connect();

      $Owner = $MySQL->query($SQL, [1]);
      $Sibling = $MySQL->query($SQL, [2]);

      $MySQL->advance($Owner);
      fread($server, 65536);

      $error = "\xFF" . pack('v', 1064) . '#42000' . 'You have an error in your SQL syntax';
      fwrite($server, $MySQL->Encoder->frame($error, 1));
      $MySQL->advance($Owner);

      yield assert(
         assertion: $Owner->finished && $Owner->error !== null
            && $Ledger->getValue($MySQL) === [],
         description: 'A failed prepare fails its own operation and clears the ledger'
      );

      $MySQL->advance($Sibling);
      $written = $commands((string) fread($server, 65536));

      yield assert(
         assertion: $written === [Encoder::COM_STMT_PREPARE],
         description: 'The sibling prepares the statement itself once the owner is gone'
      );

      fclose($server);
      $Connection->disconnect();


      // # The overwrite backstop: a prepare-OK that displaces a cached id
      [$MySQL, $Connection, $server] = $connect();

      $Displaced = $MySQL->query($SQL, [1]);
      $MySQL->advance($Displaced);
      fread($server, 65536);

      // ! Stand in for a cache entry this prepare-OK is about to displace —
      //   the window fix makes it unreachable through the public API, and the
      //   site must stay safe on its own anyway.
      $Cache = new ReflectionProperty(MySQL::class, 'statements');
      $Cache->setValue($MySQL, [
         $SQL => ['statement' => 77, 'parameters' => 1, 'columns' => 0],
         'SELECT other' => ['statement' => 90, 'parameters' => 0, 'columns' => 0],
      ]);

      fwrite($server, $MySQL->Encoder->frame($prepared(88), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Displaced);

      $wire = (string) fread($server, 65536);

      yield assert(
         assertion: str_contains($wire, Encoder::COM_STMT_CLOSE . pack('V', 77)),
         description: 'A prepare-OK closes the statement id it displaces'
      );

      yield assert(
         assertion: $MySQL->statements[$SQL]['statement'] === 88
            && isset($MySQL->statements['SELECT other']),
         description: 'An overwrite costs no unrelated cache entry'
      );

      fclose($server);
      $Connection->disconnect();


      // # Control: the LRU still closes the statement it evicts
      [$MySQL, $Connection, $server] = $connect(statements: 1);

      $First = $MySQL->query($SQL, [1]);
      $MySQL->advance($First);
      fwrite($server, $MySQL->Encoder->frame($prepared(11), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($First);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($First);
      fread($server, 65536);

      $Second = $MySQL->query('SELECT ? AS w', [2]);
      $MySQL->advance($Second);
      fwrite($server, $MySQL->Encoder->frame($prepared(12), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Second);

      $wire = (string) fread($server, 65536);

      yield assert(
         assertion: str_contains($wire, Encoder::COM_STMT_CLOSE . pack('V', 11))
            && array_keys($MySQL->statements) === ['SELECT ? AS w'],
         description: 'The statement cache still closes on the wire what the LRU evicts'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
