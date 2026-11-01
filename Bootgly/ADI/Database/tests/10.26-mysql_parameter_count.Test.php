<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Decoder;


return new Test(
   description: 'MySQL: a parameter count the statement does not take never reaches the server',
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

      // ! The command byte of every packet the client wrote — counting raw
      //   needles would also match payload data carrying the same byte.
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

      // ! A prepare-OK declaring two placeholders and no columns, then their
      //   definitions and the EOF a pre-DEPRECATE_EOF session sends.
      $prepared = fn (int $id, int $parameters): string =>
         "\x00" . pack('V', $id) . pack('v', 0) . pack('v', $parameters) . "\x00" . pack('v', 0);
      $definition = "\x03def\x02db\x05table\x05table\x01p\x01p"
         . "\x0C" . pack('v', 45) . pack('V', 255) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00";
      $eof = "\xFE" . pack('v', 0) . pack('v', 0);
      $ok = "\x00\x01\x00" . pack('v', 0) . pack('v', 0);
      $answer = function (MySQL $MySQL, $server, int $id, int $parameters) use ($prepared, $definition, $eof): void {
         $sequence = 1;
         fwrite($server, $MySQL->Encoder->frame($prepared($id, $parameters), $sequence++));

         for ($index = 0; $index < $parameters; $index++) {
            fwrite($server, $MySQL->Encoder->frame($definition, $sequence++));
         }

         if ($parameters > 0) {
            fwrite($server, $MySQL->Encoder->frame($eof, $sequence));
         }
      };

      $SQL = 'INSERT INTO t (a, b) VALUES (?, ?)';

      // # A surplus value on the cold path
      //   COM_STMT_EXECUTE carries no count: the server read two values from a
      //   block laid out for three, so every value shifted two bytes — `[1, 42,
      //   99]` stored `(65544, 2752512)` with no error. The count the
      //   prepare-OK declares is checked before the EXECUTE is encoded.
      [$MySQL, $Connection, $server] = $connect();

      $Surplus = $MySQL->query($SQL, [1, 42, 99]);
      $MySQL->advance($Surplus);
      $wire = (string) fread($server, 8192);
      $answer($MySQL, $server, 77, 2);
      $MySQL->advance($Surplus);
      $wire .= (string) fread($server, 8192);

      yield assert(
         assertion: $Surplus->state === OperationStates::Failed
            && $Surplus->error === 'MySQL statement expects 2 parameters, 3 given.'
            && $commands($wire) === ["\x16"],
         description: 'A surplus value fails after the prepare-OK, and no EXECUTE is written'
      );

      // @ A missing value on a cold statement fails the same way.
      $Short = $MySQL->query('SELECT ? AS a, ? AS b', [5]);
      $MySQL->advance($Short);
      $wire = (string) fread($server, 8192);
      $answer($MySQL, $server, 80, 2);
      $MySQL->advance($Short);
      $wire .= (string) fread($server, 8192);

      yield assert(
         assertion: $Short->state === OperationStates::Failed
            && $Short->error === 'MySQL statement expects 2 parameters, 1 given.'
            && $commands($wire) === ["\x16"],
         description: 'A missing value fails after the prepare-OK, and no EXECUTE is written'
      );

      // @ One placeholder reads in the singular — cold, then warm.
      $One = $MySQL->query('SELECT ? AS a', [1, 2]);
      $MySQL->advance($One);
      fread($server, 8192);
      $answer($MySQL, $server, 82, 1);
      $MySQL->advance($One);
      fread($server, 8192);
      $Warm = $MySQL->query('SELECT ? AS a', [1, 2]);

      yield assert(
         assertion: $One->error === 'MySQL statement expects 1 parameter, 2 given.'
            && $Warm->error === 'MySQL statement expects 1 parameter, 2 given.',
         description: 'A single placeholder is named in the singular, cold and warm'
      );

      // # A missing value on the warm path
      //   The same statement is cached now: the call is refused before a byte
      //   is written, where the server answered `1835` — or, for some values,
      //   read the length prefix as the missing value and succeeded.
      $Missing = $MySQL->query($SQL, [5]);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Missing->state === OperationStates::Failed
            && $Missing->error === 'MySQL statement expects 2 parameters, 1 given.'
            && $wire === ''
            && isset($MySQL->statements[$SQL]),
         description: 'A missing value is refused on a cache hit, and the statement stays cached'
      );

      // @ The count that matches still executes the cached statement.
      $Exact = $MySQL->query($SQL, [1, 2]);
      $MySQL->advance($Exact);
      $wire = (string) fread($server, 8192);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Exact);

      yield assert(
         assertion: $Exact->state === OperationStates::Finished
            && $Exact->error === null
            && $commands($wire) === ["\x17"],
         description: 'The matching count executes the cached statement'
      );

      fclose($server);
      $Connection->disconnect();

      // # Statements without a cache
      [$MySQL, $Connection, $server] = $connect(0);

      $Uncached = $MySQL->query('SELECT 1 AS one', [5]);
      $MySQL->advance($Uncached);
      fread($server, 8192);
      $answer($MySQL, $server, 78, 0);
      $MySQL->advance($Uncached);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Uncached->state === OperationStates::Failed
            && $Uncached->error === 'MySQL statement expects 0 parameters, 1 given.'
            && in_array("\x17", $commands($wire), true) === false,
         description: 'A value for a statement with no placeholder fails with the cache off too'
      );

      // @ Without a cache nothing else closes what the prepare-OK created:
      //   the refusal queues its close, which rides ahead of the next command.
      $Next = $MySQL->query('SELECT 2 AS two');
      $MySQL->advance($Next);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $MySQL->statements === []
            && $commands($wire) === ["\x19", "\x03"],
         description: 'A refused statement is closed on the server when nothing caches it'
      );

      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Next);

      // @ A value that cannot be bound is refused at the same point, and
      //   its statement is closed the same way.
      $Unbindable = $MySQL->query('SELECT ? AS a', [new stdClass]);
      $MySQL->advance($Unbindable);
      fread($server, 8192);
      $answer($MySQL, $server, 42, 1);
      $MySQL->advance($Unbindable);
      fread($server, 8192);

      $Next = $MySQL->query('SELECT 2 AS two');
      $MySQL->advance($Next);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Unbindable->state === OperationStates::Failed
            && $MySQL->statements === []
            && $commands($wire) === ["\x19", "\x03"]
            && str_contains($wire, "\x19" . pack('V', 42)),
         description: 'An unbindable value closes its uncached statement too'
      );

      fclose($server);
      $Connection->disconnect();

      // # A deferred command that cannot be encoded
      //   A sibling composed while the same SQL's prepare is in flight defers
      //   its encoding until it becomes the head. Failing there left it at the
      //   head as a flushed command nobody answers: the connection wedged and
      //   every later command waited for its deadline unwritten.
      [$MySQL, $Connection, $server] = $connect();

      $Owner = $MySQL->query($SQL, [1, 2]);
      $Deferred = $MySQL->query($SQL, [1, 2, 3]);
      $deferred = $Deferred->write === '';

      $MySQL->advance($Owner);
      $MySQL->advance($Deferred);
      fread($server, 8192);
      $answer($MySQL, $server, 79, 2);
      $MySQL->advance($Owner);
      fread($server, 8192);

      // @ A third caller queues behind both and waits for a read that only
      //   the command ahead of it can provoke.
      $Later = $MySQL->query('SELECT 3 AS c');
      $MySQL->advance($Later);

      // @ The owner's answer retires it; the read promotes the deferred
      //   command, which fails to encode — nobody advances the third caller.
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Owner);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $deferred
            && $Owner->state === OperationStates::Finished
            && $Deferred->state === OperationStates::Failed
            && $Deferred->error === 'MySQL statement expects 2 parameters, 3 given.'
            && str_contains($wire, 'SELECT 3 AS c')
            && in_array($Deferred, $MySQL->drain(), true),
         description: 'A deferred command that fails to encode leaves the FIFO, and the command behind it is written'
      );

      fwrite($server, $MySQL->Encoder->frame("\x01", 1));
      fwrite($server, $MySQL->Encoder->frame("\x03def\x00\x00\x00\x01c\x01c\x0C" . pack('v', 63) . pack('V', 1) . chr(Decoder::TYPE_LONGLONG) . pack('v', 0) . "\x00\x00\x00", 2));
      fwrite($server, $MySQL->Encoder->frame($eof, 3));
      fwrite($server, $MySQL->Encoder->frame("\x013", 4));
      fwrite($server, $MySQL->Encoder->frame($eof, 5));
      $MySQL->advance($Later);

      yield assert(
         assertion: $Later->state === OperationStates::Finished
            && $Later->rows === [['c' => 3]]
            && $MySQL->check() === false,
         description: 'The command behind it completes and the session is free again'
      );

      fclose($server);
      $Connection->disconnect();

      // # The command behind it pumps the read that promotes it
      //   Here the read that retires the owner is the third caller's own: its
      //   read promotes the deferred command, which fails and hands the wire on
      //   — writing the third command from inside that read. Its own advance
      //   then wrote it again, so the server ran it twice and every later
      //   caller received the answer meant for the one before it.
      [$MySQL, $Connection, $server] = $connect();

      $Owner = $MySQL->query($SQL, [1, 2]);
      $Deferred = $MySQL->query($SQL, [new stdClass, 2]);

      $MySQL->advance($Owner);
      $MySQL->advance($Deferred);
      fread($server, 8192);
      $answer($MySQL, $server, 81, 2);
      $MySQL->advance($Owner);
      fread($server, 8192);

      $Third = $MySQL->query('INSERT INTO t (a, b) VALUES (3, 4)');
      $MySQL->advance($Third);
      fwrite($server, $MySQL->Encoder->frame($ok, 1));
      $MySQL->advance($Third);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $Owner->state === OperationStates::Finished
            && $Deferred->state === OperationStates::Failed
            && $commands($wire) === ["\x03"]
            && $Third->state === OperationStates::Reading,
         description: 'The command behind a deferred failure is written exactly once when its own read promotes it'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
