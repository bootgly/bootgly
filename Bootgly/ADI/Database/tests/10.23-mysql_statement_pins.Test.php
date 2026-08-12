<?php


use ReflectionProperty;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Encoder;


return new Test(
   description: 'MySQL: a statement id frozen in an unsent command is never closed out from under it',
   test: function () {
      $connect = function (int $statements): array {
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

      // ! Split the client stream into (command byte, payload) pairs. A raw
      //   substring search would also match parameter data carrying those bytes.
      $commands = function (string $wire): array {
         $commands = [];
         $offset = 0;

         while ($offset + 4 <= strlen($wire)) {
            $header = unpack('V', substr($wire, $offset, 3) . "\x00");
            $length = $header[1] ?? 0;
            $payload = substr($wire, $offset + 4, $length);
            $commands[] = [$payload[0] ?? '', substr($payload, 1, 4)];
            $offset += 4 + $length;
         }

         return $commands;
      };

      // ! True when a COM_STMT_CLOSE for an id is followed by a COM_STMT_EXECUTE
      //   for that same id — the decapitation this spec exists to forbid.
      $decapitates = function (array $commands): bool {
         $closed = [];

         foreach ($commands as [$command, $id]) {
            if ($command === Encoder::COM_STMT_CLOSE) {
               $closed[$id] = true;
            }
            elseif ($command === Encoder::COM_STMT_EXECUTE && isset($closed[$id])) {
               return true;
            }
         }

         return false;
      };

      $prepared = fn (int $id): string =>
         "\x00" . pack('V', $id) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);

      // @ Drive one operation from a fresh cache to a cached id, leaving the
      //   connection warm and the statement cached.
      $warm = function (MySQL $MySQL, $server, string $sql, int $id) use ($prepared, $definition, $eof, $ok) {
         $Operation = $MySQL->query($sql, [1]);
         $MySQL->advance($Operation);
         fwrite($server, $MySQL->Encoder->frame($prepared($id), 1));
         fwrite($server, $MySQL->Encoder->frame($definition, 2));
         fwrite($server, $MySQL->Encoder->frame($eof, 3));
         $MySQL->advance($Operation);
         fwrite($server, $MySQL->Encoder->frame($ok, 1));
         $MySQL->advance($Operation);

         return $Operation;
      };

      $SQL = 'UPDATE t SET n = ? WHERE id = 1';
      $Other = 'UPDATE t SET m = ? WHERE id = 2';


      // # discard(): a completion closes the id while a sibling holds it
      [$MySQL, $Connection, $server] = $connect(statements: 0);

      // @ Drive the owner only as far as its prepare-OK: the cache holds the id
      //   but the command has not completed, so discard() has not run yet. That
      //   post-prepare-OK/pre-completion window is where the defect lives — a
      //   sibling created before it opens takes the harmless PREPARE path.
      $Owner = $MySQL->query($SQL, [1]);
      $MySQL->advance($Owner);
      fwrite($server, $MySQL->Encoder->frame($prepared(7), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Owner);
      fread($server, 65536);

      $Sibling = $MySQL->query($SQL, [2]);

      yield assert(
         assertion: ($Sibling->write[4] ?? '') === Encoder::COM_STMT_EXECUTE
            && substr($Sibling->write, 5, 4) === pack('V', 7),
         description: 'A sibling created on a warm cache freezes the server statement id'
      );

      // @ The owner completes — discard() wants to close the id it just used.
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Owner);

      $MySQL->advance($Sibling);
      $written = $commands((string) fread($server, 65536));

      yield assert(
         assertion: $decapitates($written) === false,
         description: 'No COM_STMT_CLOSE is written ahead of a frozen COM_STMT_EXECUTE for that id'
      );

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Sibling);

      yield assert(
         assertion: $Sibling->finished && $Sibling->error === null,
         description: 'The sibling reaches the server and resolves'
      );

      $Held = new ReflectionProperty(MySQL::class, 'Holders');

      yield assert(
         assertion: $Held->getValue($MySQL) === [],
         description: 'A holder is released once its bytes are on the wire'
      );

      fclose($server);
      $Connection->disconnect();


      // # The LRU picks an unheld victim instead of the held one
      [$MySQL, $Connection, $server] = $connect(statements: 2);

      $warm($MySQL, $server, $SQL, 11);
      $warm($MySQL, $server, $Other, 12);
      fread($server, 65536);

      // @ Freeze the OLDEST entry (id 11) — the one the LRU would evict first.
      $Frozen = $MySQL->query($SQL, [4]);

      // @ A third distinct SQL overflows the cache and forces an eviction.
      $Overflow = $MySQL->query('UPDATE t SET k = ? WHERE id = 3', [5]);
      $MySQL->advance($Overflow);
      fwrite($server, $MySQL->Encoder->frame($prepared(13), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Overflow);

      $written = $commands((string) fread($server, 65536));
      $closes = [];

      foreach ($written as [$command, $id]) {
         if ($command === Encoder::COM_STMT_CLOSE) {
            $closes[] = unpack('V', $id)[1] ?? 0;
         }
      }

      yield assert(
         assertion: in_array(11, $closes, true) === false,
         description: 'The LRU never evicts the statement id a pending command froze'
      );

      yield assert(
         assertion: $closes === [12],
         description: 'The LRU evicts the first unheld entry instead'
      );

      fclose($server);
      $Connection->disconnect();


      // # A queued close never splices into a command already on the wire
      [$MySQL, $Connection, $server] = $connect(statements: 1);

      $warm($MySQL, $server, $SQL, 7);
      fread($server, 1 << 20);

      // @ This sibling freezes id 7 and is never advanced.
      $Sibling = $MySQL->query($SQL, [2]);

      // @ A 300 KB parameter cannot leave the socket in one write, so the head
      //   re-enters advance() holding the remainder of a packet already begun.
      $Big = $MySQL->query('UPDATE t SET blob = ? WHERE id = 9', [str_repeat('x', 300000)]);
      $MySQL->advance($Big);
      fread($server, 1 << 20);
      fwrite($server, $MySQL->Encoder->frame($prepared(8), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Big);

      yield assert(
         assertion: $Big->write !== '',
         description: 'A 300 KB command really does leave a remainder to re-enter on'
      );

      $wire = '';
      $drain = function () use (&$wire, $server): void {
         while (($chunk = fread($server, 1 << 16)) !== '' && $chunk !== false) {
            $wire .= $chunk;
         }
      };
      $drain();

      // @ Release the holder, then let the head finish writing its remainder.
      $Sibling->fail('caller gave up');

      for ($i = 0; $i < 200 && $Big->write !== ''; $i++) {
         $MySQL->advance($Big);
         $drain();
      }

      $header = unpack('V', substr($wire, 0, 3) . "\x00");
      $length = $header[1] ?? 0;

      yield assert(
         assertion: strpos(substr($wire, 4, $length), Encoder::COM_STMT_CLOSE . pack('V', 7)) === false,
         description: 'A deferred close is never spliced into a packet already on the wire'
      );

      fclose($server);
      $Connection->disconnect();


      // # Control: an unheld id is still closed on the wire
      [$MySQL, $Connection, $server] = $connect(statements: 1);

      $warm($MySQL, $server, $SQL, 21);
      fread($server, 65536);

      // @ The close rides the prepare-OK path, so the evictor has to get one.
      $Evictor = $MySQL->query($Other, [6]);
      $MySQL->advance($Evictor);
      fwrite($server, $MySQL->Encoder->frame($prepared(22), 1));
      fwrite($server, $MySQL->Encoder->frame($definition, 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      $MySQL->advance($Evictor);

      $written = $commands((string) fread($server, 65536));
      $closes = [];

      foreach ($written as [$command, $id]) {
         if ($command === Encoder::COM_STMT_CLOSE) {
            $closes[] = unpack('V', $id)[1] ?? 0;
         }
      }

      yield assert(
         assertion: $closes === [21],
         description: 'An id nobody holds is still closed when the LRU evicts it'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
