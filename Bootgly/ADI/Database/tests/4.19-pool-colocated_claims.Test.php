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
use function assert;
use function chr;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function gc_collect_cycles;
use function is_resource;
use function json_encode;
use function microtime;
use function pack;
use function str_contains;
use function str_repeat;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use function strpos;
use function substr;
use function usleep;
use ReflectionProperty;
use WeakMap;
use WeakReference;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


/**
 * An operation the pool hands a connection holds a claim on it until the
 * claim settles. A driver sees only what reached its pipeline: an operation
 * co-located but not flushed yet — still Queued, or parked behind another
 * caller's write — is invisible to `Driver::check()`. Handing that connection
 * to a parked BEGIN let the co-located statement flush behind it, inside
 * another caller's transaction (PostgreSQL ran it under the same xid; MySQL
 * lost its write with that transaction's rollback). The claim keeps exclusive
 * operations off the connection — and nothing else: a claimant its caller
 * dropped must not pin the connection busy, and a parked BEGIN must wait for
 * the claim without spinning the worker. Every route that settles a claim is
 * checked to leave nothing behind.
 */
return new Test(
   description: 'Pool: an exclusive operation is never handed an idle connection an unflushed co-located operation still claims',
   test: function () {
      /**
       * Opens a pooled SQL database over a socketpair, with the peer alongside.
       *
       * @return array{SQL, resource}
       */
      $Open = static function (string $driver, int $max = 1): array {
         [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
         stream_set_blocking($client, false);
         stream_set_blocking($peer, false);

         $Database = new SQL([
            'driver' => $driver,
            'host' => '127.0.0.1',
            'port' => 1,
            'timeout' => 30.0,
            'secure' => ['mode' => 'disable'],
            'pool' => ['min' => 0, 'max' => $max],
         ]);
         $Database->Connection->attach($client);

         return [$Database, $peer];
      };
      /**
       * The pool census every leg asserts, with the claims still unsettled.
       *
       * @return array{created:int,idle:int,busy:int,pending:int,locked:int,claims:int}
       */
      $Census = static function (Pool $Pool): array {
         $locked = (array) (new ReflectionProperty(Pool::class, 'locked'))->getValue($Pool);
         $Claims = (array) (new ReflectionProperty(Pool::class, 'claims'))->getValue($Pool);
         $claims = 0;

         // @@ Unfinished claimants only: a finished one never writes again.
         foreach ($Claims as $Map) {
            foreach ($Map as $Claimant => $claimed) {
               if ($Claimant instanceof Operation && $Claimant->finished === false) {
                  $claims++;
               }
            }
         }

         return [
            'created' => $Pool->created,
            'idle' => count($Pool->idle),
            'busy' => count($Pool->busy),
            'pending' => count($Pool->pending),
            'locked' => count($locked),
            'claims' => $claims,
         ];
      };
      $census = static fn (int $created, int $idle, int $busy, int $pending, int $locked, int $claims = 0): array => [
         'created' => $created,
         'idle' => $idle,
         'busy' => $busy,
         'pending' => $pending,
         'locked' => $locked,
         'claims' => $claims,
      ];
      // ! Every claim the pool still records, finished claimants included.
      $Raw = static function (Pool $Pool): int {
         $Claims = (array) (new ReflectionProperty(Pool::class, 'claims'))->getValue($Pool);
         $entries = 0;

         foreach ($Claims as $Map) {
            $entries += $Map instanceof WeakMap ? count($Map) : 0;
         }

         return $entries;
      };
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
      // ! The answer to the session reset PostgreSQL writes behind a session it
      //   keeps: one CommandComplete per statement, one ReadyForQuery.
      $Unlocked = (static function (): string {
         $selected = pack('N', strlen('SELECT 1') + 5);
         $unlistened = pack('N', strlen('UNLISTEN') + 5);
         $ready = pack('N', 5);

         return "C{$selected}SELECT 1\0C{$unlistened}UNLISTEN\0Z{$ready}I";
      })();
      // ! A PostgreSQL ErrorResponse followed by its ReadyForQuery.
      $Error = static function (string $text): string {
         $payload = "SERROR\0C42601\0M{$text}\0\0";
         $size = pack('N', strlen($payload) + 4);
         $ready = pack('N', 5);

         return "E{$size}{$payload}Z{$ready}I";
      };
      // ! One MySQL packet: 3-byte little-endian length, sequence id, payload.
      $Frame = static function (string $payload, int $sequence): string {
         $length = substr(pack('V', strlen($payload)), 0, 3);
         $id = chr($sequence);

         return "{$length}{$id}{$payload}";
      };
      // ! A MySQL OK packet answering a command.
      $OK = $Frame("\x00\x00\x00\x02\x00\x00\x00", 1);
      /**
       * Drives one operation until it finishes, reading what the peer received.
       *
       * @param resource $peer
       */
      $Drive = static function (SQL $Database, Operation $Operation, $peer): string {
         $wire = '';

         // @@ Bounded: a socketpair delivers each answer to one read.
         for ($turn = 0; $turn < 50 && $Operation->finished === false; $turn++) {
            $Database->advance($Operation);
            $wire = "{$wire}" . (string) fread($peer, 8192);

            if ($Operation->finished === false) {
               usleep(1_000);
            }
         }

         return $wire;
      };

      // # 1. A Queued co-located statement keeps a parked BEGIN off its connection
      //   A reads; X is co-located behind it and assigned, but its caller has
      //   not advanced it yet; BEGIN parks, since it never co-locates. Each way
      //   A can leave — answered, or withdrawn by a caller that left — idles
      //   the connection, but BEGIN is not handed it while X is still owed it.
      foreach (['answered', 'withdrawn'] as $route) {
         [$SQL, $peer] = $Open('pgsql');
         $Pool = $SQL->Pool;
         $A = $SQL->query('SELECT 1 AS value');
         $SQL->advance($A);
         fread($peer, 8192);
         $X = $SQL->query('SELECT 2 AS value');
         $Transaction = $SQL->begin();
         $Begin = $Transaction->Operation;
         $staged = $A->state === OperationStates::Reading
            && $X->state === OperationStates::Queued
            && $X->Connection === $A->Connection
            && $Begin !== null
            && $Begin->state === OperationStates::Pending
            && $Census($Pool) === $census(1, 0, 1, 1, 0, 2);

         if ($route === 'answered') {
            fwrite($peer, $Select(1));
            $Drive($SQL, $A, $peer);
         }
         else {
            $SQL->withdraw($A);
         }

         $held = $Begin !== null
            && $Begin->state === OperationStates::Pending
            && $Begin->Connection === null
            && $Census($Pool) === $census(1, 1, 0, 1, 0, 1);

         yield assert(
            assertion: $staged && $held,
            description: "PostgreSQL: A {$route}, the parked BEGIN is not handed the connection the Queued co-located X still claims; census="
               . json_encode($Census($Pool))
         );

         // @ X's caller advances it: X flushes first, its answer (behind A's,
         //   when A left a stand-in) settles its claim, and only then is the
         //   connection lent to BEGIN, which writes after X.
         $SQL->advance($X);
         $wire = (string) fread($peer, 8192);
         $owed = $route === 'withdrawn' ? "{$Select(1)}{$Unlocked}" : '';
         $answer = $Select(2);
         fwrite($peer, "{$owed}{$answer}");
         $wire = "{$wire}{$Drive($SQL, $X, $peer)}";
         $promoted = $Begin !== null
            && $Begin->Connection === $X->Connection
            && $Census($Pool) === $census(1, 0, 1, 0, 1, 1);

         if ($Begin !== null) {
            fwrite($peer, $Complete('BEGIN'));
            $wire = "{$wire}{$Drive($SQL, $Begin, $peer)}";
         }

         $x = strpos($wire, 'SELECT 2 AS value');
         $begin = strpos($wire, 'BEGIN');

         yield assert(
            assertion: $X instanceof SQLOperation
               && $X->error === null
               && $X->rows === [['value' => 2]]
               && $promoted
               && $x !== false
               && $begin !== false
               && $x < $begin
               && $Begin?->error === null
               && $Begin->status === 'BEGIN'
               && $Census($Pool) === $census(1, 0, 1, 0, 1),
            description: "PostgreSQL: A {$route}, X runs on its own before BEGIN is lent the connection; x="
               . json_encode($x) . ', begin=' . json_encode($begin) . ', census=' . json_encode($Census($Pool))
         );

         fclose($peer);
         $SQL->Connection->disconnect();
      }

      // @ MySQL: the same shape. A co-located command only joins the FIFO when
      //   its caller first advances it, so the driver cannot see it either.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $A = $SQL->query('UPDATE a SET v = 1');
      $SQL->advance($A);
      fread($peer, 8192);
      $X = $SQL->query('INSERT INTO b VALUES (2)');
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;
      fwrite($peer, $OK);
      $Drive($SQL, $A, $peer);
      $held = $X->state === OperationStates::Queued
         && $Begin !== null
         && $Begin->state === OperationStates::Pending
         && $Census($Pool) === $census(1, 1, 0, 1, 0, 1);

      $SQL->advance($X);
      $wire = (string) fread($peer, 8192);
      fwrite($peer, $OK);
      $wire = "{$wire}{$Drive($SQL, $X, $peer)}";

      if ($Begin !== null) {
         fwrite($peer, $OK);
         $wire = "{$wire}{$Drive($SQL, $Begin, $peer)}";
      }

      $x = strpos($wire, 'INSERT INTO b');
      $begin = strpos($wire, 'BEGIN');

      yield assert(
         assertion: $held
            && $X->error === null
            && $x !== false
            && $begin !== false
            && $x < $begin
            && $Begin?->error === null
            && $Census($Pool) === $census(1, 0, 1, 0, 1),
         description: 'MySQL: BEGIN is not handed the connection a Queued co-located command still claims; it runs after that command; x='
            . json_encode($x) . ', begin=' . json_encode($begin) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 2. An exclusive operation is never handed a claimed connection
      //   However the claimed connection reached `idle` — here attach(), which
      //   puts it there as is — BEGIN is kept off it while the claim is live.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $X = $SQL->query('SELECT 3 AS value');
      $Pool->attach($SQL->Connection);
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;

      yield assert(
         assertion: $X->state === OperationStates::Queued
            && $Begin !== null
            && $Begin->state === OperationStates::Pending
            && $Begin->Connection === null,
         description: 'An idle connection an unflushed operation still claims is never handed to BEGIN; begin='
            . json_encode($Begin?->state->name)
      );

      $Pool->withdraw($X);

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 3. Every route that settles a claim leaves nothing behind
      //   A claim that outlived its operation would keep every BEGIN off the
      //   connection: after each route the census, and the claims recorded,
      //   are back where they started.
      $routes = [];

      // @ Answered.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Answered = $SQL->query('SELECT 4 AS value');
      $SQL->advance($Answered);
      fread($peer, 8192);
      fwrite($peer, $Select(4));
      $Drive($SQL, $Answered, $peer);
      $routes['answered'] = $Answered->error === null
         && $Raw($Pool) === 0
         && $Census($Pool) === $census(1, 1, 0, 0, 0);

      // @ Refused by the server.
      $Refused = $SQL->query('SELECT bad');
      $SQL->advance($Refused);
      fread($peer, 8192);
      fwrite($peer, $Error('syntax error'));
      $Drive($SQL, $Refused, $peer);
      $routes['refused'] = $Refused->error === 'syntax error'
         && $Raw($Pool) === 0
         && $Census($Pool) === $census(1, 1, 0, 0, 0);

      // @ Withdrawn while Queued, and while Reading (its stand-in drained by the next).
      $Queued = $SQL->query('SELECT 5 AS value');
      $Pool->withdraw($Queued);
      $routes['withdrawn queued'] = $Census($Pool) === $census(1, 1, 0, 0, 0);

      $Reading = $SQL->query('SELECT 6 AS value');
      $SQL->advance($Reading);
      fread($peer, 8192);
      $Pool->withdraw($Reading);
      $routes['withdrawn reading'] = $Census($Pool) === $census(1, 1, 0, 0, 0);

      $Next = $SQL->query('SELECT 7 AS value');
      $SQL->advance($Next);
      fread($peer, 8192);
      $first = $Select(6);
      $second = $Select(7);
      fwrite($peer, "{$first}{$Unlocked}{$second}");
      $Drive($SQL, $Next, $peer);
      $routes['drained'] = $Next instanceof SQLOperation
         && $Next->rows === [['value' => 7]]
         && $Census($Pool) === $census(1, 1, 0, 0, 0);

      // @ Cancelled before reaching the server.
      $Cancelled = $SQL->query('SELECT 8 AS value');
      $Pool->cancel($Cancelled);
      $routes['cancelled'] = $Raw($Pool) === 0 && $Census($Pool) === $census(1, 1, 0, 0, 0);

      // @ Expired behind a live head: its stand-in waits, the head settles both.
      $Head = $SQL->query('SELECT 9 AS value');
      $SQL->advance($Head);
      fread($peer, 8192);
      $Late = $SQL->query('SELECT 10 AS value');
      $SQL->advance($Late);
      fread($peer, 8192);
      (new ReflectionProperty(Operation::class, 'deadline'))->setValue($Late, microtime(true) - 1.0);
      $SQL->advance($Late);
      $first = $Select(9);
      $second = $Select(10);
      fwrite($peer, "{$first}{$second}");
      $Drive($SQL, $Head, $peer);
      $routes['expired'] = $Late->state === OperationStates::Failed
         && $Head->error === null
         && $Census($Pool) === $census(1, 1, 0, 0, 0);

      // @ Finished out of band while still claimed — a sibling taking over its
      //   stale write expires it without a release — then withdrawn, or
      //   cancelled, by a caller that is leaving. Nothing released its claim,
      //   but a finished claimant never writes again: a BEGIN is handed the
      //   idle connection meanwhile.
      foreach (['withdrawn', 'cancelled'] as $leaving) {
         $Front = $SQL->query('SELECT 14 AS value');
         $SQL->advance($Front);
         fread($peer, 8192);
         $Stray = $SQL->query('SELECT 15 AS value');
         fwrite($peer, $Select(14));
         $Drive($SQL, $Front, $peer);
         $kept = $Census($Pool) === $census(1, 1, 0, 0, 0, 1);
         $Stray->fail('Finished by something other than this pool.');
         $recorded = $Raw($Pool) === 1;
         $Transaction = $SQL->begin();
         $Begin = $Transaction->Operation;
         $handed = $Begin !== null
            && $Begin->Connection === $SQL->Connection
            && $Begin->state === OperationStates::Queued;

         if ($Begin !== null) {
            $Pool->withdraw($Begin);
         }

         if ($leaving === 'withdrawn') {
            $Pool->withdraw($Stray);
         }
         else {
            $Pool->cancel($Stray);
         }

         $routes["out of band, {$leaving}"] = $kept
            && $recorded
            && $handed
            && $Stray->error === 'Finished by something other than this pool.'
            && $Census($Pool) === $census(1, 1, 0, 0, 0);
      }

      // @ Failed with every sibling when the peer hangs up: the connection goes.
      $Front = $SQL->query('SELECT 11 AS value');
      $SQL->advance($Front);
      $Back = $SQL->query('SELECT 12 AS value');
      $SQL->advance($Back);
      fread($peer, 8192);
      fclose($peer);

      // @@ Bounded: the hangup is read on the first turn.
      for ($turn = 0; $turn < 50 && $Front->finished === false; $turn++) {
         $SQL->advance($Front);
      }

      $routes['severed'] = $Front->state === OperationStates::Failed
         && $Back->state === OperationStates::Failed
         && $Census($Pool) === $census(0, 0, 0, 0, 0);

      // @ Failed over: the claim on the first pool settles before the retry
      //   claims a connection on the fallback pool, and that one settles too.
      [$Primary, $peer] = $Open('pgsql');
      [$Replica, $other] = $Open('pgsql');
      $Retried = $Primary->query('SELECT 13 AS value');
      $Retried->FallbackPool = $Replica->Pool;
      $Primary->advance($Retried);
      fread($peer, 8192);
      fwrite($peer, $Error('replica lag'));
      $Primary->advance($Retried);
      $moved = $Retried->Pool === $Replica->Pool
         && $Retried->finished === false
         && $Census($Primary->Pool) === $census(1, 1, 0, 0, 0)
         && $Census($Replica->Pool) === $census(1, 0, 1, 0, 0, 1);
      $Primary->advance($Retried);
      fread($other, 8192);
      fwrite($other, $Select(13));
      $Drive($Primary, $Retried, $other);
      $routes['failed over'] = $moved
         && $Retried instanceof SQLOperation
         && $Retried->rows === [['value' => 13]]
         && $Census($Primary->Pool) === $census(1, 1, 0, 0, 0)
         && $Census($Replica->Pool) === $census(1, 1, 0, 0, 0);

      fclose($peer);
      fclose($other);
      $Primary->Connection->disconnect();
      $Replica->Connection->disconnect();

      $settled = [];

      foreach ($routes as $name => $passed) {
         if ($passed === false) {
            $settled[] = $name;
         }
      }

      yield assert(
         assertion: $settled === [] && count($routes) === 11,
         description: 'Every route — answered, refused, withdrawn, drained, cancelled, expired, finished out of band, severed, failed over — settles its claim; unsettled='
            . json_encode($settled)
      );

      // # 4. A claimant its caller dropped never keeps BEGIN off the connection
      //   A request awaits only part of what it dispatched — a paginated page
      //   awaits its items, never the COUNT(*) sent alongside — and its Fiber
      //   dies. The co-located statement it left behind stays Queued and
      //   claimed while anything keeps it alive, so nothing may: neither the
      //   pool, which then held the connection busy for every later BEGIN
      //   (`no capacity` until the worker restarts), nor the driver, whose
      //   record of a warm Bind kept the operation itself alive.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $statement = 'SELECT $1::int AS value';
      $A = $SQL->query($statement, [1]);
      $SQL->advance($A);
      fread($peer, 8192);
      $X = $SQL->query($statement, [2]);
      $warm = $X instanceof SQLOperation
         && $X->prepared
         && $X->statement === $A->statement
         && $X->write !== ''
         && $X->write[0] === 'B';
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;

      // @ A's whole answer: ParseComplete, ParameterDescription, BindComplete,
      //   RowDescription, one row, CommandComplete, ReadyForQuery.
      $parsed = '1' . pack('N', 4);
      $described = pack('n', 1) . pack('N', 23);
      $parameters = 't' . pack('N', strlen($described) + 4) . $described;
      $bound = '2' . pack('N', 4);
      $answer = $Select(1);
      fwrite($peer, "{$parsed}{$parameters}{$bound}{$answer}");
      $Drive($SQL, $A, $peer);
      $parked = $Begin !== null
         && $Begin->state === OperationStates::Pending
         && $Census($Pool) === $census(1, 1, 0, 1, 0, 1);

      // @ X's caller is gone: nothing but this local holds it.
      $Weak = WeakReference::create($X);
      unset($X);
      gc_collect_cycles();
      $collected = $Weak->get() === null;

      // @ Its own caller drives the parked BEGIN: assigned, then flushed.
      if ($Begin !== null) {
         $SQL->advance($Begin);
         $SQL->advance($Begin);
      }

      $wire = (string) fread($peer, 8192);

      yield assert(
         assertion: $A->error === null
            && $warm
            && $parked
            && $collected
            && $Begin?->Connection === $SQL->Connection
            && str_contains($wire, 'BEGIN')
            && $Census($Pool) === $census(1, 0, 1, 0, 1, 1),
         description: 'PostgreSQL: a warm co-located Bind its caller dropped is collected, and BEGIN takes the connection; collected='
            . json_encode($collected) . ', begin=' . json_encode($Begin?->state->name) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL: the same shape, with the co-located command never advanced.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $A = $SQL->query('UPDATE a SET v = 1');
      $SQL->advance($A);
      fread($peer, 8192);
      $X = $SQL->query('SELECT COUNT(*) FROM a');
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;
      fwrite($peer, $OK);
      $Drive($SQL, $A, $peer);
      $parked = $Begin !== null
         && $Begin->state === OperationStates::Pending
         && $Census($Pool) === $census(1, 1, 0, 1, 0, 1);

      $Weak = WeakReference::create($X);
      unset($X);
      gc_collect_cycles();
      $collected = $Weak->get() === null;

      // @ Its own caller drives the parked BEGIN: assigned, then flushed.
      if ($Begin !== null) {
         $SQL->advance($Begin);
         $SQL->advance($Begin);
      }

      $wire = (string) fread($peer, 8192);

      yield assert(
         assertion: $A->error === null
            && $parked
            && $collected
            && $Begin?->Connection === $SQL->Connection
            && str_contains($wire, 'BEGIN')
            && $Census($Pool) === $census(1, 0, 1, 0, 1, 1),
         description: 'MySQL: a co-located command its caller dropped never keeps BEGIN off the connection; begin='
            . json_encode($Begin?->state->name) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 5. A parked BEGIN waits for a live claim without spinning the worker
      //   The answered connection is idle, but claimed: promote() shifts the
      //   BEGIN, assign() parks it again, and `idle` is still non-empty. One
      //   pass over the parked operations, then the release returns. Spinning
      //   would hold this call until the BEGIN's own deadline, and fail it.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $SQL->Config->timeout = 2.0;
      $A = $SQL->query('SELECT 1 AS value');
      $SQL->advance($A);
      fread($peer, 8192);
      $X = $SQL->query('SELECT 2 AS value');
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;
      $SQL->Config->timeout = 30.0;
      fwrite($peer, $Select(1));
      $started = microtime(true);
      $Drive($SQL, $A, $peer);
      $elapsed = microtime(true) - $started;
      $waiting = $Begin !== null
         && $Begin->finished === false
         && $Begin->state === OperationStates::Pending
         && $Census($Pool) === $census(1, 1, 0, 1, 0, 1);

      // @ The claimant leaves: the BEGIN proceeds on the next release.
      $Pool->withdraw($X);
      $wire = (string) fread($peer, 8192);

      yield assert(
         assertion: $A->error === null
            && $elapsed < 1.0
            && $waiting
            && $Begin?->Connection === $SQL->Connection
            && str_contains($wire, 'BEGIN')
            && $Census($Pool) === $census(1, 0, 1, 0, 1, 1),
         description: 'A parked BEGIN waits for the claimed idle connection without spinning, then takes it once the claim settles; elapsed='
            . json_encode($elapsed) . ', begin=' . json_encode($Begin?->state->name) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 6. A dropped session takes its claims with it
      //   The peer hangs up while A reads and X is still Queued behind it. X
      //   never ran on that session and never will — its driver is torn down —
      //   so once the pool rebuilds the same Connection object, X's claim must
      //   not keep a BEGIN off the new session.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $A = $SQL->query('SELECT 1 AS value');
      $SQL->advance($A);
      fread($peer, 8192);
      $X = $SQL->query('SELECT 2 AS value');
      fclose($peer);

      // @@ Bounded: the hangup is read on the first turn.
      for ($turn = 0; $turn < 50 && $A->finished === false; $turn++) {
         $SQL->advance($A);
      }

      $dropped = $A->state === OperationStates::Failed
         && $X->finished === false
         && $X->Connection === $SQL->Connection
         && $Census($Pool) === $census(0, 0, 0, 0, 0);

      // @ A new socket on the same Connection object: the pool rebuilds it.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $SQL->Connection->attach($client);
      $Rebuilt = $SQL->query('SELECT 3 AS value');
      $SQL->advance($Rebuilt);
      fread($peer, 8192);
      fwrite($peer, $Select(3));
      $Drive($SQL, $Rebuilt, $peer);
      $Transaction = $SQL->begin();
      $Begin = $Transaction->Operation;

      yield assert(
         assertion: $dropped
            && $Rebuilt->error === null
            && $Rebuilt->Connection === $X->Connection
            && $Begin?->Connection === $SQL->Connection
            && $Begin->state === OperationStates::Queued,
         description: 'A claim on a dropped session does not survive into the session rebuilt on the same Connection object; begin='
            . json_encode($Begin?->state->name)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 7. A claimant re-dispatched elsewhere no longer claims this connection
      //   X failed out of band and its fallback pool took it over before this
      //   pool settled its claim: X is unfinished again, but on another
      //   connection of another pool. Its record here is stale, so it never
      //   keeps a BEGIN off this pool's idle connection.
      [$Primary, $peer] = $Open('pgsql');
      [$Replica, $other] = $Open('pgsql');
      $Pool = $Primary->Pool;
      $A = $Primary->query('SELECT 1 AS value');
      $Primary->advance($A);
      fread($peer, 8192);
      $X = $Primary->query('SELECT 2 AS value');
      $X->FallbackPool = $Replica->Pool;
      $X->fail('Failed by something other than this pool.');
      $Primary->advance($X);
      $moved = $X->Pool === $Replica->Pool
         && $X->finished === false
         && $X->Connection === $Replica->Connection;
      fwrite($peer, $Select(1));
      $Drive($Primary, $A, $peer);
      $stale = $Raw($Pool) === 1;
      $Transaction = $Primary->begin();
      $Begin = $Transaction->Operation;

      yield assert(
         assertion: $moved
            && $stale
            && $Begin?->Connection === $Primary->Connection
            && $Begin->state === OperationStates::Queued,
         description: 'A claimant re-dispatched to its fallback pool never keeps a BEGIN off the pool it left; begin='
            . json_encode($Begin?->state->name) . ', moved=' . json_encode($moved)
      );

      fclose($peer);
      fclose($other);
      $Primary->Connection->disconnect();
      $Replica->Connection->disconnect();

      // # 8. A collected warm-Bind holder holds no statement name
      //   The driver records each warm Bind against the name it binds, so a
      //   queued Close waits for its bytes. Collected, that operation sends
      //   nothing: the Close ships with the next batch, and a withdrawn Parse
      //   strips its holders without tripping on the collected one.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);

      // ! statements => 0 evicts at every ReadyForQuery, queueing the Close.
      $SQL = new SQL([
         'driver' => 'pgsql',
         'host' => '127.0.0.1',
         'port' => 1,
         'timeout' => 30.0,
         'statements' => 0,
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
      ]);
      $SQL->Connection->attach($client);
      $statement = 'SELECT $1::int AS a';
      $First = $SQL->query($statement, [1]);
      $SQL->advance($First);
      fread($peer, 8192);
      $Holder = $SQL->query($statement, [2]);
      $warm = $Holder instanceof SQLOperation && $Holder->write !== '' && $Holder->write[0] === 'B';
      $Weak = WeakReference::create($Holder);
      unset($Holder);
      gc_collect_cycles();
      $collected = $Weak->get() === null;

      $parsed = '1' . pack('N', 4);
      $described = pack('n', 1) . pack('N', 23);
      $parameters = 't' . pack('N', strlen($described) + 4) . $described;
      $bound = '2' . pack('N', 4);
      $answer = $Complete('SELECT 1');
      fwrite($peer, "{$parsed}{$parameters}{$bound}{$answer}");
      $Drive($SQL, $First, $peer);

      $Other = $SQL->query('SELECT $1::int AS c', [3]);
      $SQL->advance($Other);
      $wire = (string) fread($peer, 8192);
      $close = (new Encoder)->encode(Encoder::CLOSE, [
         'type' => 'S',
         'name' => $First instanceof SQLOperation ? $First->statement : '',
      ]);

      yield assert(
         assertion: $warm
            && $collected
            && $First->error === null
            && substr($wire, 0, strlen($close)) === $close,
         description: 'A queued Close ships with the next batch once the warm Bind holding its name is collected; collected='
            . json_encode($collected)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ A Parse that never left — its batch holds the write stream with
      //   nothing credited — is withdrawn after the sibling that Bound its
      //   name warm was collected.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $socket = $SQL->Connection->socket;
      $junk = str_repeat('j', 65536);

      // @@ Bounded: a socketpair buffer takes a few hundred KiB.
      for ($turn = 0; $turn < 64 && is_resource($socket) && @fwrite($socket, $junk) > 0; $turn++) {
         // fill the socket buffer
      }

      $statement = 'SELECT $1::int AS b';
      $Composer = $SQL->query($statement, [1]);
      $SQL->advance($Composer);
      $Holder = $SQL->query($statement, [2]);
      $warm = $Composer->state === OperationStates::Querying
         && $Holder instanceof SQLOperation
         && $Holder->prepared
         && $Holder->write !== ''
         && $Holder->write[0] === 'B';
      $Weak = WeakReference::create($Holder);
      unset($Holder);
      gc_collect_cycles();
      $collected = $Weak->get() === null;
      $Pool->withdraw($Composer);
      $Driver = $SQL->Connection->Protocol;
      $held = $Driver instanceof PostgreSQL
         ? (array) (new ReflectionProperty(PostgreSQL::class, 'Holders'))->getValue($Driver)
         : null;

      yield assert(
         assertion: $warm
            && $collected
            && $Composer->state === OperationStates::Failed
            && $held === []
            && $Census($Pool) === $census(1, 1, 0, 0, 0),
         description: 'A withdrawn Parse strips its warm-Bind holders, a collected one included; holders='
            . json_encode($held) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();
   }
);
