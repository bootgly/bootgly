<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: LRU and an older runtime error preserve a newer Parse owner',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $Config = new Config(['driver' => 'pgsql', 'statements' => 1]);
      $Connection = new Connection($Config);
      $Connection->attach($client);
      $PostgreSQL = new PostgreSQL($Config, $Connection);

      // ! Split frontend batches into protocol message types. Every frontend
      //   message is <type><int32 length><payload>.
      $Types = function (string $batch): string {
         $types = '';
         $offset = 0;

         while ($offset + 5 <= strlen($batch)) {
            $types .= $batch[$offset];
            $header = unpack('N', substr($batch, $offset + 1, 4));
            $offset += 1 + ($header[1] ?? 4);
         }

         return $types;
      };

      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $closeComplete = '3' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      $coldAnswer = "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}";
      $warmAnswer = "{$bindComplete}{$command}{$ready}";

      $errorPayload = "SERROR\0C22012\0Mdivision by zero\0\0";
      $runtimeError = 'E' . pack('N', strlen($errorPayload) + 4) . $errorPayload . $ready;

      $SQL = 'SELECT $1::int AS a';
      $StrangerSQL = 'SELECT $1::int AS b';

      // # Register X and put an older warm Bind for X on the wire.
      $Seed = $PostgreSQL->query($SQL, [1]);
      $PostgreSQL->advance($Seed);
      $seedWire = (string) fread($server, 65536);

      $Older = $PostgreSQL->query($SQL, [2]);
      $PostgreSQL->advance($Older);
      $olderWire = (string) fread($server, 65536);

      fwrite($server, $coldAnswer);
      $PostgreSQL->advance($Seed);

      yield assert(
         assertion: $Types($seedWire) === 'PDBDES'
            && $Types($olderWire) === 'BDES'
            && $Seed->finished
            && $Seed->error === null,
         description: 'The control statement is registered before its older warm Bind is answered'
      );

      // # Evict X through the one-entry LRU, then compose its replacement.
      //   The stranger is deliberately not flushed yet: it only performs the
      //   same cache-side eviction as the filed PG-10 trigger.
      $Stranger = $PostgreSQL->query($StrangerSQL, [3]);
      $Replacement = $PostgreSQL->query($SQL, [4]);

      yield assert(
         assertion: $Types($Stranger->write) === 'PDBDES'
            && $Types($Replacement->write) === 'PDBDES'
            && $Stranger->statement !== $Replacement->statement,
         description: 'The one-entry LRU evicts X and its first replacement composes one Parse'
      );

      // @ The older Bind now receives an ordinary runtime ErrorResponse. Since
      //   LRU already removed X locally, this read path evicts X again. It must
      //   not release the Parse that belongs to $Replacement.
      fwrite($server, $runtimeError);
      $PostgreSQL->advance($Older);

      yield assert(
         assertion: $Older->finished && $Older->error === 'division by zero',
         description: 'The older runtime ErrorResponse is consumed by the intended operation'
      );

      $Later = $PostgreSQL->query($SQL, [5]);

      yield assert(
         assertion: $Later->statement === $Replacement->statement
            && $Later->prepared
            && $Types($Later->write) === 'BDES',
         description: 'PG-10: the later same-name operation stays warm after the unrelated release'
      );

      // # Flush in composition order. The queued LRU Close precedes the sole
      //   replacement Parse; the later operation carries only its warm Bind.
      $PostgreSQL->advance($Replacement);
      $replacementWire = (string) fread($server, 65536);
      $PostgreSQL->advance($Later);
      $laterWire = (string) fread($server, 65536);

      yield assert(
         assertion: $Types($replacementWire) === 'CPDBDES'
            && $Types($laterWire) === 'BDES'
            && substr_count($Types($replacementWire . $laterWire), 'P') === 1,
         description: 'Exactly one replacement Parse reaches the backend and it follows its Close'
      );

      fwrite($server, "{$closeComplete}{$coldAnswer}{$warmAnswer}");
      $PostgreSQL->advance($Replacement);
      $PostgreSQL->advance($Later);

      // @ Complete the LRU stranger too, so every non-error control proves its
      //   own batch and response path instead of relying only on composition.
      $PostgreSQL->advance($Stranger);
      $strangerWire = (string) fread($server, 65536);
      fwrite($server, $coldAnswer);
      $PostgreSQL->advance($Stranger);

      $errors = implode(' ', [
         (string) $Seed->error,
         (string) $Replacement->error,
         (string) $Later->error,
         (string) $Stranger->error,
      ]);

      yield assert(
         assertion: $Replacement->finished && $Replacement->error === null
            && $Later->finished && $Later->error === null
            && $Stranger->finished && $Stranger->error === null
            && $Types($strangerWire) === 'PDBDES'
            && str_contains($errors, '42P05') === false
            && str_contains($errors, 'already exists') === false,
         description: 'Both replacements and the LRU control complete without PostgreSQL 42P05'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
