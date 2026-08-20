<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: the Parse reaches the backend ahead of every Bind that needs it',
   test: function () {
      $connect = function (): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'pgsql', 'statements' => 256]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new PostgreSQL($Config, $Connection), $Connection, $server];
      };
      // ! Split a composed batch into its frontend message types. Every message
      //   is <type><int32 length><payload>, so this never mistakes parameter
      //   data for a message tag.
      $messages = function (string $batch): string {
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
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';

      $SQL = 'SELECT $1::int AS v';

      // # Awaiting out of creation order
      //   The sibling composed a warm Bind trusting the owner would write
      //   first. Nothing ordered that: whichever operation the caller advances
      //   first is the one that writes first, and the backend answered the
      //   Bind with `prepared statement "…" does not exist`.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $Sibling = $PostgreSQL->query($SQL, [2]);

      yield assert(
         assertion: $messages($Owner->write) === 'PDBDES' && $messages($Sibling->write) === 'BDES',
         description: 'Composition is unchanged: the owner Parses and the sibling Binds warm'
      );

      // @ The caller advances the sibling first.
      $PostgreSQL->advance($Sibling);
      $first = (string) fread($server, 65536);

      yield assert(
         assertion: str_starts_with($messages($first), 'P'),
         description: 'The batch that reaches the wire first carries the Parse'
      );

      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($Sibling);

      $PostgreSQL->advance($Owner);
      $second = (string) fread($server, 65536);

      yield assert(
         assertion: $messages($second) === 'BDES',
         description: 'The operation that gave up its Parse comes back as the warm Bind'
      );

      fwrite($server, "{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($Owner);

      yield assert(
         assertion: substr_count($messages($first . $second), 'P') === 1
            && $Owner->finished && $Owner->error === null
            && $Sibling->finished && $Sibling->error === null,
         description: 'Exactly one Parse is sent and both operations resolve'
      );

      fclose($server);
      $Connection->disconnect();

      // # A marker its owner will never send must not suppress the next Parse
      //   `fail()` is invisible to the driver, so nothing released the name.
      //   The next operation for that SQL then composed a Bind for a statement
      //   nothing had Parsed.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Composer = $PostgreSQL->query($SQL, [7]);
      $Ledger = new ReflectionProperty(PostgreSQL::class, 'preparing');

      yield assert(
         assertion: $Ledger->getValue($PostgreSQL) !== [],
         description: 'Composing the Parse marks the statement name in flight'
      );

      $Composer->fail('its caller gave up on it');
      $Next = $PostgreSQL->query($SQL, [8]);

      yield assert(
         assertion: $messages($Next->write) === 'PDBDES',
         description: 'A name whose owner is finished and unsent is Parsed again'
      );

      fclose($server);
      $Connection->disconnect();

      // # A Parse that is on the wire but unanswered is not up for grabs
      //   Between the owner's flush and the backend's ParseComplete the name is
      //   not yet cached, so only the sent-marker distinguishes it. Reading it
      //   as still-composed would have this sibling take a Parse that is
      //   already on the socket and send a second one — 42P05.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $Sibling = $PostgreSQL->query($SQL, [2]);

      $PostgreSQL->advance($Owner);
      $sent = (string) fread($server, 65536);

      $PostgreSQL->advance($Sibling);
      $after = (string) fread($server, 65536);

      yield assert(
         assertion: $messages($sent) === 'PDBDES'
            && $messages($after) === 'BDES'
            && substr_count($messages($sent . $after), 'P') === 1,
         description: 'A sibling advanced before the ParseComplete still Binds warm'
      );

      fclose($server);
      $Connection->disconnect();

      // # A batch re-entering a partial flush is never re-composed
      //   Its first bytes are already on the wire, so re-deriving it would put
      //   a Parse behind the Bind it belongs in front of.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $Sibling = $PostgreSQL->query($SQL, [2]);

      $Writing = new ReflectionProperty(PostgreSQL::class, 'writing');
      $Writing->setValue($PostgreSQL, $Sibling);

      $PostgreSQL->advance($Sibling);
      $partial = (string) fread($server, 65536);

      yield assert(
         assertion: $messages($partial) === 'BDES' && $Owner->write !== '',
         description: 'A batch already writing keeps the messages it composed'
      );

      fclose($server);
      $Connection->disconnect();

      // # Taking the Parse leaves the batch mid-send, not mid-queue
      //   prepare() rewinds the state to Queued, which is the branch that
      //   reconnects. Parking a batch that holds the write stream there makes a
      //   connection lost during a partial flush re-handshake instead of fail,
      //   and keep a statement cache the new session never Parsed.
      [$PostgreSQL, $Connection, $server] = $connect();

      // ! Large enough that the socket buffer cannot take the batch in one write.
      $wide = 'SELECT $1::int AS v /* ' . str_repeat('x', 512 * 1024) . ' */';
      $Owner = $PostgreSQL->query($wide, [1]);
      $Taker = $PostgreSQL->query($wide, [2]);

      $PostgreSQL->advance($Taker);

      yield assert(
         assertion: $Taker->state === OperationStates::Querying
            && $Taker->write !== ''
            && $Owner->write === '',
         description: 'A partially flushed take-over stays in the querying state'
      );

      $Connection->disconnect();
      $PostgreSQL->advance($Taker);

      yield assert(
         assertion: $Taker->state === OperationStates::Failed && $Taker->finished,
         description: 'Losing the connection mid-send fails the batch instead of reconnecting'
      );

      fclose($server);

      // # Only the composer's own completion releases its name
      //   `cache()` and `evict()` are on the read path and know a statement name
      //   but not who owes its Parse. Dropping an unsent composer's marker there
      //   strands it: a warm sibling can then neither read it nor take it, and
      //   reaches the wire with a Bind for a statement nothing has Parsed — this
      //   entry's own defect, by another route.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Owner = $PostgreSQL->query($SQL, [1]);
      $Sibling = $PostgreSQL->query($SQL, [2]);

      $PostgreSQL->evict($Owner->statement);
      $PostgreSQL->advance($Sibling);
      $evicted = (string) fread($server, 65536);

      yield assert(
         assertion: str_contains($messages($evicted), 'P')
            && substr_count($messages($evicted), 'P') === 1,
         description: 'Evicting a name its composer has not sent still leaves the Parse to send'
      );

      fclose($server);
      $Connection->disconnect();

      // # A composer nobody holds any more releases its name
      //   The ledger keeps a weak reference precisely so a dropped composer can
      //   be collected: holding it would pin its whole result set for the
      //   connection's life, and the name would suppress every later Parse.
      [$PostgreSQL, $Connection, $server] = $connect();

      $Ghost = $PostgreSQL->query($SQL, [1]);
      $Ghost = null;
      gc_collect_cycles();

      $Next = $PostgreSQL->query($SQL, [2]);

      yield assert(
         assertion: $messages($Next->write) === 'PDBDES',
         description: 'A name whose composer was collected is Parsed again'
      );

      fclose($server);
      $Connection->disconnect();

      // # An operation retried onto another driver is never stripped
      //   fallback() moves an operation to a replica pool, where it composes a
      //   batch of its own. Blanking that from the driver it left would corrupt
      //   a stream this one does not own.
      [$Left, $LeftConnection, $leftServer] = $connect();
      [$Joined, $JoinedConnection, $joinedServer] = $connect();

      $Moved = $Left->query($SQL, [1]);
      $Moved->retry();
      $Joined->prepare($Moved);
      $composed = $Moved->write;

      $Local = $Left->query($SQL, [2]);
      $Left->advance($Local);

      yield assert(
         assertion: $Moved->Protocol === $Joined && $Moved->write === $composed && $composed !== '',
         description: 'A batch composed on another driver survives a take-over here'
      );

      fclose($leftServer);
      fclose($joinedServer);
      $LeftConnection->disconnect();
      $JoinedConnection->disconnect();
   }
);
