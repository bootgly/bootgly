<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Driver;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Authentication;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL\Capabilities;


return new Test(
   description: 'Database: a cancel names the session, so it is sent only for the statement the session runs',
   test: function () {
      // ! A side-channel listener the cancel requests would dial.
      $Listener = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      if ($Listener === false) {
         throw new RuntimeException('Could not open the loopback cancel listener.');
      }

      $address = (string) stream_socket_get_name($Listener, false);
      $port = (int) substr($address, strrpos($address, ':') + 1);
      // ! A port nobody listens on: a MySQL kill dialled there fails at once
      //   instead of blocking on a greeting that never comes.
      $Closed = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      if ($Closed === false) {
         throw new RuntimeException('Could not reserve a loopback port.');
      }

      $closed = (string) stream_socket_get_name($Closed, false);
      $refused = (int) substr($closed, strrpos($closed, ':') + 1);
      fclose($Closed);

      /**
       * Opens a single-session pool over a socketpair, with the peer alongside.
       *
       * @param array<string,mixed> $config
       * @return array{SQL, resource}
       */
      $open = static function (array $config): array {
         [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($server, false);

         $Database = new SQL($config + ['timeout' => 5.0, 'pool' => ['min' => 0, 'max' => 1]]);
         $Database->Connection->attach($client);

         return [$Database, $server];
      };
      // ! Fills a socket's send buffer, so the next write takes no byte.
      $fill = static function (mixed $socket): void {
         $junk = str_repeat('j', 65536);

         // @@ Bounded: a socketpair buffer takes a few hundred KiB.
         for ($turn = 0; $turn < 64 && is_resource($socket) && @fwrite($socket, $junk) > 0; $turn++) {
            // fill the socket buffer
         }
      };
      $complete = static function (string $value, int $type = 23): string {
         $columns = pack('n', 1) . "value\0" . pack('N', 0) . pack('n', 0) . pack('N', $type)
            . pack('n', $type === 23 ? 4 : 0xFFFF) . pack('N', 0xFFFFFFFF) . pack('n', 0);
         $row = pack('n', 1) . pack('N', strlen($value)) . $value;
         $command = "SELECT 1\0";

         return 'T' . pack('N', strlen($columns) + 4) . $columns
            . 'D' . pack('N', strlen($row) + 4) . $row
            . 'C' . pack('N', strlen($command) + 4) . $command
            . 'Z' . pack('N', 5) . 'I';
      };

      // # PostgreSQL — a batch pipelined behind another caller's
      //   The backend runs the head; the sibling's batch waits behind it. A
      //   CancelRequest names the backend, so sending one for the sibling
      //   stopped the head — another caller's statement — while the sibling
      //   still ran. It is refused instead: no request leaves, the sibling
      //   runs to completion with `cancelled` false, and the head is intact.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      $Sibling = $Database->query('SELECT 2 AS value');
      $Database->advance($Sibling);
      fread($server, 8192);

      $Protocol = $Head->Protocol;
      $Protocol?->identify(7, 9);

      $pipelined = $Sibling->Connection === $Head->Connection
         && $Head->state === OperationStates::Reading
         && $Sibling->state === OperationStates::Reading;

      $Database->cancel($Sibling);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $pipelined
            && $Dialed === false
            && $Sibling->cancelled === false
            && $Sibling->revoked
            && $Sibling->finished === false
            && $Head->finished === false,
         description: 'PostgreSQL: cancelling a pipelined sibling sends no CancelRequest'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fwrite($server, $complete('1') . $complete('2'));
      $Database->advance($Head);
      $Database->advance($Sibling);

      yield assert(
         assertion: $Head->error === null
            && $Head->Result?->rows === [['value' => 1]]
            && $Sibling->error === null
            && $Sibling->Result?->rows === [['value' => 2]],
         description: 'PostgreSQL: the head keeps its rows and the refused sibling runs to completion'
      );

      // @ The head itself is what the backend runs: its cancel still goes out.
      $Running = $Database->query('SELECT 3 AS value');
      $Database->advance($Running);
      fread($server, 8192);

      $Database->cancel($Running);

      $Dialed = @stream_socket_accept($Listener, 1.0);
      $request = '';

      if ($Dialed !== false) {
         stream_set_timeout($Dialed, 1);
         $request = (string) fread($Dialed, 16);
         fclose($Dialed);
      }

      yield assert(
         assertion: $Running->cancelled
            && $request === pack('N', 16) . pack('N', 80877102) . pack('N', 7) . pack('N', 9),
         description: 'PostgreSQL: cancelling the head still sends its CancelRequest'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # PostgreSQL — a batch parked behind a full write stream
      //   Past `Queued`, yet not one byte of it left: the backend has never
      //   heard of it, and a request would stop the head instead.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      $Head = $Database->query('SELECT 1 AS value');
      $Database->advance($Head);
      fread($server, 8192);
      $Head->Protocol?->identify(7, 9);
      $fill($Database->Connection->socket);

      $Parked = $Database->query('SELECT 2 AS value');
      $Database->advance($Parked);
      $parked = $Parked->Connection === $Head->Connection
         && $Parked->state === OperationStates::Querying;

      $Database->cancel($Parked);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $parked
            && $Dialed === false
            && $Parked->finished
            && $Parked->error === 'Database operation was cancelled before reaching the server.'
            && $Head->finished === false
            && is_resource($Database->Connection->socket),
         description: 'PostgreSQL: a batch not written yet is withdrawn locally, with no CancelRequest'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fclose($server);
      $Database->Connection->disconnect();

      // # PostgreSQL — a batch answered but not read yet
      //   The backend already answered the head and runs the batch behind
      //   it. Judged by what the client had read, the head still looked
      //   running, and its CancelRequest stopped the next caller's batch.
      //   What arrived is read first, all of it: the head is done, nothing is
      //   sent.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      //   Larger than one read, so a single read still leaves it running.
      $Answered = $Database->query('SELECT repeat(\'x\', 20000) AS value');
      $Database->advance($Answered);
      $Next = $Database->query('SELECT 2 AS value');
      $Database->advance($Next);
      fread($server, 8192);
      $Answered->Protocol?->identify(7, 9);
      $large = str_repeat('x', 20000);
      fwrite($server, $complete($large, 25));

      $unread = $Answered->finished === false && $Next->state === OperationStates::Reading;

      $Database->cancel($Answered);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $unread
            && $Dialed === false
            && $Answered->cancelled === false
            && $Answered->error === null
            && $Answered->Result?->rows === [['value' => $large]]
            && $Next->finished === false,
         description: 'PostgreSQL: a head already answered is read, not cancelled, so the batch behind it is spared'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fwrite($server, $complete('2'));
      $Database->advance($Next);

      yield assert(
         assertion: $Next->error === null
            && $Next->Result?->rows === [['value' => 2]]
            && count($Database->Pool->busy) === 0,
         description: 'PostgreSQL: the batch behind it completes and the slot settles'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # PostgreSQL — a batch pipelined behind a stand-in
      //   A withdrawn statement still runs on the backend, drained by a
      //   stand-in. A request for the batch behind it stops that one.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      $Gone = $Database->query('SELECT 1 AS value');
      $Database->advance($Gone);
      fread($server, 8192);
      $Database->withdraw($Gone);

      $Behind = $Database->query('SELECT 2 AS value');
      $Database->advance($Behind);
      fread($server, 8192);
      $Behind->Protocol?->identify(7, 9);

      $behind = $Behind->state === OperationStates::Reading
         && $Behind->Connection === $Gone->Connection
         && $Behind->Protocol?->detached === true;

      $Database->cancel($Behind);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $behind
            && $Dialed === false
            && $Behind->cancelled === false
            && $Behind->finished === false,
         description: 'PostgreSQL: a batch behind a stand-in sends no CancelRequest'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fclose($server);
      $Database->Connection->disconnect();

      // # PostgreSQL — a batch partly written behind a live head
      //   Withdrawing it drops the session, failing the head — another
      //   caller's statement. It is committed instead: refused, it finishes
      //   writing and runs.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      $Live = $Database->query('SELECT 1 AS value');
      $Database->advance($Live);
      fread($server, 8192);

      $Large = $Database->query("SELECT '" . str_repeat('x', 4 * 1024 * 1024) . "' AS value");
      $Database->advance($Large);
      $Large->Protocol?->identify(7, 9);

      $partial = $Large->state === OperationStates::Querying
         && $Large->Connection === $Live->Connection;

      $Database->cancel($Large);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $partial
            && $Dialed === false
            && $Large->finished === false
            && $Large->cancelled === false
            && $Live->finished === false
            && $Live->error === null
            && is_resource($Database->Connection->socket),
         description: 'PostgreSQL: a batch cancelled mid-write behind a live head keeps the session and the head'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fclose($server);
      $Database->Connection->disconnect();

      // # PostgreSQL — a batch partly written, and a caller parked behind it
      //   Nothing is pipelined, but a second caller waits for the write
      //   stream. Withdrawing the batch drops the session under it.
      [$Database, $server] = $open(['host' => '127.0.0.1', 'port' => $port]);

      $Large = $Database->query("SELECT '" . str_repeat('x', 4 * 1024 * 1024) . "' AS value");
      $Database->advance($Large);
      $Waiting = $Database->query('SELECT 2 AS value');
      $Database->advance($Waiting);

      $parked = $Large->state === OperationStates::Querying
         && $Waiting->state === OperationStates::Querying
         && $Waiting->Connection === $Large->Connection;

      $Database->cancel($Large);
      $Database->advance($Waiting);

      $Dialed = @stream_socket_accept($Listener, 0.2);

      yield assert(
         assertion: $parked
            && $Dialed === false
            && $Large->finished === false
            && $Large->cancelled === false
            && $Waiting->finished === false
            && $Waiting->error === null
            && is_resource($Database->Connection->socket),
         description: 'PostgreSQL: a batch cancelled mid-write keeps its session, and the caller parked behind it'
      );

      if ($Dialed !== false) {
         fclose($Dialed);
      }

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a command waiting in the FIFO behind another caller's
      //   Only the head writes, so the sibling is composed but never written.
      //   `KILL QUERY` names the session: it killed the head, and the sibling
      //   was then written and ran with `cancelled` true. It is withdrawn
      //   locally instead, the way a `Queued` operation always was.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);

      $Head = $Database->query('SELECT SLEEP(2)');
      $Database->advance($Head);
      $Sibling = $Database->query("INSERT INTO t (v) VALUES ('cancelled')");
      $Database->advance($Sibling);
      $wire = (string) fread($server, 8192);

      $MySQL = $Head->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $queued = $Sibling->Connection === $Head->Connection
         && $Head->state === OperationStates::Reading
         && $Sibling->state === OperationStates::Querying;

      // @ The driver's own door refuses it too: nothing of it is on the wire.
      $MySQL?->cancel($Sibling);

      yield assert(
         assertion: $queued
            && $Sibling->finished === false
            && $Sibling->cancelled === false
            && $Sibling->error === null,
         description: 'MySQL: the driver sends no kill for a command it has not written'
      );

      $Database->cancel($Sibling);

      yield assert(
         assertion: $queued
            && $Sibling->finished
            && $Sibling->cancelled === false
            && $Sibling->error === 'Database operation was cancelled before reaching the server.'
            && $Head->finished === false
            && $Head->error === null,
         description: 'MySQL: a sibling never written is withdrawn locally, with no kill'
      );

      // @ The head's answer: an OK. Nothing else may follow it on the wire.
      if ($MySQL instanceof MySQL) {
         fwrite($server, $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 2) . pack('v', 0), 1));
      }

      $Database->advance($Head);
      $wire .= (string) fread($server, 8192);

      yield assert(
         assertion: $Head->state === OperationStates::Finished
            && $Head->error === null
            && str_contains($wire, 'SELECT SLEEP(2)')
            && str_contains($wire, 'cancelled') === false
            && $MySQL?->check() === false
            && count($Database->Pool->busy) === 0,
         description: 'MySQL: the head completes and the withdrawn command never reaches the wire'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a head partly written, and a caller the driver has not seen
      //   The rest of the packet must follow, or the session drops — and with
      //   it the caller handed the same connection, which has not reached the
      //   driver's FIFO yet. The cancel is refused: the head finishes writing
      //   and runs, `cancelled` false.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);
      $fill($Database->Connection->socket);

      $Partial = $Database->query("INSERT INTO t (v) VALUES ('partial')");
      $Database->advance($Partial);
      $Unseen = $Database->query("SELECT 'unseen'");

      $MySQL = $Partial->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $writing = $Partial->state === OperationStates::Querying
         && $Unseen->state === OperationStates::Queued
         && $Unseen->Connection === $Partial->Connection;

      $Database->cancel($Partial);
      $Database->advance($Unseen);

      yield assert(
         assertion: $writing
            && $Partial->finished === false
            && $Partial->cancelled === false
            && $Partial->error === null
            && $Unseen->finished === false
            && $Unseen->error === null
            && is_resource($Database->Connection->socket),
         description: 'MySQL: a head cancelled mid-write keeps its session, and the caller not yet queued there'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a command queued behind a stand-in head
      //   A withdrawn head left a stand-in draining its answer. The command
      //   behind it never wrote, so it just leaves the FIFO: the session is
      //   kept for the next caller to drain, as a withdrawal keeps it.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);

      $Abandoned = $Database->query('SELECT SLEEP(2)');
      $Database->advance($Abandoned);
      fread($server, 8192);
      $Database->withdraw($Abandoned);

      $Behind = $Database->query("INSERT INTO t (v) VALUES ('behind')");
      $Database->advance($Behind);

      $MySQL = $Behind->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $behind = $Behind->state === OperationStates::Querying
         && $Behind->Connection === $Abandoned->Connection
         && $MySQL?->detached === true;

      $Database->cancel($Behind);

      yield assert(
         assertion: $behind
            && $Behind->finished
            && $Behind->error === 'Database operation was cancelled before reaching the server.'
            && is_resource($Database->Connection->socket)
            && $MySQL?->detached === true
            && $Database->Pool->created === 1
            && count($Database->Pool->busy) === 0
            && str_contains((string) fread($server, 8192), 'behind') === false,
         description: 'MySQL: a command behind a stand-in head leaves the FIFO and the session is kept'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a head partly written with a caller queued behind it
      //   Withdrawing it drops the session and fails the caller behind it,
      //   which nobody cancelled. It is committed instead: refused, it
      //   finishes writing and runs.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);
      $fill($Database->Connection->socket);

      $Partial = $Database->query("INSERT INTO t (v) VALUES ('partial')");
      $Database->advance($Partial);
      $Queued = $Database->query("SELECT 'innocent'");
      $Database->advance($Queued);

      $MySQL = $Partial->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $shared = $Partial->state === OperationStates::Querying
         && $Queued->state === OperationStates::Querying
         && $Queued->Connection === $Partial->Connection;

      $Database->cancel($Partial);

      yield assert(
         assertion: $shared
            && $Partial->finished === false
            && $Partial->cancelled === false
            && $Partial->error === null
            && $Queued->finished === false
            && $Queued->error === null
            && is_resource($Database->Connection->socket),
         description: 'MySQL: a head cancelled mid-write with a caller behind it keeps the session and that caller'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a parameterized command with only its prepare on the wire
      //   The statement is the COM_STMT_EXECUTE the prepare-OK releases, so a
      //   kill found the session idle and the EXECUTE then ran in full. It is
      //   withdrawn locally: the stand-in that drains the prepare-OK keeps
      //   the statement for the cache and never executes it.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);

      $Prepared = $Database->query('INSERT INTO t (v) VALUES (?)', ['prepared']);
      $Database->advance($Prepared);
      $wire = (string) fread($server, 8192);

      $MySQL = $Prepared->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $preparing = $Prepared->state === OperationStates::Reading
         && str_contains($wire, 'INSERT INTO t (v) VALUES (?)');

      $Database->cancel($Prepared);

      $Later = $Database->query('SELECT 1');
      $Database->advance($Later);

      if ($MySQL instanceof MySQL) {
         // @ prepare-OK: statement 77, no columns, one parameter — then its
         //   definition and the EOF a pre-DEPRECATE_EOF session sends.
         $definition = "\x03def\x00\x00\x00\x01?\x00\x0C" . pack('v', 63) . pack('V', 0)
            . chr(253) . pack('v', 0) . "\x00\x00\x00";
         @fwrite($server, $MySQL->Encoder->frame("\x00" . pack('V', 77) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0), 1));
         @fwrite($server, $MySQL->Encoder->frame($definition, 2));
         @fwrite($server, $MySQL->Encoder->frame("\xFE" . pack('v', 0) . pack('v', 0), 3));
      }

      $Database->advance($Later);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $preparing
            && $Prepared->finished
            && $Prepared->cancelled === false
            && $Prepared->error === 'Database operation was cancelled before reaching the server.'
            && str_contains($wire, "\x17" . pack('V', 77)) === false
            && str_contains($wire, 'SELECT 1'),
         description: 'MySQL: a command cancelled while only its prepare is out is never executed'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a command cancelled after its prepare-OK, before the EXECUTE
      //   The definitions that follow the prepare-OK are still arriving: the
      //   EXECUTE is not written yet, so the command is withdrawn the same way.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);

      $Defining = $Database->query('INSERT INTO t (v) VALUES (?)', ['defining']);
      $Database->advance($Defining);
      fread($server, 8192);

      $MySQL = $Defining->Protocol;

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
         fwrite($server, $MySQL->Encoder->frame("\x00" . pack('V', 78) . pack('v', 0) . pack('v', 1) . "\x00" . pack('v', 0), 1));
      }

      $Database->advance($Defining);
      $defining = $Defining->state === OperationStates::Reading
         && (fn () => $this->phase)->call($MySQL) === 'definitions';

      $Database->cancel($Defining);

      $Later = $Database->query('SELECT 1');
      $Database->advance($Later);

      if ($MySQL instanceof MySQL) {
         $definition = "\x03def\x00\x00\x00\x01?\x00\x0C" . pack('v', 63) . pack('V', 0)
            . chr(253) . pack('v', 0) . "\x00\x00\x00";
         @fwrite($server, $MySQL->Encoder->frame($definition, 2));
         @fwrite($server, $MySQL->Encoder->frame("\xFE" . pack('v', 0) . pack('v', 0), 3));
      }

      $Database->advance($Later);
      $wire = (string) fread($server, 8192);

      yield assert(
         assertion: $defining
            && $Defining->finished
            && $Defining->error === 'Database operation was cancelled before reaching the server.'
            && str_contains($wire, "\x17" . pack('V', 78)) === false
            && str_contains($wire, 'SELECT 1'),
         description: 'MySQL: a command cancelled between its prepare-OK and its EXECUTE is never executed'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — the prepare window inside a transaction
      //   The connection is reserved, and its caller is still there: the
      //   transaction's next statement queues behind the stand-in. Dropping
      //   the session instead rolled back the whole transaction for a
      //   statement that never ran.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'secure' => ['mode' => 'disable'],
      ]);

      $Transaction = $Database->begin();
      $Begin = $Transaction->Operation;
      $MySQL = $Begin?->Protocol;
      $ok = static fn (int $sequence): string => $MySQL instanceof MySQL
         ? $MySQL->Encoder->frame("\x00\x00\x00" . pack('v', 3) . pack('v', 0), $sequence)
         : '';

      if ($Begin !== null) {
         $Database->advance($Begin);
         fread($server, 8192);
         fwrite($server, $ok(1));
         $Database->advance($Begin);
      }

      $Inside = $Transaction->query('INSERT INTO t (v) VALUES (?)', ['inside']);
      $Database->advance($Inside);
      fread($server, 8192);

      if ($MySQL instanceof MySQL) {
         (fn () => $this->thread = 42)->call($MySQL);
      }

      $reserved = $Begin?->error === null
         && $Inside->state === OperationStates::Reading
         && $Inside->Connection === $Begin?->Connection;

      $Database->cancel($Inside);

      $After = $Transaction->query("INSERT INTO t (v) VALUES ('after')");

      yield assert(
         assertion: $reserved
            && $Inside->finished
            && $Inside->error === 'Database operation was cancelled before reaching the server.'
            && is_resource($Database->Connection->socket)
            && $After->error === null
            && $After->Connection === $Inside->Connection,
         description: 'MySQL: a statement withdrawn in its prepare window leaves its transaction open'
      );

      fclose($server);
      $Database->Connection->disconnect();

      // # MySQL — a session still in its handshake
      //   The greeting named the thread, so a kill could be sent, but the
      //   session is authenticating: the kill found nothing to stop, and the
      //   statement then ran in full with `cancelled` true. Nothing of it was
      //   written, so it is withdrawn locally and the half-open connection
      //   is dropped.
      [$Database, $server] = $open([
         'driver' => 'mysql',
         'host' => '127.0.0.1',
         'port' => $refused,
         'username' => 'root',
         'password' => 'secret',
         'database' => '',
         'secure' => ['mode' => 'disable'],
      ]);
      $capabilities = Capabilities::PROTOCOL_41
         | Capabilities::SECURE_CONNECTION
         | Capabilities::PLUGIN_AUTH
         | Capabilities::PLUGIN_AUTH_LENENC
         | Capabilities::DEPRECATE_EOF;
      $greeting = "\x0A8.4.2\0" . pack('V', 42) . "ABCDEFGH\0"
         . pack('v', $capabilities & 0xFFFF) . chr(Capabilities::CHARSET_UTF8MB4)
         . pack('v', 0) . pack('v', $capabilities >> 16) . chr(21) . str_repeat("\0", 10)
         . "IJKLMNOPQRST\0" . Authentication::NATIVE . "\0";

      $Write = $Database->query("INSERT INTO t (v) VALUES ('handshake')");
      $Database->Pool->assign($Write);
      $Write->state = OperationStates::Connecting;
      $Database->advance($Write);

      $MySQL = $Write->Protocol;

      if ($MySQL instanceof MySQL) {
         fwrite($server, $MySQL->Encoder->frame($greeting, 0));
      }

      $Database->advance($Write);
      $wire = (string) fread($server, 8192);
      $authenticating = $Write->state === OperationStates::Authenticating
         && $MySQL instanceof MySQL
         && $MySQL->thread === 42;

      $Database->cancel($Write);
      $wire .= (string) fread($server, 8192);

      yield assert(
         assertion: $authenticating
            && $Write->finished
            && $Write->cancelled === false
            && $Write->error === 'Database operation was cancelled before reaching the server.'
            && is_resource($Database->Connection->socket) === false
            && $Database->Pool->created === 0
            && count($Database->Pool->busy) === 0
            && str_contains($wire, 'handshake') === false,
         description: 'MySQL: a cancel during the handshake withdraws the statement and drops the connection'
      );

      fclose($server);

      // # A driver without a FIFO of its own
      //   The default keeps the one rule every driver shares: `Queued` was
      //   never written, anything past it was.
      $Plain = new SQL(['pool' => ['min' => 0, 'max' => 1]]);
      $Driver = new class ($Plain->Config, $Plain->Connection) extends Driver {
         public function prepare (Operation $Operation): Operation
         {
            return $Operation;
         }

         public function advance (Operation $Operation): Operation
         {
            return $Operation;
         }
      };
      $Probe = $Plain->query('SELECT 1');
      $Probe->state = OperationStates::Queued;
      $queued = $Driver->owe($Probe);
      $Probe->state = OperationStates::Querying;
      $querying = $Driver->owe($Probe);

      yield assert(
         assertion: $queued === false && $querying,
         description: 'Driver::owe() defaults to everything past Queued'
      );

      fclose($Listener);
   }
);
