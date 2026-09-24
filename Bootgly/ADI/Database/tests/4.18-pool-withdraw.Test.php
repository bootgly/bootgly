<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_unique;
use function assert;
use function count;
use function fclose;
use function feof;
use function fread;
use function fwrite;
use function gc_collect_cycles;
use function is_resource;
use function is_string;
use function json_encode;
use function max;
use function microtime;
use function min;
use function pack;
use function spl_object_id;
use function str_contains;
use function str_repeat;
use function stream_select;
use function stream_set_blocking;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_pair;
use function stream_socket_server;
use function strlen;
use function strrpos;
use function substr;
use function usleep;
use ReflectionProperty;
use RuntimeException;
use WeakReference;

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Connection\ConnectionStates;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


/**
 * `Pool::withdraw()` is what a caller that stopped waiting leaves behind: a
 * deferred response whose wait was refused, interrupted, or whose Fiber was
 * destroyed. Nothing may reach the server for it — the operation fails
 * locally, the driver reconciles the wire, and the pool takes the slot back.
 * Every leg below checks the whole census (created/idle/busy/pending/locked),
 * because each earlier shape of this defect was a slot counted forever or a
 * session lent to the wrong caller.
 */
return new Test(
   description: 'Pool: a withdrawn operation fails locally, reconciles its wire and gives its slot back',
   test: function () {
      // ! The error every withdrawal leaves on the operation it withdraws.
      $withdrawn = 'Database operation was withdrawn: its caller stopped waiting.';
      // ! Every configuration points at this listener. Leg 1 dials it on
      //   purpose; any other leg that dials is caught by an accept it never
      //   expected — never a real server on a default port.
      $Trap = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);

      if (is_resource($Trap) === false) {
         yield assert(
            assertion: false,
            description: "Fixture: the trap listener could not bind ({$error})"
         );

         return;
      }

      $address = stream_socket_get_name($Trap, false);
      $separator = is_string($address) ? strrpos($address, ':') : false;
      $port = $separator === false ? 0 : (int) substr((string) $address, $separator + 1);

      /**
       * Opens a pooled SQL database over a socketpair, with the peer alongside.
       *
       * @return array{SQL, resource}
       */
      $Open = static function (string $driver, int $max) use ($port): array {
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);

         $Database = new SQL([
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => $max],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $peer];
      };
      /**
       * The pool census every leg asserts.
       *
       * @return array{created:int,idle:int,busy:int,pending:int,locked:int}
       */
      $Census = static function (Pool $Pool): array {
         $locked = (new ReflectionProperty(Pool::class, 'locked'))->getValue($Pool);

         return [
            'created' => $Pool->created,
            'idle' => count($Pool->idle),
            'busy' => count($Pool->busy),
            'pending' => count($Pool->pending),
            'locked' => count((array) $locked),
         ];
      };
      $census = static fn (int $created, int $idle, int $busy, int $pending, int $locked): array => [
         'created' => $created,
         'idle' => $idle,
         'busy' => $busy,
         'pending' => $pending,
         'locked' => $locked,
      ];
      // ! A complete PostgreSQL answer to a statement: CommandComplete + ReadyForQuery.
      $Complete = static function (string $command): string {
         $size = pack('N', strlen($command) + 5);
         $ready = pack('N', 5);

         return "C{$size}{$command}\0Z{$ready}I";
      };
      // ! A PostgreSQL answer to `SELECT <n> AS value`: one int4 column, one row.
      $Select = static function (int $value) use ($Complete): string {
         $text = (string) $value;
         $one = pack('n', 1);
         $attributes = pack('NnNnNn', 0, 0, 23, 4, 0xFFFFFFFF, 0);
         $column = "{$one}value\0{$attributes}";
         $width = pack('N', strlen($text));
         $row = "{$one}{$width}{$text}";
         $described = pack('N', strlen($column) + 4);
         $sized = pack('N', strlen($row) + 4);
         $completed = $Complete('SELECT 1');

         return "T{$described}{$column}D{$sized}{$row}{$completed}";
      };
      /**
       * Reads whatever the peer still holds and reports whether the client
       * hung up. Bounded: at most 100 readiness waits of 10 ms each.
       *
       * @param resource $peer
       * @return array{string, bool}
       */
      $Hangup = static function ($peer): array {
         $bytes = '';

         // @@
         for ($turn = 0; $turn < 100; $turn++) {
            $read = [$peer];
            $write = [];
            $except = [];

            if (@stream_select($read, $write, $except, 0, 10_000) !== 1) {
               continue;
            }

            $chunk = fread($peer, 65536);

            if ($chunk === false || $chunk === '') {
               if (feof($peer)) {
                  return [$bytes, true];
               }

               continue;
            }

            $bytes = "{$bytes}{$chunk}";
         }

         return [$bytes, feof($peer)];
      };
      /**
       * Opens a transaction and drives its BEGIN to an answered, reserved state.
       *
       * @param resource $peer
       */
      $Begin = static function (SQL $Database, $peer) use ($Complete): Transaction {
         $Transaction = $Database->begin();
         $Opening = $Transaction->Operation;

         if ($Opening !== null) {
            $Database->advance($Opening);
            fread($peer, 8192);
            fwrite($peer, $Complete('BEGIN'));
            $Database->advance($Opening);
         }

         return $Transaction;
      };
      /**
       * Drives one statement to its answer and hands back what the peer read.
       *
       * @param resource $peer
       */
      $Settle = static function (SQL $Database, SQLOperation $Operation, $peer, string $answer): string {
         $Database->advance($Operation);
         $wire = (string) fread($peer, 8192);
         fwrite($peer, $answer);

         // @@ Bounded: a socketpair delivers the answer to one read.
         for ($turn = 0; $turn < 50 && $Operation->finished === false; $turn++) {
            $Database->advance($Operation);

            if ($Operation->finished === false) {
               usleep(1_000);
            }
         }

         return $wire;
      };
      $Withdrawn = static fn (Operation $Operation): bool =>
         $Operation->state === OperationStates::Failed
         && $Operation->finished
         && $Operation->error === $withdrawn
         && $Operation->revoked;
      /**
       * Drives an operation's dial to the trap listener and accepts it there,
       * reading the StartupMessage. With `trust`, the peer answers the way a
       * trust-auth server does and the operation is driven until its batch is
       * on the wire. Bounded: every phase is a ceiling, not the expected path.
       *
       * @return array{resource|false, string, string} [session, startup, wire]
       */
      $Dial = static function (SQL $Database, SQLOperation $Operation, bool $trust = false) use ($Trap): array {
         // @@ A loopback dial and the StartupMessage flush settle in a couple of turns.
         for ($turn = 0; $turn < 200 && $Operation->state !== OperationStates::Authenticating && $Operation->finished === false; $turn++) {
            $Database->advance($Operation);

            if ($Operation->state !== OperationStates::Authenticating) {
               usleep(5_000);
            }
         }

         $session = @stream_socket_accept($Trap, 2.0);

         if (is_resource($session) === false) {
            return [false, '', ''];
         }

         stream_set_blocking($session, false);
         $startup = '';

         // @@ The StartupMessage is already flushed.
         for ($turn = 0; $turn < 100 && strlen($startup) < 8; $turn++) {
            $chunk = (string) fread($session, 8192);
            $startup = "{$startup}{$chunk}";

            if (strlen($startup) < 8) {
               usleep(5_000);
            }
         }

         if ($trust === false) {
            return [$session, $startup, ''];
         }

         // @ AuthenticationOk + ReadyForQuery: a trust-auth server's whole answer.
         $size = pack('N', 8);
         $code = pack('N', 0);
         $ready = pack('N', 5);
         fwrite($session, "R{$size}{$code}Z{$ready}I");

         // @@ The handshake answer and the batch flush take a few turns.
         for ($turn = 0; $turn < 200 && $Operation->state !== OperationStates::Reading && $Operation->finished === false; $turn++) {
            $Database->advance($Operation);

            if ($Operation->state !== OperationStates::Reading) {
               usleep(1_000);
            }
         }

         $wire = '';

         // @@ The batch is already flushed.
         for ($turn = 0; $turn < 100 && $wire === '' && $Operation->state === OperationStates::Reading; $turn++) {
            $wire = (string) fread($session, 8192);

            if ($wire === '') {
               usleep(1_000);
            }
         }

         return [$session, $startup, $wire];
      };
      /**
       * Passes withdrawn statements' deadlines without waiting for them: each
       * operation's own deadline, and the slot the pool quarantined under it,
       * move into the past. A leg that slept until a short deadline passed
       * could be lapsed by any stall of the process before it asserted the
       * slot was still held; a deadline far away, moved by hand, cannot.
       *
       * @return int The quarantined slots that lapsed.
       */
      $Lapse = static function (Pool $Pool, Operation ...$Operations): int {
         $Quarantine = new ReflectionProperty(Pool::class, 'quarantine');
         $Deadline = new ReflectionProperty(Operation::class, 'deadline');
         $slots = (array) $Quarantine->getValue($Pool);
         $past = microtime(true) - 1.0;
         $lapsed = 0;

         // @@ Every slot quarantined under one of these deadlines.
         foreach ($Operations as $Operation) {
            foreach ($slots as $key => $deadline) {
               if ($deadline === $Operation->deadline) {
                  $slots[$key] = $past;
                  $lapsed++;
               }
            }

            $Deadline->setValue($Operation, $past);
         }

         $Quarantine->setValue($Pool, $slots);

         return $lapsed;
      };

      $Previous = Emitter::$Instance;

      try {
         // # 1. A connection still in its startup handshake is discarded
         //   The peer accepted the dial and took the StartupMessage, but never
         //   answered. Nothing is known about that session: pooling it would
         //   run the next caller's query on a half-authenticated connection.
         $SQL = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Pool = $SQL->Pool;
         $Dialing = $SQL->query('SELECT 1');
         $Connection = $Dialing->Connection;

         // @@ Bounded: a loopback dial and the StartupMessage flush settle in a
         //    couple of turns; 200 × 5 ms is a ceiling, not the expected path.
         for ($turn = 0; $turn < 200 && $Dialing->state !== OperationStates::Authenticating && $Dialing->finished === false; $turn++) {
            $SQL->advance($Dialing);

            if ($Dialing->state !== OperationStates::Authenticating) {
               usleep(5_000);
            }
         }

         $Silent = @stream_socket_accept($Trap, 2.0);
         $startup = '';

         if (is_resource($Silent)) {
            stream_set_blocking($Silent, false);

            // @@ Bounded: the StartupMessage is already flushed.
            for ($turn = 0; $turn < 100 && strlen($startup) < 8; $turn++) {
               $chunk = (string) fread($Silent, 8192);
               $startup = "{$startup}{$chunk}";

               if (strlen($startup) < 8) {
                  usleep(5_000);
               }
            }
         }

         $handshaking = $Dialing->state === OperationStates::Authenticating
            && $Connection !== null
            && $Connection->state === ConnectionStates::Startup
            && is_resource($Silent)
            && substr($startup, 4, 4) === pack('N', 196608)
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $Returned = $Pool->withdraw($Dialing);
         $closed = false;
         $rest = '';

         if (is_resource($Silent)) {
            [$rest, $closed] = $Hangup($Silent);
            fclose($Silent);
         }

         yield assert(
            assertion: $handshaking
               && $Returned === $Dialing
               && $Withdrawn($Dialing)
               && $Census($Pool) === $census(0, 0, 0, 0, 0)
               && $Connection?->connected === false
               && $Connection?->socket === null
               && $closed
               && $rest === '',
            description: 'PostgreSQL: an operation withdrawn during startup discards the connection and the peer reads EOF; census='
               . json_encode($Census($Pool)) . ', error=' . json_encode($Dialing->error)
         );

         // # 2. A sole reader takes its session down with it
         //   Nobody is left to drain the answer the server still owes, so the
         //   session cannot be resynchronised and the slot comes back with it.
         [$SQL, $peer] = $Open('mysql', 1);
         $Pool = $SQL->Pool;
         $Alone = $SQL->query('SELECT 1');
         $SQL->advance($Alone);
         $wire = (string) fread($peer, 8192);
         $reading = $Alone->state === OperationStates::Reading
            && str_contains($wire, 'SELECT 1')
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $Pool->withdraw($Alone);
         [$rest, $closed] = $Hangup($peer);

         yield assert(
            assertion: $reading
               && $Withdrawn($Alone)
               && $Census($Pool) === $census(0, 0, 0, 0, 0)
               && $SQL->Connection->connected === false
               && $closed
               && $rest === '',
            description: 'MySQL: a withdrawn sole reader drops its connection; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);

         // # 3. A reader with a live sibling pipelined behind it keeps the session
         //   The sibling still reads this socket, so the withdrawn answer is
         //   handed to a stand-in and absorbed there — the sibling gets its own.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Front = $SQL->query('SELECT 111 AS value');
         $Back = $SQL->query('SELECT 222 AS value');
         $SQL->advance($Front);
         $SQL->advance($Back);
         $wire = (string) fread($peer, 8192);
         $pipelined = $Front->state === OperationStates::Reading
            && $Back->state === OperationStates::Reading
            && $Front->Connection === $Back->Connection
            && str_contains($wire, 'SELECT 111 AS value')
            && str_contains($wire, 'SELECT 222 AS value');

         $Pool->withdraw($Front);

         yield assert(
            assertion: $pipelined
               && $Withdrawn($Front)
               && $Back->finished === false
               && $Census($Pool) === $census(1, 0, 1, 0, 0)
               && is_resource($SQL->Connection->socket),
            description: 'PostgreSQL: withdrawing a reader with a live sibling behind it keeps the connection busy for the sibling; census='
               . json_encode($Census($Pool))
         );

         // @ The server answers both, in order; only the sibling is still read.
         $first = $Select(111);
         $second = $Select(222);
         fwrite($peer, "{$first}{$second}");

         // @@ Bounded: both answers sit in one socketpair buffer.
         for ($turn = 0; $turn < 50 && $Back->finished === false; $turn++) {
            $SQL->advance($Back);

            if ($Back->finished === false) {
               usleep(1_000);
            }
         }

         yield assert(
            assertion: $Back->finished
               && $Back->error === null
               && $Back->Result?->rows === [['value' => 222]]
               && $Front->Result === null
               && $Census($Pool) === $census(1, 1, 0, 0, 0),
            description: 'PostgreSQL: the sibling reads its own answer, then the connection goes idle; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();

         // # 4. A parked operation is forgotten, never promoted
         //   With the only connection reserved by a transaction, the statement
         //   parks. Withdrawn, it must leave `pending` for good: once the
         //   transaction frees the slot, nothing may put it on the wire.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Transaction = $Begin($SQL, $peer);
         $Parked = $SQL->query('SELECT 2 AS value');
         $parked = $Parked->state === OperationStates::Pending
            && $Census($Pool) === $census(1, 0, 1, 1, 1);

         $Pool->withdraw($Parked);

         yield assert(
            assertion: $parked
               && $Withdrawn($Parked)
               && $Census($Pool) === $census(1, 0, 1, 0, 1),
            description: 'A withdrawn parked operation leaves pending at once; census='
               . json_encode($Census($Pool))
         );

         // @ Capacity frees: the transaction commits and hands the slot back —
         //   and the caller collecting what it withdrew must not revive it.
         $Commit = $Transaction->commit();
         $wire = $Settle($SQL, $Commit, $peer, $Complete('COMMIT'));
         $SQL->advance($Parked);
         $late = (string) fread($peer, 8192);

         yield assert(
            assertion: $Commit->finished
               && $Commit->error === null
               && str_contains($wire, "COMMIT\0")
               && str_contains("{$wire}{$late}", 'SELECT 2') === false
               && $Parked->Protocol === null
               && $Parked->Connection === null
               && $Withdrawn($Parked)
               && $Census($Pool) === $census(1, 1, 0, 0, 0),
            description: 'Freed capacity never promotes the withdrawn operation onto the wire; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();

         // # 5. A finished operation keeps its outcome
         //   Whoever finished it settled its claim; withdrawing it afterwards
         //   records the caller's decision and touches nothing else.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Done = $SQL->query('SELECT 7 AS value');
         $Settle($SQL, $Done, $peer, $Select(7));
         $Result = $Done->Result;
         $answered = $Done->state === OperationStates::Finished
            && $Result?->rows === [['value' => 7]]
            && $Done->revoked === false;
         $before = $Census($Pool);

         $Pool->withdraw($Done);

         yield assert(
            assertion: $answered
               && $Done->state === OperationStates::Finished
               && $Done->error === null
               && $Done->Result === $Result
               && $Done->Result?->rows === [['value' => 7]]
               && $Done->revoked
               && $before === $census(1, 1, 0, 0, 0)
               && $Census($Pool) === $before,
            description: 'A resolved operation keeps its Result when withdrawn — only revoked is set; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();

         // @ A parked operation finished by something else keeps its error and
         //   still leaves the queue: `pending` carries live work only.
         $Starved = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'pool' => ['min' => 0, 'max' => 0],
         ]);
         $Refused = $Starved->query('SELECT 1');
         $queued = count($Starved->Pool->pending) === 1;
         $Refused->fail('Finished by something other than this pool.');

         $Starved->Pool->withdraw($Refused);

         yield assert(
            assertion: $queued
               && $Refused->error === 'Finished by something other than this pool.'
               && $Refused->revoked
               && $Census($Starved->Pool) === $census(0, 0, 0, 0, 0),
            description: 'A failed operation keeps its error when withdrawn and leaves pending; census='
               . json_encode($Census($Starved->Pool))
         );

         // # 6. A teardown withdrawn before the wire ends the transaction
         //   The ROLLBACK never reached the server, so the transaction is still
         //   open there. Lending that session to the next caller would run
         //   their writes inside it; dropping it rolls it back server-side.
         foreach (['rollback', 'abort'] as $teardown) {
            [$SQL, $peer] = $Open('pgsql', 1);
            $Pool = $SQL->Pool;
            $Transaction = $Begin($SQL, $peer);
            $reserved = $Census($Pool) === $census(1, 0, 1, 0, 1);
            $Opening = $Transaction->Operation;

            $Teardown = $teardown === 'rollback'
               ? $Transaction->rollback()
               : $Transaction->abort();
            $queued = $Teardown->state === OperationStates::Queued
               && $Teardown->unlock
               && $Opening !== null
               && $Teardown->Connection === $Opening->Connection;

            $Pool->withdraw($Teardown);
            [$rest, $closed] = $Hangup($peer);

            yield assert(
               assertion: $reserved
                  && $queued
                  && $Withdrawn($Teardown)
                  && $Census($Pool) === $census(0, 0, 0, 0, 0)
                  && $SQL->Connection->connected === false
                  && $closed
                  && str_contains($rest, 'ROLLBACK') === false,
               description: "A queued {$teardown}() teardown withdrawn before the wire drops the session and its reservation; census="
                  . json_encode($Census($Pool))
            );

            fclose($peer);
         }

         // # 7. A transaction whose session is gone composes nothing
         //   Withdrawing its in-flight statement dropped the connection. Once
         //   that statement's deadline lets its slot go, the pool may hand the
         //   same Connection object to another caller with a new driver — a
         //   ROLLBACK composed then would end their session.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Transaction = $Begin($SQL, $peer);
         $Opening = $Transaction->Operation;
         $Connection = $Opening?->Connection;
         $Retired = $Opening?->Protocol;
         // ! The pool's 30 s deadline: its withdrawn slot stays quarantined
         //   until then, and the rebuild below passes it by hand.
         $Statement = $Transaction->query('UPDATE t SET v = 1');
         $SQL->advance($Statement);
         fread($peer, 8192);
         $inflight = $Statement->state === OperationStates::Reading;

         $Pool->withdraw($Statement);
         [$rest, $closed] = $Hangup($peer);
         fclose($peer);

         $Inactive = static fn (SQLOperation $Operation): bool =>
            $Operation->state === OperationStates::Failed
            && $Operation->error === 'SQL transaction is not active.'
            && $Operation->Pool === null
            && $Operation->Protocol === null;
         $Rollback = $Transaction->rollback();
         $Abort = $Transaction->abort();

         yield assert(
            assertion: $inflight
               && $Withdrawn($Statement)
               && $closed
               && $Connection?->Protocol === null
               && $Inactive($Rollback)
               && $Inactive($Abort)
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'Once its session is dropped, rollback() and abort() fail without touching the pool; census='
               . json_encode($Census($Pool)) . ', rollback=' . json_encode($Rollback->error)
         );

         // @ Another caller arrives while the server may still be running the
         //   withdrawn UPDATE: the slot is not free yet, so it parks — even with
         //   a live socket already attached to the Connection object.
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);
         $Connection?->attach($client);

         $Other = $SQL->query('SELECT 333 AS value');
         $SQL->advance($Other);
         $held = microtime(true) < $Statement->deadline;
         $parked = $Other->state === OperationStates::Pending
            && $Other->Connection === null
            && $Other->Protocol === null
            && $Census($Pool) === $census(0, 0, 0, 1, 0);
         $silent = (string) fread($peer, 8192);

         yield assert(
            assertion: $held
               && $parked
               && $silent === '',
            description: 'While the withdrawn statement\'s deadline holds its slot, another caller parks; census='
               . json_encode($Census($Pool)) . ', held=' . json_encode($held)
         );

         // @ The deadline passes — moved there, never waited out — and the pool
         //   rebuilds the same Connection object for the caller that was waiting.
         $lapsed = $Lapse($Pool, $Statement) === 1;
         $SQL->advance($Other);
         $rebuilt = $Connection !== null
            && $Other->Connection === $Connection
            && $Other->Protocol !== null
            && $Other->Protocol !== $Retired
            && $Connection->Protocol === $Other->Protocol;
         $shared = $Census($Pool);

         $Rollback = $Transaction->rollback();
         $Abort = $Transaction->abort();
         $Query = $Transaction->query('UPDATE t SET v = 2');

         yield assert(
            assertion: $lapsed
               && $rebuilt
               && $Inactive($Rollback)
               && $Inactive($Abort)
               && $Inactive($Query)
               && $shared === $census(1, 0, 1, 0, 0)
               && $Census($Pool) === $shared
               && $Connection?->Protocol === $Other->Protocol,
            description: "A transaction never composes onto the caller its connection was rebuilt for; census="
               . json_encode($Census($Pool)) . ', lapsed=' . json_encode($lapsed) . ', rollback=' . json_encode($Rollback->error)
         );

         $wire = $Settle($SQL, $Other, $peer, $Select(333));

         yield assert(
            assertion: str_contains($wire, 'SELECT 333 AS value')
               && str_contains($wire, 'ROLLBACK') === false
               && str_contains($wire, 'UPDATE') === false
               && $Other->error === null
               && $Other->Result?->rows === [['value' => 333]]
               && $Census($Pool) === $census(1, 1, 0, 0, 0),
            description: 'The rebuilt session carries only its own caller\'s statement; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();

         // # 8. abort() ends the whole transaction at any depth, silently
         //   rollback() at a savepoint still unwinds one level; abort() composes
         //   the top-level ROLLBACK that gives the reservation back, and emits
         //   nothing — its caller is a Fiber being destroyed, where a listener
         //   cannot suspend.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Transaction = $Begin($SQL, $peer);
         $Settle($SQL, $Transaction->save(), $peer, $Complete('SAVEPOINT'));

         $rollbacks = 0;
         $Emitter = new Emitter;
         $Emitter->listen(Events::Rollback, static function () use (&$rollbacks): void {
            $rollbacks++;
         });
         Emitter::$Instance = $Emitter;

         $Partial = $Transaction->rollback();
         $partial = [$Partial->SQL, $Partial->unlock, $Transaction->depth, $rollbacks];
         $wire = $Settle($SQL, $Partial, $peer, $Complete('ROLLBACK'));
         $Settle($SQL, $Transaction->save(), $peer, $Complete('SAVEPOINT'));
         $deep = $Transaction->depth;

         $Abort = $Transaction->abort();
         $aborted = [$Abort->SQL, $Abort->unlock, $Abort->lock, $Transaction->depth, $Transaction->Connection, $rollbacks];

         Emitter::$Instance = $Previous;

         yield assert(
            assertion: $partial === ['ROLLBACK TO SAVEPOINT "bootgly_0"', false, 1, 1]
               && str_contains($wire, "ROLLBACK TO SAVEPOINT \"bootgly_0\"\0")
               && $deep === 2
               && $aborted === ['ROLLBACK', true, false, 0, null, 1],
            description: 'At depth 2, rollback() unwinds one savepoint and abort() composes a silent top-level ROLLBACK; partial='
               . json_encode($partial) . ', abort=' . json_encode([$Abort->SQL, $Abort->unlock, $deep, $rollbacks])
         );

         $wire = $Settle($SQL, $Abort, $peer, $Complete('ROLLBACK'));

         yield assert(
            assertion: str_contains($wire, "ROLLBACK\0")
               && str_contains($wire, 'SAVEPOINT') === false
               && $Abort->finished
               && $Abort->error === null
               && $Census($Pool) === $census(1, 1, 0, 0, 0),
            description: 'The aborted transaction hands its reserved connection back to the pool; census='
               . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();

         // # 9. The facade withdraws parked work before the slot it waits for
         //   The in-flight operation is named first on purpose. Freeing its
         //   slot first would promote the parked statement onto a new
         //   connection — dialled for work whose caller already left.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Transaction = $SQL->begin();
         $Opening = $Transaction->Operation;
         $wire = '';

         if ($Opening !== null) {
            $SQL->advance($Opening);
            $wire = (string) fread($peer, 8192);
         }

         $Parked = $SQL->query('SELECT 2 AS value');
         $staged = $Opening !== null
            && $Opening->state === OperationStates::Reading
            && $Parked->state === OperationStates::Pending
            && $Census($Pool) === $census(1, 0, 1, 1, 1);

         if ($Opening !== null) {
            $SQL->withdraw($Opening, $Parked);
         }

         [$rest, $closed] = $Hangup($peer);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 50_000);

         yield assert(
            assertion: $staged
               && $Opening !== null
               && $Withdrawn($Opening)
               && $Withdrawn($Parked)
               && $Parked->Protocol === null
               && $Parked->Connection === null
               && $Census($Pool) === $census(0, 0, 0, 0, 0)
               && $closed
               && str_contains("{$wire}{$rest}", 'SELECT 2') === false
               && $dialled === 0,
            description: 'Database::withdraw() retires the parked operation first, so it never reaches a wire; census='
               . json_encode($Census($Pool)) . ', dialled=' . json_encode($dialled)
         );

         fclose($peer);

         // @ One refusing pool strands nothing: every operation is attempted,
         //   and the first failure surfaces once all of them ran.
         $Starved = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'pool' => ['min' => 0, 'max' => 0],
         ]);
         $Kept = $Starved->query('SELECT 1');
         $Refusing = new class ($Starved->Config, new Connection($Starved->Config)) extends Pool {
            public function withdraw (Operation $Operation): Operation
            {
               $name = $Operation instanceof SQLOperation ? $Operation->SQL : '';

               throw new RuntimeException("Refused {$name}.");
            }
         };
         $First = new SQLOperation(null, 'first');
         $First->Pool = $Refusing;
         $Second = new SQLOperation(null, 'second');
         $Second->Pool = $Refusing;
         $failure = '';

         try {
            $Starved->withdraw($First, $Kept, $Second);
         }
         catch (RuntimeException $Exception) {
            $failure = $Exception->getMessage();
         }

         yield assert(
            assertion: $failure === 'Refused first.'
               && $Withdrawn($Kept)
               && $Census($Starved->Pool) === $census(0, 0, 0, 0, 0),
            description: 'Database::withdraw() isolates a refusing pool and rethrows the first failure; failure='
               . json_encode($failure)
         );

         // # 10. A withdrawn statement keeps its slot until its own deadline
         //   Dropping the session does not stop the server: it runs what it was
         //   sent until it notices the hangup. The slot stays counted against
         //   `max` until the statement's deadline, so a client's disconnect
         //   rate never lets the pool run more than `max` statements at once.
         //   The census shows only connections; the quarantine shows as work
         //   that parks, and as dials that never reach the trap. Deadlines here
         //   are far away — no stall of this process lapses one while a leg
         //   still asserts the slot held — and a leg that needs one gone
         //   passes it by hand ($Lapse) instead of sleeping it out.

         // @ (a) A sole reader withdrawn: the next query parks, nothing dials,
         //   until the withdrawn reader's own deadline passes.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Sole = $SQL->query('SELECT 4 AS value');
         $SQL->advance($Sole);
         $wire = (string) fread($peer, 8192);
         $reading = $Sole->state === OperationStates::Reading
            && str_contains($wire, 'SELECT 4 AS value');

         $Pool->withdraw($Sole);
         [$rest, $closed] = $Hangup($peer);
         fclose($peer);

         $Next = $SQL->query('SELECT 5 AS value');
         $SQL->advance($Next);
         $SQL->advance($Next);
         $held = microtime(true) < $Sole->deadline;
         $parked = $Next->state === OperationStates::Pending
            && $Next->Connection === null
            && $Census($Pool) === $census(0, 0, 0, 1, 0);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 20_000);

         yield assert(
            assertion: $reading
               && $Withdrawn($Sole)
               && $closed
               && $rest === ''
               && $held
               && $parked
               && $dialled === 0,
            description: 'A withdrawn sole reader drops its session but holds its slot: the next query parks and no session is dialled; census='
               . json_encode($Census($Pool)) . ', held=' . json_encode($held) . ', dialled=' . json_encode($dialled)
         );

         // @ The reader's deadline passes — moved there, never waited out.
         $lapsed = $Lapse($Pool, $Sole) === 1;
         [$session, $startup] = $Dial($SQL, $Next);

         yield assert(
            assertion: $lapsed
               && $Next->state === OperationStates::Authenticating
               && $Next->Connection === $SQL->Connection
               && is_resource($session)
               && substr($startup, 4, 4) === pack('N', 196608)
               && $Census($Pool) === $census(1, 0, 1, 0, 0),
            description: 'Once the withdrawn reader\'s deadline passes, the parked query dials the freed slot; census='
               . json_encode($Census($Pool)) . ', lapsed=' . json_encode($lapsed)
         );

         $Pool->withdraw($Next);

         if (is_resource($session)) {
            fclose($session);
         }

         // @ (b) Readers withdrawn one after another inside one deadline never
         //   open more than `max` sessions: each one on the wire holds its slot.
         [$SQL, $peer] = $Open('pgsql', 2);
         $Pool = $SQL->Pool;
         $First = $SQL->query('SELECT 11 AS value');
         $SQL->advance($First);
         $received = [(string) fread($peer, 8192)];
         $wired = [$First->state === OperationStates::Reading];
         $Pool->withdraw($First);
         [, $closed] = $Hangup($peer);
         $hangups = [$closed];
         fclose($peer);

         // @ One slot is still free: the second reader dials a second session.
         $Second = $SQL->query('SELECT 12 AS value');
         [$session, , $wire] = $Dial($SQL, $Second, true);
         $received[] = $wire;
         $wired[] = $Second->state === OperationStates::Reading;
         $Pool->withdraw($Second);

         if (is_resource($session)) {
            [, $closed] = $Hangup($session);
            $hangups[] = $closed;
            fclose($session);
         }

         // @ Both slots are quarantined: the third parks and dials nothing.
         $Third = $SQL->query('SELECT 13 AS value');
         $SQL->advance($Third);
         $parked = $Third->state === OperationStates::Pending
            && $Census($Pool) === $census(0, 0, 0, 1, 0);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 20_000);
         $Pool->withdraw($Third);

         yield assert(
            assertion: $wired === [true, true]
               && str_contains($received[0], 'SELECT 11 AS value')
               && str_contains($received[1], 'SELECT 12 AS value')
               && $hangups === [true, true]
               && $parked
               && $dialled === 0
               && $Withdrawn($First)
               && $Withdrawn($Second)
               && $Withdrawn($Third)
               && $Third->Connection === null
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'Three readers withdrawn inside one deadline open at most pool.max = 2 sessions; the third never dials; census='
               . json_encode($Census($Pool)) . ', wired=' . json_encode($wired) . ', dialled=' . json_encode($dialled)
         );

         // @ (c) A withdrawn reader whose session survives holds nothing extra:
         //   the connection still counts, so the free slot serves at once.
         //   The sibling is pinned to the same connection, leaving the second
         //   slot free; BEGIN never co-locates, so it only runs if that slot
         //   is not also held by a quarantine.
         [$SQL, $peer] = $Open('pgsql', 2);
         $Pool = $SQL->Pool;
         $Front = $SQL->query('SELECT 111 AS value');
         $Back = new SQLOperation($Front->Connection, 'SELECT 222 AS value', [], 30.0);
         $Pool->assign($Back);
         $SQL->advance($Front);
         $SQL->advance($Back);
         $wire = (string) fread($peer, 8192);
         $pipelined = $Front->state === OperationStates::Reading
            && $Back->state === OperationStates::Reading
            && $Front->Connection === $Back->Connection
            && str_contains($wire, 'SELECT 111 AS value')
            && str_contains($wire, 'SELECT 222 AS value');

         $Pool->withdraw($Front);
         $survived = $Withdrawn($Front)
            && $Back->finished === false
            && $SQL->Connection->Protocol === $Back->Protocol
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $Transaction = $SQL->begin();
         $Opening = $Transaction->Operation;
         $assigned = $Opening !== null
            && $Opening->state === OperationStates::Queued
            && $Opening->Connection !== null
            && $Opening->Connection !== $SQL->Connection
            && $Census($Pool) === $census(2, 0, 2, 0, 1);
         $wire = '';
         $session = false;

         if ($Opening !== null) {
            [$session, , $wire] = $Dial($SQL, $Opening, true);

            if (is_resource($session)) {
               $Settle($SQL, $Opening, $session, $Complete('BEGIN'));
            }
         }

         yield assert(
            assertion: $pipelined
               && $survived
               && $assigned
               && $Opening !== null
               && str_contains($wire, 'BEGIN')
               && $Opening->finished
               && $Opening->error === null
               && microtime(true) < $Front->deadline
               && $Census($Pool) === $census(2, 0, 2, 0, 1),
            description: 'A withdrawn reader whose session survives quarantines nothing: BEGIN takes the free slot at once; census='
               . json_encode($Census($Pool)) . ', begin=' . json_encode($Opening?->error)
         );

         if (is_resource($session)) {
            fclose($session);
         }

         fclose($peer);
         $SQL->Connection->disconnect();
         $Opening?->Connection?->disconnect();

         // @ (d) Work withdrawn before its statement reached the wire holds
         //   nothing, even with a 30 s deadline: the next query dials at once.
         $SQL = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $Pool = $SQL->Pool;
         $Early = $SQL->query('SELECT 1');
         $queued = $Early->state === OperationStates::Queued
            && $Census($Pool) === $census(1, 0, 1, 0, 0);
         $Pool->withdraw($Early);

         $Starting = $SQL->query('SELECT 2');
         $assigned = [
            $Starting->state === OperationStates::Queued
            && $Starting->Connection === $SQL->Connection
            && $Census($Pool) === $census(1, 0, 1, 0, 0),
         ];
         [$session, $startup] = $Dial($SQL, $Starting);
         $handshaking = $Starting->state === OperationStates::Authenticating
            && $SQL->Connection->state === ConnectionStates::Startup
            && is_resource($session)
            && substr($startup, 4, 4) === pack('N', 196608);
         $Pool->withdraw($Starting);

         if (is_resource($session)) {
            fclose($session);
         }

         $Last = $SQL->query('SELECT 3');
         $assigned[] = $Last->state === OperationStates::Queued
            && $Last->Connection === $SQL->Connection
            && $Census($Pool) === $census(1, 0, 1, 0, 0);
         [$session, $startup] = $Dial($SQL, $Last);
         $dialling = $Last->state === OperationStates::Authenticating
            && is_resource($session)
            && substr($startup, 4, 4) === pack('N', 196608);
         $Pool->withdraw($Last);

         if (is_resource($session)) {
            fclose($session);
         }

         yield assert(
            assertion: $queued
               && $Withdrawn($Early)
               && $handshaking
               && $Withdrawn($Starting)
               && $assigned === [true, true]
               && $dialling
               && $Withdrawn($Last)
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'Operations withdrawn while Queued or in the startup handshake quarantine nothing: the next query dials at once; assigned='
               . json_encode($assigned) . ', census=' . json_encode($Census($Pool))
         );

         // @ (e) A reader with no deadline has none to wait out: its slot is
         //   free the moment its session drops.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $SQL->Config->timeout = 0.0;
         $Unbounded = $SQL->query('SELECT 6 AS value');
         $SQL->Config->timeout = 30.0;
         $SQL->advance($Unbounded);
         fread($peer, 8192);
         $reading = $Unbounded->state === OperationStates::Reading
            && $Unbounded->deadline === 0.0;
         $Pool->withdraw($Unbounded);
         fclose($peer);

         $After = $SQL->query('SELECT 7 AS value');
         $free = $After->state === OperationStates::Queued
            && $After->Connection === $SQL->Connection
            && $Census($Pool) === $census(1, 0, 1, 0, 0);
         $Pool->withdraw($After);

         yield assert(
            assertion: $reading
               && $Withdrawn($Unbounded)
               && $free
               && $Withdrawn($After)
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'A withdrawn reader without a deadline frees its slot at once; census='
               . json_encode($Census($Pool))
         );

         // @ (f) Quarantines alone fill `max`, and a parked BEGIN still waits:
         //   both readers drop their sessions, so no connection is counted.
         //   Each withdrawal's release() promotes with the BEGIN pending; if
         //   promote() counted connections only, it would keep shifting it
         //   into an assign() that re-parks it, and return only once the
         //   BEGIN's own deadline expired it.
         [$SQL, $peer] = $Open('pgsql', 2);
         $Pool = $SQL->Pool;
         $First = $SQL->query('SELECT 14 AS value');
         $Second = $SQL->query('SELECT 15 AS value');
         $SQL->advance($First);
         $received = [(string) fread($peer, 8192)];
         [$session, , $wire] = $Dial($SQL, $Second, true);
         $received[] = $wire;

         // @ Both slots are busy: BEGIN never co-locates, so it parks.
         $Transaction = $SQL->begin();
         $Opening = $Transaction->Operation;
         $staged = $First->state === OperationStates::Reading
            && $Second->state === OperationStates::Reading
            && $First->Connection !== $Second->Connection
            && str_contains($received[0], 'SELECT 14 AS value')
            && str_contains($received[1], 'SELECT 15 AS value')
            && $Opening !== null
            && $Opening->state === OperationStates::Pending
            && $Census($Pool) === $census(2, 0, 2, 1, 0);

         // ! Timed against a bound no stall reaches: a spinning promote() holds
         //   the withdrawals for the BEGIN's whole 30 s deadline, and leaves it
         //   expired — which $parked sees as well.
         $started = microtime(true);
         $Pool->withdraw($First);
         $Pool->withdraw($Second);
         $elapsed = microtime(true) - $started;

         $held = microtime(true) < $First->deadline;
         $parked = $Opening !== null
            && $Opening->state === OperationStates::Pending
            && $Opening->Connection === null
            && $Opening->Protocol === null
            && $Census($Pool) === $census(0, 0, 0, 1, 0);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 20_000);
         [, $closed] = $Hangup($peer);
         $hangups = [$closed];
         fclose($peer);

         if (is_resource($session)) {
            [, $closed] = $Hangup($session);
            $hangups[] = $closed;
            fclose($session);
         }

         yield assert(
            assertion: $staged
               && $elapsed < 5.0
               && $Withdrawn($First)
               && $Withdrawn($Second)
               && $hangups === [true, true]
               && $held
               && $parked
               && $dialled === 0,
            description: 'Two withdrawn readers quarantine both slots with no connection left: the withdrawals return and the parked BEGIN stays parked; elapsed='
               . json_encode($elapsed) . ', census=' . json_encode($Census($Pool)) . ', held=' . json_encode($held) . ', dialled=' . json_encode($dialled)
         );

         // @ Both readers' deadlines pass — moved there, never waited out.
         $lapsed = $Lapse($Pool, $First, $Second) === 2;
         $session = false;
         $startup = '';

         if ($Opening !== null) {
            [$session, $startup] = $Dial($SQL, $Opening);
         }

         yield assert(
            assertion: $lapsed
               && $Opening !== null
               && $Opening->state === OperationStates::Authenticating
               && $Opening->Connection === $SQL->Connection
               && is_resource($session)
               && substr($startup, 4, 4) === pack('N', 196608)
               && $Census($Pool) === $census(1, 0, 1, 0, 1),
            description: 'Once both readers\' deadlines pass, the parked BEGIN dials the freed slot; census='
               . json_encode($Census($Pool)) . ', lapsed=' . json_encode($lapsed) . ', state=' . json_encode($Opening?->state->name)
         );

         if ($Opening !== null) {
            $Pool->withdraw($Opening);
         }

         if (is_resource($session)) {
            fclose($session);
         }

         // @ (g) A withdrawn statement's caller leaves, and the operation is
         //   freed with it: PHP hands its object id to the next operation.
         //   Keyed by that id, every withdrawal would overwrite the entry of
         //   the statement before it — each round dialling one more session
         //   while the server still runs them all. Six rounds of query, wire,
         //   withdraw and free inside one deadline reach the trap `max` times.
         $SQL = new SQL([
            'driver' => 'pgsql',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 5.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 2],
         ]);
         $Pool = $SQL->Pool;
         $parks = [];
         $freed = [];
         $withdrawals = [];
         $ids = [];
         $deadlines = [];
         $received = [];
         $hangups = [];

         // @@ Bounded: three times `max` rounds, each dial capped by $Dial.
         for ($round = 1; $round <= 6; $round++) {
            $Round = $SQL->query("SELECT {$round} AS value");
            $SQL->advance($Round);
            $parked = $Round->state === OperationStates::Pending;
            $session = false;

            if ($parked === false) {
               $ids[] = spl_object_id($Round);
               $deadlines[] = $Round->deadline;
               [$session, , $wire] = $Dial($SQL, $Round, true);
               $received[] = $wire;
            }

            $Pool->withdraw($Round);
            $withdrawals[] = $Withdrawn($Round);

            if (is_resource($session)) {
               [, $closed] = $Hangup($session);
               $hangups[] = $closed;
               fclose($session);
            }

            // @ The caller is gone: nothing but this local holds the operation.
            //   Pending garbage is collected first, so the id the next query
            //   takes is the one this operation gives back.
            $parks[] = $parked;
            $Weak = WeakReference::create($Round);
            gc_collect_cycles();
            unset($Round);
            $freed[] = $Weak->get() === null;
         }

         $held = $deadlines !== [] && microtime(true) < min($deadlines);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 20_000);
         $backlog = $dialled;

         // @@ Bounded: accept whatever dialled past `max`, so no later leg
         //    meets it in the trap's backlog.
         for ($turn = 0; $turn < 16 && $backlog > 0; $turn++) {
            $stray = @stream_socket_accept($Trap, 0.0);

            if (is_resource($stray)) {
               fclose($stray);
            }

            $read = [$Trap];
            $write = [];
            $except = [];
            $backlog = @stream_select($read, $write, $except, 0, 0);
         }

         yield assert(
            assertion: $parks === [false, false, true, true, true, true]
               && count($received) === 2
               && str_contains($received[0], 'SELECT 1 AS value')
               && str_contains($received[1], 'SELECT 2 AS value')
               && $hangups === [true, true]
               && $withdrawals === [true, true, true, true, true, true]
               && $freed === [true, true, true, true, true, true]
               && count(array_unique($ids)) < count($ids)
               && $held
               && $dialled === 0
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'Freed withdrawn readers whose ids are recycled still hold their slots: six rounds open pool.max = 2 sessions; parks='
               . json_encode($parks) . ', ids=' . json_encode($ids) . ', freed=' . json_encode($freed)
               . ', held=' . json_encode($held) . ', dialled=' . json_encode($dialled) . ', census=' . json_encode($Census($Pool))
         );

         // @ (h) A key-value server drops a disconnected client's work at once:
         //   a Redis command withdrawn on the wire frees its slot with its
         //   session, however far its deadline. On a single-slot pool the
         //   next command dials at once instead of parking for 30 s.
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);

         $KV = new KV([
            'driver' => 'redis',
            'host' => '127.0.0.1',
            'port' => $port,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => 1],
         ]);
         $KV->Connection->attach($client);
         $Pool = $KV->Pool;
         $Command = $KV->command('GET', ['k']);
         $KV->advance($Command);
         $wire = (string) fread($peer, 8192);
         $reading = $Command->state === OperationStates::Reading
            && $wire === "*2\r\n\$3\r\nGET\r\n\$1\r\nk\r\n"
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $Pool->withdraw($Command);
         [$rest, $closed] = $Hangup($peer);
         fclose($peer);
         $dropped = $Withdrawn($Command)
            && $KV->Connection->connected === false
            && $closed
            && $rest === ''
            && $Census($Pool) === $census(0, 0, 0, 0, 0);

         $Next = $KV->command('GET', ['n']);
         $free = $Next->state === OperationStates::Queued
            && $Next->Connection === $KV->Connection
            && microtime(true) < $Command->deadline
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         // @@ Bounded: a loopback dial settles in a couple of turns; 200 × 5 ms
         //    is a ceiling, not the expected path.
         for ($turn = 0; $turn < 200 && $Next->state !== OperationStates::Reading && $Next->finished === false; $turn++) {
            $KV->advance($Next);

            if ($Next->state !== OperationStates::Reading) {
               usleep(5_000);
            }
         }

         $session = $Next->state === OperationStates::Reading
            ? @stream_socket_accept($Trap, 2.0)
            : false;
         $sent = '';

         if (is_resource($session)) {
            stream_set_blocking($session, false);

            // @@ Bounded: the command frame is already flushed.
            for ($turn = 0; $turn < 100 && $sent === ''; $turn++) {
               $sent = (string) fread($session, 8192);

               if ($sent === '') {
                  usleep(1_000);
               }
            }
         }

         yield assert(
            assertion: $reading
               && $dropped
               && $free
               && $sent === "*2\r\n\$3\r\nGET\r\n\$1\r\nn\r\n"
               && $Census($Pool) === $census(1, 0, 1, 0, 0),
            description: 'A withdrawn Redis reader drops its session without holding its slot: the next command dials at once; state='
               . json_encode($Next->state->name) . ', census=' . json_encode($Census($Pool))
         );

         $Pool->withdraw($Next);

         if (is_resource($session)) {
            fclose($session);
         }

         // @ (i) A sole writer withdrawn mid-flush holds its slot as a reader
         //   does. Parse, Bind and Execute are on the wire and only the Sync is
         //   left, so the server already runs the statement while the
         //   operation is still Querying. A half-written stream cannot be
         //   finished for nobody, so the session drops.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $socket = $SQL->Connection->socket;
         $capacity = 0;

         // @@ Bounded: the most one write puts into this socketpair while its
         //    peer reads nothing — probed with junk the peer drains again,
         //    growing until a write comes back short. A socketpair takes a few
         //    hundred KiB; 16 MiB is a ceiling, not the expected path.
         for ($size = 65536; $capacity === 0 && $size <= 16_777_216 && is_resource($socket); $size *= 4) {
            $accepted = (int) @fwrite($socket, str_repeat('j', $size));

            // @@ Bounded: the peer holds at most what that write accepted.
            for ($turn = 0; $turn < 512 && (string) fread($peer, 65536) !== ''; $turn++) {
               // drain the junk
            }

            if ($accepted < $size) {
               $capacity = $accepted;
            }
         }

         // ! A Bind parameter pads the batch to that capacity plus the Sync, so
         //   the flush stops right in front of it. The same statement composed
         //   by a spare driver around an empty parameter is the batch without
         //   its padding.
         $statement = 'UPDATE jam SET v = $1';
         $Spare = new PostgreSQL($SQL->SQLConfig, new Connection($SQL->SQLConfig));
         $bare = strlen($Spare->query($statement, [''])->write);
         $sync = strlen(Encoder::SYNC_BYTES);
         $Jammed = $SQL->query($statement, [str_repeat('j', max(0, $capacity + $sync - $bare))]);
         $batch = $Jammed->write;
         $SQL->advance($Jammed);
         $Driver = $Jammed->Protocol;
         $credit = $Driver instanceof PostgreSQL
            ? [
               (new ReflectionProperty(PostgreSQL::class, 'writing'))->getValue($Driver) === $Jammed,
               (new ReflectionProperty(PostgreSQL::class, 'wrote'))->getValue($Driver),
            ]
            : [false, -1];
         $querying = $capacity > 0
            && strlen($batch) === $capacity + $sync
            && $Jammed->state === OperationStates::Querying
            && $Jammed->write === Encoder::SYNC_BYTES
            && $credit === [true, $capacity]
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $Pool->withdraw($Jammed);
         [$rest, $closed] = $Hangup($peer);
         fclose($peer);

         $Next = $SQL->query('SELECT 9 AS value');
         $SQL->advance($Next);
         $held = microtime(true) < $Jammed->deadline;
         $parked = $Next->state === OperationStates::Pending
            && $Next->Connection === null
            && $Census($Pool) === $census(0, 0, 0, 1, 0);
         $read = [$Trap];
         $write = [];
         $except = [];
         $dialled = @stream_select($read, $write, $except, 0, 20_000);

         yield assert(
            assertion: $querying
               && $Withdrawn($Jammed)
               && $SQL->Connection->connected === false
               && $closed
               && $rest === substr($batch, 0, -$sync)
               && $held
               && $parked
               && $dialled === 0,
            description: 'A withdrawn sole writer with only its Sync unsent drops its session but holds its slot: the next query parks and no session is dialled; capacity='
               . json_encode($capacity) . ', credit=' . json_encode($credit) . ', census=' . json_encode($Census($Pool))
               . ', held=' . json_encode($held) . ', dialled=' . json_encode($dialled)
         );

         // @ The writer's deadline passes — moved there, never waited out.
         $lapsed = $Lapse($Pool, $Jammed) === 1;
         [$session, $startup] = $Dial($SQL, $Next);

         yield assert(
            assertion: $lapsed
               && $Next->state === OperationStates::Authenticating
               && $Next->Connection === $SQL->Connection
               && is_resource($session)
               && substr($startup, 4, 4) === pack('N', 196608)
               && $Census($Pool) === $census(1, 0, 1, 0, 0),
            description: 'Once the withdrawn writer\'s deadline passes, the parked query dials the freed slot; census='
               . json_encode($Census($Pool)) . ', lapsed=' . json_encode($lapsed)
         );

         $Pool->withdraw($Next);

         if (is_resource($session)) {
            fclose($session);
         }

         // # 11. The facade withdraws a writer before the reader ahead of it
         //   The writer holds the write stream with nothing credited, so the
         //   driver counts it as the reader who will drain the answer ahead.
         //   Withdrawn reader-first, that answer goes to a stand-in — then the
         //   writer leaves with nothing sent, and nobody ever reads the
         //   stand-in: the slot stays busy for good. Named reader-first on
         //   purpose; Database::withdraw() must reorder it.
         [$SQL, $peer] = $Open('pgsql', 1);
         $Pool = $SQL->Pool;
         $Reader = $SQL->query('SELECT 8 AS value');
         $SQL->advance($Reader);
         $wire = (string) fread($peer, 8192);

         // @ Jam the client side: the writer behind it cannot flush one byte.
         $socket = $SQL->Connection->socket;
         $junk = str_repeat('j', 65536);

         // @@ Bounded: a socketpair buffer takes a few hundred KiB.
         for ($turn = 0; $turn < 64 && is_resource($socket) && @fwrite($socket, $junk) > 0; $turn++) {
            // fill the socket buffer
         }

         $Writer = $SQL->query('UPDATE w SET v = 1');
         $SQL->advance($Writer);
         $Driver = $Writer->Protocol;
         $credit = $Driver instanceof PostgreSQL
            ? [
               (new ReflectionProperty(PostgreSQL::class, 'writing'))->getValue($Driver) === $Writer,
               (new ReflectionProperty(PostgreSQL::class, 'wrote'))->getValue($Driver),
            ]
            : [false, -1];
         $staged = $Reader->state === OperationStates::Reading
            && $Writer->state === OperationStates::Querying
            && $Writer->Connection === $Reader->Connection
            && $credit === [true, 0]
            && str_contains($wire, 'SELECT 8 AS value')
            && $Census($Pool) === $census(1, 0, 1, 0, 0);

         $SQL->withdraw($Reader, $Writer);
         [$rest, $closed] = $Hangup($peer);

         yield assert(
            assertion: $staged
               && $Withdrawn($Reader)
               && $Withdrawn($Writer)
               && $SQL->Connection->connected === false
               && $closed
               && str_contains($rest, 'UPDATE w') === false
               && $Census($Pool) === $census(0, 0, 0, 0, 0),
            description: 'Database::withdraw() takes a zero-credit writer out before the reader named ahead of it, so the session drops; credit='
               . json_encode($credit) . ', census=' . json_encode($Census($Pool))
         );

         fclose($peer);
      }
      finally {
         Emitter::$Instance = $Previous;
         fclose($Trap);
      }
   }
);
