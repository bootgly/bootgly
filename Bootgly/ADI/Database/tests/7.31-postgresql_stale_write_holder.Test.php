<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;


return new Test(
   description: 'PostgreSQL: a write holder past its deadline is reconciled without touching the transport',
   test: function () {
      $connect = function (float $timeout = 5.0): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Config = new Config(['driver' => 'pgsql', 'statements' => 256, 'timeout' => $timeout]);
         $Connection = new Connection($Config);
         $Connection->attach($client);

         return [new PostgreSQL($Config, $Connection), $Config, $Connection, $server];
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
      $peek = static function (PostgreSQL $PostgreSQL, string $property): mixed {
         return (new ReflectionProperty(PostgreSQL::class, $property))->getValue($PostgreSQL);
      };
      $wait = static function (float $seconds): void {
         $started = microtime(true);
         while (microtime(true) - $started < $seconds) { /* busy — a sleep can be cut short */ }
      };
      $drain = static function ($server): void {
         while (($bytes = fread($server, 262144)) !== false && $bytes !== '') { /* @ empty the pipe */ }
      };
      $fill = static function ($client): void {
         while (@fwrite($client, str_repeat('j', 65536)) > 0) { /* @ jam the pipe */ }
      };

      // ! One backend answer per batch shape, byte-exact.
      $row = static function (string $name, string $value): string {
         $columnPayload = pack('n', 1)
            . "{$name}\0" . pack('N', 0) . pack('n', 0) . pack('N', 23) . pack('n', 4) . pack('N', 0xFFFFFFFF) . pack('n', 0);
         $rowPayload = pack('n', 1) . pack('N', strlen($value)) . $value;
         $commandPayload = "SELECT 1\0";

         return 'T' . pack('N', strlen($columnPayload) + 4) . $columnPayload
            . 'D' . pack('N', strlen($rowPayload) + 4) . $rowPayload
            . 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload
            . 'Z' . pack('N', 5) . 'I';
      };
      $parseComplete = '1' . pack('N', 4);
      $parameterPayload = pack('n', 1) . pack('N', 25);
      $parameterDescription = 't' . pack('N', strlen($parameterPayload) + 4) . $parameterPayload;
      $bindComplete = '2' . pack('N', 4);
      $commandPayload = "SELECT 1\0";
      $command = 'C' . pack('N', strlen($commandPayload) + 4) . $commandPayload;
      $ready = 'Z' . pack('N', 5) . 'I';

      $payload = str_repeat('x', 512 * 1024);

      // # A holder inside its own deadline still owns the stream
      //   Its caller is legitimately mid-write and resumes the flush — a
      //   sibling must park, exactly as before this fix.
      [$PostgreSQL, $Config, $Connection, $server] = $connect();

      $Holder = $PostgreSQL->query('SELECT length($1) AS n', [$payload]);
      $PostgreSQL->advance($Holder);

      yield assert(
         assertion: $Holder->write !== '' && $peek($PostgreSQL, 'writing') === $Holder,
         description: 'A batch larger than the send buffer flushes partially and holds the stream'
      );

      $Sibling = $PostgreSQL->query('SELECT 1 AS v');
      $PostgreSQL->advance($Sibling);

      yield assert(
         assertion: $Sibling->finished === false && $Holder->finished === false
            && $peek($PostgreSQL, 'writing') === $Holder,
         description: 'A sibling waits for a live holder — the takeover never fires inside the deadline'
      );

      fclose($server);
      $Connection->disconnect();

      // # Past the deadline, the sibling finishes the holder's flush
      //   The remainder sits in the holder's own buffer and is all the backend
      //   waits for. Answers the backend already delivered for whole batches
      //   must survive — the collateral that reverted the first attempt.
      [$PostgreSQL, $Config, $Connection, $server] = $connect();

      $First = $PostgreSQL->query('SELECT 1 AS a');
      $PostgreSQL->advance($First);
      $Second = $PostgreSQL->query('SELECT 2 AS b');
      $PostgreSQL->advance($Second);

      // @ Both answers are already in this process's receive buffer.
      fwrite($server, $row('a', '1') . $row('b', '2'));

      $Config->timeout = 0.05;
      $Holder = $PostgreSQL->query('SELECT length($1) AS n', [$payload]);
      $PostgreSQL->advance($Holder);
      $Config->timeout = 5.0;

      $stalled = strlen($Holder->write);
      $wait(0.08);

      // @ A later caller trips the guard. The remainder can be larger than the
      //   pipe, so the test drains between passes — the role a live backend
      //   plays continuously.
      $Late = $PostgreSQL->query('SELECT 99 AS q');

      for ($pass = 0; $pass < 10 && $Holder->finished === false; $pass++) {
         $drain($server);
         $PostgreSQL->advance($Late);
      }

      yield assert(
         assertion: $stalled > 0 && $Holder->finished && $Holder->error !== null
            && str_contains($Holder->error, 'timed out'),
         description: 'The stale holder fails with its own deadline, not with a transport error'
      );

      yield assert(
         assertion: $Holder->revoked === true,
         description: 'A batch whose bytes ran is revoked — fallback() must never re-run work with an unknown outcome'
      );

      yield assert(
         assertion: $Connection->connected && is_resource($Connection->socket),
         description: 'The transport stays up: the backend was healthy, only waiting for bytes'
      );

      // ? The takeover marked the holder's Parse as on the wire — an operation
      //   composed after it Binds the statement warm instead of Parsing again.
      $Warm = $PostgreSQL->query('SELECT length($1) AS n', [$payload . 'w']);

      yield assert(
         assertion: $messages($Warm->write) === 'BDES',
         description: 'After the takeover the statement name is registered as flushed: later batches Bind warm'
      );

      // @ The backend answers everything whole on the wire: the holder's batch
      //   (drained by the detached stand-in) and the late caller's query.
      $drain($server);
      fwrite($server, "{$parseComplete}{$parameterDescription}{$bindComplete}{$command}{$ready}");
      fwrite($server, $row('q', '99'));

      $PostgreSQL->advance($First);
      $PostgreSQL->advance($Second);
      $PostgreSQL->advance($Late);

      yield assert(
         assertion: $First->finished && $First->error === null && $First->Result?->cell === 1
            && $Second->finished && $Second->error === null && $Second->Result?->cell === 2,
         description: 'Both siblings keep the answers the backend had already sent, found: '
            . json_encode([$First->error, $First->Result?->cell, $Second->error, $Second->Result?->cell])
      );

      yield assert(
         assertion: $Late->finished && $Late->error === null,
         description: 'The caller that performed the takeover completes its own work on the same connection'
      );

      fclose($server);
      $Connection->disconnect();

      // # A holder with nothing on the wire is withdrawn locally
      //   flush() also returns false when fwrite() writes zero bytes into a
      //   full send buffer — that holder owns the stream on a perfectly
      //   synchronised connection, and nothing is owed to the backend.
      [$PostgreSQL, $Config, $Connection, $server] = $connect(0.05);

      $PostgreSQL->evict('bootgly_carried');
      $fill($Connection->socket);

      $Holder = $PostgreSQL->query('SELECT 3 AS v');
      $PostgreSQL->advance($Holder);

      yield assert(
         assertion: $peek($PostgreSQL, 'writing') === $Holder && $peek($PostgreSQL, 'wrote') === 0,
         description: 'The first fwrite() returned zero: the holder claims the stream with nothing on the wire'
      );

      $wait(0.08);
      $Config->timeout = 5.0;

      $Next = $PostgreSQL->query('SELECT 4 AS v');
      $PostgreSQL->advance($Next);

      yield assert(
         assertion: $Holder->finished && $Holder->error !== null
            && str_contains($Holder->error, 'timed out') && $Holder->revoked === false,
         description: 'A zero-byte holder is expired without revocation — nothing ran, so a fallback retry stays legal'
      );

      yield assert(
         assertion: $Connection->connected && is_resource($Connection->socket),
         description: 'Local withdrawal never touches the transport'
      );

      // ? The Close the withdrawn batch carried never went out — it must ride
      //   the next batch, or the backend keeps a name the driver believes
      //   closed and the next Parse for it collides (42P05).
      yield assert(
         assertion: str_starts_with($messages($Next->write), 'C'),
         description: 'The statement Close the withdrawn batch carried leads the next batch, found: '
            . $messages($Next->write)
      );

      fclose($server);
      $Connection->disconnect();

      // # abandon() draws the same line
      //   The pool envelope expires before it abandons, so the buffer is
      //   already destroyed there: bytes on the wire leave a message only that
      //   buffer could complete — the session drops. Zero bytes leave a whole
      //   connection — it must survive.
      [$PostgreSQL, $Config, $Connection, $server] = $connect(0.05);

      $PostgreSQL->evict('bootgly_requeued');
      $fill($Connection->socket);

      $Holder = $PostgreSQL->query('SELECT 5 AS v');
      $PostgreSQL->advance($Holder);
      $Holder->fail('withdrawn by its caller');
      $PostgreSQL->abandon($Holder);

      yield assert(
         assertion: $Connection->connected && $peek($PostgreSQL, 'writing') === null
            && isset($peek($PostgreSQL, 'closing')['bootgly_requeued']),
         description: 'An abandoned zero-byte holder frees the stream, requeues its Closes and keeps the connection'
      );

      fclose($server);
      $Connection->disconnect();

      [$PostgreSQL, $Config, $Connection, $server] = $connect(0.05);

      $Holder = $PostgreSQL->query('SELECT length($1) AS n', [$payload]);
      $PostgreSQL->advance($Holder);
      $Holder->fail('withdrawn by its caller');
      $PostgreSQL->abandon($Holder);

      yield assert(
         assertion: $Connection->connected === false,
         description: 'An abandoned half-written holder still drops the session: its remainder died with the expiry'
      );

      fclose($server);
   }
);
