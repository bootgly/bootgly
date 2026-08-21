<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: a Close never overtakes an in-flight Parse of the same statement',
   test: function () {
      $Connect = static function (int $statements = 256): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'pgsql', 'statements' => $statements, 'timeout' => 5.0]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new PostgreSQL($Config, $Connection), $Connection, $server];
      };
      // ! Split a frontend batch into protocol message types. Every message is
      //   <type><int32 length><payload>, including the simple Query control.
      $Messages = static function (string $batch): string {
         $types = '';
         $offset = 0;

         while ($offset + 5 <= strlen($batch)) {
            $types .= $batch[$offset];
            $header = unpack('N', substr($batch, $offset + 1, 4));
            $offset += 1 + ($header[1] ?? 4);
         }

         return $types;
      };
      $Peek = static function (PostgreSQL $PostgreSQL, string $property): mixed {
         $Property = new ReflectionProperty(PostgreSQL::class, $property);

         return $Property->getValue($PostgreSQL);
      };

      $parseComplete = '1' . pack('N', 4);
      $closeComplete = '3' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 23);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';
      $success = "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}";
      $invalidPayload = "SERROR\0C0A000\0Mcached plan must not change result type\0\0";
      $invalid = 'E' . pack('N', strlen($invalidPayload) + 4) . $invalidPayload;
      $missingPayload = "SERROR\0C42P01\0Mrelation \"missing_table\" does not exist\0\0";
      $missing = 'E' . pack('N', strlen($missingPayload) + 4) . $missingPayload;

      $SQL = 'SELECT $1::int AS v';

      // # A warm error arrives while a replacement Parse is unanswered
      //   The old warm batch is first on the wire. LRU eviction then composes
      //   Close(X)+Parse(X), and only afterwards does the driver read the old
      //   batch's invalidation error. That error queues another Close(X), but
      //   it did not answer the replacement Parse and must not release it.
      [$PostgreSQL, $Connection, $server] = $Connect(1);

      $Warmup = $PostgreSQL->query($SQL, [0]);
      $PostgreSQL->advance($Warmup);
      fread($server, 65536);
      fwrite($server, $success);
      $PostgreSQL->advance($Warmup);

      $Fault = $PostgreSQL->query($SQL, [1]);
      $PostgreSQL->advance($Fault);
      $faultWire = (string) fread($server, 65536);

      // @ Composing this distinct cold statement performs the capacity-one
      //   eviction of X. It deliberately remains unsent.
      $Evictor = $PostgreSQL->query('SELECT $1::int AS displaced', [2]);
      $Parse = $PostgreSQL->query($SQL, [3]);
      $PostgreSQL->advance($Parse);
      $parseWire = (string) fread($server, 65536);

      yield assert(
         assertion: $Messages($faultWire) === 'BDES'
            && $Messages($parseWire) === 'CPDBDES'
            && ($Peek($PostgreSQL, 'preparing')[$Parse->statement] ?? null) === true,
         description: 'The fixture puts a replacement Parse on the wire behind its legitimate Close'
      );

      // @ The first pipeline slot now receives its delayed warm error. X is
      //   absent from the cache because of the LRU eviction, but Parse(X) is
      //   already on the socket in the following slot.
      fwrite($server, "{$invalid}{$ready}");
      $PostgreSQL->advance($Fault);

      yield assert(
         assertion: $Fault->state === OperationStates::Failed
            && ($Peek($PostgreSQL, 'preparing')[$Parse->statement] ?? null) === true
            && isset($Peek($PostgreSQL, 'closing')[$Parse->statement]),
         description: 'PG-11: an older warm ErrorResponse retains the unanswered sent Parse marker'
      );

      // # An unrelated batch cannot carry the newly queued Close(X)
      //   There is no warm Holder for X. The sent Parse marker is the only
      //   evidence that Close(X) would overtake registration of that name.
      $Unrelated = $PostgreSQL->query('SELECT 9 AS unrelated');
      $PostgreSQL->advance($Unrelated);
      $unrelatedWire = (string) fread($server, 65536);

      yield assert(
         assertion: $Messages($unrelatedWire) === 'Q'
            && isset($Peek($PostgreSQL, 'closing')[$Parse->statement]),
         description: 'PG-11: an unrelated batch suppresses Close(X) while Parse(X) is unanswered'
      );

      // # ParseComplete makes the deferred Close stale
      //   The backend has now registered X. Keeping the queued Close would
      //   merely defer the same decapitation to the next batch.
      fwrite($server, "{$closeComplete}{$parseComplete}");
      $PostgreSQL->advance($Parse);

      yield assert(
         assertion: isset($Peek($PostgreSQL, 'preparing')[$Parse->statement]) === false
            && isset($Peek($PostgreSQL, 'closing')[$Parse->statement]) === false
            && isset($PostgreSQL->statements[$Parse->statement]),
         description: 'PG-11: ParseComplete releases its marker, cancels the stale Close, and caches X'
      );

      fwrite($server, "{$parameterDescription}{$bindComplete}{$command}{$ready}");
      $PostgreSQL->advance($Parse);

      $Reuse = $PostgreSQL->query($SQL, [4]);

      yield assert(
         assertion: $Parse->finished && $Parse->error === null
            && $Reuse->prepared && $Messages($Reuse->write) === 'BDES',
         description: 'PG-11: the next execution is a warm Bind with no Close or replacement Parse'
      );

      // @ Keep the deliberately unsent eviction operation alive until all
      //   assertions above have observed its weak marker.
      unset($Evictor);
      fclose($server);
      $Connection->disconnect();

      // # Control: ErrorResponse from the cold Parse itself releases `true`
      //   No ParseComplete preceded this error, so the backend rejected the
      //   registration and retaining the marker would suppress every retry.
      [$PostgreSQL, $Connection, $server] = $Connect();

      $Cold = $PostgreSQL->query('SELECT $1::int FROM missing_table', [1]);
      $PostgreSQL->advance($Cold);
      fread($server, 65536);

      yield assert(
         assertion: ($Peek($PostgreSQL, 'preparing')[$Cold->statement] ?? null) === true,
         description: 'The owner-error control begins with its cold Parse on the wire'
      );

      fwrite($server, "{$missing}{$ready}");
      $PostgreSQL->advance($Cold);

      yield assert(
         assertion: $Cold->state === OperationStates::Failed
            && isset($Peek($PostgreSQL, 'preparing')[$Cold->statement]) === false
            && isset($Peek($PostgreSQL, 'closing')[$Cold->statement]),
         description: 'PG-11 control: a real cold Parse rejection releases the sent marker and queues cleanup'
      );

      fclose($server);
      $Connection->disconnect();

      // # Control: an unsent Parse does not suppress a safe old-name Close
      //   WeakReference means Parse(X) is only in a local buffer. Close(X) may
      //   lead an unrelated batch, and the future Parse remains ordered after it.
      [$PostgreSQL, $Connection, $server] = $Connect();

      $Composer = $PostgreSQL->query($SQL, [5]);
      $PostgreSQL->evict($Composer->statement);
      $Unrelated = $PostgreSQL->query('SELECT 10 AS unrelated');
      $Marker = $Peek($PostgreSQL, 'preparing')[$Composer->statement] ?? null;
      $PostgreSQL->advance($Unrelated);
      $wire = (string) fread($server, 65536);

      yield assert(
         assertion: $Marker instanceof WeakReference
            && $Marker->get() === $Composer
            && $Messages($wire) === 'CQ'
            && isset($Peek($PostgreSQL, 'closing')[$Composer->statement]) === false,
         description: 'PG-11 control: an unsent WeakReference does not suppress a Close ordered before its Parse'
      );

      fclose($server);
      $Connection->disconnect();

      // # Control: statements=0 creates a fresh Close at ReadyForQuery
      //   Cancelling the stale pre-Parse Close must not cancel the deliberate
      //   transient-statement eviction performed after the batch completes.
      [$PostgreSQL, $Connection, $server] = $Connect(0);

      $Transient = $PostgreSQL->query($SQL, [6]);
      $PostgreSQL->advance($Transient);
      fread($server, 65536);
      fwrite($server, $success);
      $PostgreSQL->advance($Transient);

      yield assert(
         assertion: $Transient->finished && $Transient->error === null
            && $PostgreSQL->statements === []
            && isset($Peek($PostgreSQL, 'preparing')[$Transient->statement]) === false
            && isset($Peek($PostgreSQL, 'closing')[$Transient->statement]),
         description: 'PG-11 control: a zero statement budget queues a fresh Close at ReadyForQuery'
      );

      $Unrelated = $PostgreSQL->query('SELECT 11 AS unrelated');
      $PostgreSQL->advance($Unrelated);
      $wire = (string) fread($server, 65536);

      yield assert(
         assertion: $Messages($wire) === 'CQ',
         description: 'The fresh transient Close leads the following batch'
      );

      fclose($server);
      $Connection->disconnect();
   }
);
