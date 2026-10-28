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
use function is_resource;
use function json_encode;
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

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config as SQLConfig;
use Bootgly\ADI\Databases\SQL\Drivers\MySQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Drivers\PostgreSQL\Encoder;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;


/**
 * A session the pool keeps for a withdrawn statement may carry what that
 * statement leaves behind once its caller is gone: an advisory lock granted
 * after the caller stopped waiting, a named lock, a LISTEN. The pool writes a
 * session reset as it returns that session to idle — at once when nobody else
 * reads there, so the server runs it right after the abandoned statement even
 * if nobody reads; after the last live caller pipelined there otherwise — and
 * the next reader absorbs its answer like a stand-in's. These legs pin the
 * wire: when the reset goes out, that it never interleaves with a batch still
 * being flushed nor overtakes a caller still reading, that one covers many
 * withdrawals, that a server refusing it is tolerated, that a reset that
 * cannot be written takes down only a session nobody else uses, that a
 * tainted connection is reset before it is lent and takes no new caller
 * before that, and that answers read ahead of a batch still flushing reach
 * it.
 */
return new Test(
   description: 'Pool: a kept session is reset behind everything it owes before anyone else runs there',
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
       * The pool census.
       *
       * @return array{created:int,idle:int,busy:int}
       */
      $Census = static fn (Pool $Pool): array => [
         'created' => $Pool->created,
         'idle' => count($Pool->idle),
         'busy' => count($Pool->busy),
      ];
      // ! PostgreSQL: a statement answer, the reset, and the reset's answer.
      $Complete = static function (string $command): string {
         $size = pack('N', strlen($command) + 5);
         $ready = pack('N', 5);

         return "C{$size}{$command}\0Z{$ready}I";
      };
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
      $ResetPG = (new Encoder)->query('SELECT pg_advisory_unlock_all(); UNLISTEN *');
      $UnlockedPG = (static function (): string {
         $one = pack('n', 1);
         $attributes = pack('NnNnNn', 0, 0, 2278, 4, 0xFFFFFFFF, 0);
         $column = "{$one}pg_advisory_unlock_all\0{$attributes}";
         $described = pack('N', strlen($column) + 4);
         $value = pack('N', 0);
         $row = "{$one}{$value}";
         $sized = pack('N', strlen($row) + 4);
         $selected = pack('N', strlen('SELECT 1') + 5);
         $unlistened = pack('N', strlen('UNLISTEN') + 5);
         $ready = pack('N', 5);

         return "T{$described}{$column}D{$sized}{$row}C{$selected}SELECT 1\0C{$unlistened}UNLISTEN\0Z{$ready}I";
      })();
      // ! MySQL: one packet, the reset, an OK, an ERR, a one-row result set.
      $Frame = static function (string $payload, int $sequence): string {
         $length = substr(pack('V', strlen($payload)), 0, 3);
         $id = chr($sequence);

         return "{$length}{$id}{$payload}";
      };
      $ResetMY = $Frame("\x03DO RELEASE_ALL_LOCKS()", 0);
      $OK = $Frame("\x00\x00\x00\x02\x00\x00\x00", 1);
      $Refusal = $Frame("\xFF" . pack('v', 1305) . "#42000FUNCTION RELEASE_ALL_LOCKS does not exist", 1);
      $Value = static function (int $value) use ($Frame): string {
         $text = (string) $value;
         $attributes = pack('vVCvCCC', 45, 255, 3, 0, 0, 0, 0);
         $column = "\x03def\x02db\x05table\x05table\x05value\x05value\x0C{$attributes}";
         $eof = "\xFE\x00\x00\x00\x00";
         $width = chr(strlen($text));

         return $Frame("\x01", 1)
            . $Frame($column, 2)
            . $Frame($eof, 3)
            . $Frame("{$width}{$text}", 4)
            . $Frame($eof, 5);
      };
      /**
       * Reads whatever the peer holds right now, bounded.
       *
       * @param resource $peer
       */
      $Read = static function ($peer): string {
         $bytes = '';

         // @@ Bounded: a socketpair holds a few hundred KiB.
         for ($turn = 0; $turn < 512; $turn++) {
            $chunk = (string) fread($peer, 1_048_576);

            if ($chunk === '') {
               break;
            }

            $bytes = "{$bytes}{$chunk}";
         }

         return $bytes;
      };
      /**
       * Drives one operation until it finishes.
       */
      $Drive = static function (SQL $Database, Operation $Operation): void {
         // @@ Bounded: a socketpair delivers each answer to one read.
         for ($turn = 0; $turn < 50 && $Operation->finished === false; $turn++) {
            $Database->advance($Operation);

            if ($Operation->finished === false) {
               usleep(1_000);
            }
         }
      };
      /**
       * Fills the client's send buffer so nothing more can be written.
       */
      $Jam = static function (SQL $Database): void {
         $socket = $Database->Connection->socket;
         $junk = str_repeat('j', 65536);

         // @@ Bounded: a socketpair buffer takes a few hundred KiB.
         for ($turn = 0; $turn < 64 && is_resource($socket) && @fwrite($socket, $junk) > 0; $turn++) {
            // fill the socket buffer
         }
      };

      // # 1. A reset waits for every caller still reading the session
      //   The pool writes the reset as it returns the session to idle, once
      //   nobody owes work on it: while other callers still read there, it
      //   goes after the last of them. It then never lands inside their bytes,
      //   never fails them when it cannot be written, and never ends what
      //   their own statements take.

      // @ PostgreSQL: the reader is withdrawn while another caller's batch,
      //   larger than the socket buffers, is half on the wire. Nothing is
      //   written behind that batch while its caller still reads: the reset
      //   follows only that caller's release.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Reader = $SQL->query('SELECT 1 AS value');
      $SQL->advance($Reader);
      $Read($peer);
      $padding = str_repeat('y', 1024 * 1024);
      $Writer = $SQL->query("SELECT 2 AS value /* {$padding} */");
      $batch = $Writer instanceof SQLOperation ? $Writer->write : '';
      $SQL->advance($Writer);
      $partial = $Writer->state === OperationStates::Querying && $Writer->write !== '';

      $Pool->withdraw($Reader);
      $waiting = $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];
      $received = '';

      // @@ Bounded: the server reads the batch while its caller flushes it.
      for ($turn = 0; $turn < 5000 && strlen($received) < strlen($batch); $turn++) {
         $received = "{$received}{$Read($peer)}";
         $SQL->advance($Writer);
      }

      // ! The batch is whole on the wire and its caller still reads: nothing
      //   follows it yet.
      $SQL->advance($Writer);
      $behind = $Read($peer);
      $first = $Select(1);
      $second = $Select(2);
      fwrite($peer, "{$first}{$second}");
      $Drive($SQL, $Writer);
      $reset = $Read($peer);

      yield assert(
         assertion: $partial
            && $waiting
            && $received === $batch
            && $behind === ''
            && $Writer instanceof SQLOperation
            && $Writer->error === null
            && $Writer->rows === [['value' => 2]]
            && $reset === $ResetPG
            && $SQL->Connection->Protocol?->detached === true
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'PostgreSQL: a reader withdrawn while another batch is mid-flush leaves the reset to that caller\'s release — never inside the batch, nor behind it while it reads; received='
            . strlen($received) . '/' . strlen($batch) . ', behind=' . strlen($behind) . ', reset=' . strlen($reset)
            . ', error=' . json_encode($Writer->error)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ PostgreSQL: a live caller pipelined behind the withdrawn statement
      //   takes an advisory lock in its own statement. The reset is written
      //   only once that caller has its answer: it never ends, behind the
      //   caller's back, what the caller's statement just took.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Front = $SQL->query('SELECT 3 AS value');
      $Back = $SQL->query('SELECT pg_try_advisory_lock(4242) AS value');
      $SQL->advance($Front);
      $SQL->advance($Back);
      $wire = $Read($peer);
      $pipelined = $Front->state === OperationStates::Reading
         && $Back->state === OperationStates::Reading
         && $Back->Connection === $Front->Connection
         && str_contains($wire, 'pg_try_advisory_lock(4242)');

      $Pool->withdraw($Front);
      $early = $Read($peer);
      fwrite($peer, $Select(3));
      $SQL->advance($Back);
      $live = $Back->finished === false && $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];
      $middle = $Read($peer);
      fwrite($peer, $Select(1));
      $Drive($SQL, $Back);
      $reset = $Read($peer);

      yield assert(
         assertion: $pipelined
            && $Front->state === OperationStates::Failed
            && $early === ''
            && $live
            && $middle === ''
            && $Back instanceof SQLOperation
            && $Back->error === null
            && $Back->rows === [['value' => 1]]
            && $reset === $ResetPG
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'PostgreSQL: a live caller behind a withdrawn statement keeps what its own statement takes — the reset is written only after its answer; early='
            . strlen($early) . ', middle=' . strlen($middle) . ', reset=' . strlen($reset) . ', error=' . json_encode($Back->error)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL, through the pool: a sibling queued behind the withdrawn head
      //   has not written its command yet. It drains the head's answer,
      //   writes its own command and reads only its own answer; the reset
      //   follows once it has finished — never ahead of a command whose
      //   caller still waits for it.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $Head = $SQL->query('SELECT 11 AS value');
      $SQL->advance($Head);
      $Read($peer);
      $Behind = $SQL->query('SELECT 12 AS value');
      $SQL->advance($Behind);
      $Pool->withdraw($Head);
      $early = $Read($peer);
      fwrite($peer, $Value(11));

      // @@ Bounded: the head's answer sits in one socketpair buffer.
      for ($turn = 0; $turn < 50 && $Behind->state !== OperationStates::Reading && $Behind->finished === false; $turn++) {
         $SQL->advance($Behind);
      }

      $wire = $Read($peer);
      fwrite($peer, $Value(12));
      $Drive($SQL, $Behind);
      $reset = $Read($peer);

      yield assert(
         assertion: $early === ''
            && str_contains($wire, 'SELECT 12 AS value')
            && str_contains($wire, 'RELEASE_ALL_LOCKS') === false
            && $Behind instanceof SQLOperation
            && $Behind->error === null
            && $Behind->rows === [['value' => 12]]
            && $reset === $ResetMY
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'MySQL: a queued sibling writes its command and reads its own answer before the reset, which follows once it finished; early='
            . strlen($early) . ', reset=' . strlen($reset) . ', rows=' . json_encode($Behind->rows) . ', error=' . json_encode($Behind->error)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL, at the driver: a reset asked for while a command is half on
      //   the wire is refused, and nothing is written into that command —
      //   not even once the server has read part of it and the stream has
      //   room again.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $MySQLConnection = new Connection($Config);
      $MySQLConnection->attach($client);
      $Driver = new MySQL($Config, $MySQLConnection);
      $Long = $Driver->query("SELECT 3 AS value /* {$padding} */");
      $packet = $Long->write;
      $Driver->advance($Long);
      $half = $Long->state === OperationStates::Querying && $Long->write !== '';
      $received = $Read($peer);
      $accepted = $Driver->reset();

      // @@ Bounded: the server reads the command while it is flushed.
      for ($turn = 0; $turn < 5000 && strlen($received) < strlen($packet); $turn++) {
         $received = "{$received}{$Read($peer)}";
         $Driver->advance($Long);
      }

      $Driver->advance($Long);
      $behind = $Read($peer);
      fwrite($peer, $Value(3));

      // @@ Bounded: the response sits in one socketpair buffer.
      for ($turn = 0; $turn < 50 && $Long->finished === false; $turn++) {
         $Driver->advance($Long);
      }

      yield assert(
         assertion: $half
            && $accepted === false
            && $received === $packet
            && $behind === ''
            && $Long->error === null
            && $Long->rows === [['value' => 3]]
            && $Driver->check() === false
            && $Driver->detached === false,
         description: 'MySQL: a reset asked for while a command is half on the wire is refused and writes nothing into it; received='
            . strlen($received) . '/' . strlen($packet) . ', behind=' . strlen($behind) . ', error=' . json_encode($Long->error)
      );

      fclose($peer);
      $MySQLConnection->disconnect();

      // @ PostgreSQL, at the driver: the same refusal while a batch is
      //   mid-flush, with room on the stream again.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['driver' => 'pgsql', 'secure' => ['mode' => 'disable']]);
      $PGConnection = new Connection($Config);
      $PGConnection->attach($client);
      $Session = new PostgreSQL($Config, $PGConnection);
      $Long = $Session->query("SELECT 4 AS value /* {$padding} */");
      $batch = $Long->write;
      $Session->advance($Long);
      $half = $Long->state === OperationStates::Querying && $Long->write !== '';
      $received = $Read($peer);
      $accepted = $Session->reset();

      // @@ Bounded: the server reads the batch while it is flushed.
      for ($turn = 0; $turn < 5000 && strlen($received) < strlen($batch); $turn++) {
         $received = "{$received}{$Read($peer)}";
         $Session->advance($Long);
      }

      $Session->advance($Long);
      $behind = $Read($peer);
      fwrite($peer, $Select(4));

      // @@ Bounded: the answer sits in one socketpair buffer.
      for ($turn = 0; $turn < 50 && $Long->finished === false; $turn++) {
         $Session->advance($Long);
      }

      yield assert(
         assertion: $half
            && $accepted === false
            && $received === $batch
            && $behind === ''
            && $Long->error === null
            && $Long->rows === [['value' => 4]]
            && $Session->check() === false
            && $Session->detached === false,
         description: 'PostgreSQL: a reset asked for while a batch is mid-flush is refused and writes nothing into it; received='
            . strlen($received) . '/' . strlen($batch) . ', behind=' . strlen($behind) . ', error=' . json_encode($Long->error)
      );

      fclose($peer);
      $PGConnection->disconnect();

      // # 2. A server without the reset function refuses it harmlessly
      //   MySQL answers `DO RELEASE_ALL_LOCKS()` with an ERR where the function
      //   does not exist. The ERR is absorbed like any stand-in's answer: the
      //   next command reads only its own.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $Alone = $SQL->query('SELECT 4 AS value');
      $SQL->advance($Alone);
      $Read($peer);
      $Pool->withdraw($Alone);
      $reset = $Read($peer);
      $Next = $SQL->query('SELECT 5 AS value');
      $SQL->advance($Next);
      fwrite($peer, "{$Value(4)}{$Refusal}");
      $Drive($SQL, $Next);
      $wire = $Read($peer);
      fwrite($peer, $Value(5));
      $Drive($SQL, $Next);

      yield assert(
         assertion: $reset === $ResetMY
            && str_contains($wire, 'SELECT 5 AS value')
            && $Next instanceof SQLOperation
            && $Next->error === null
            && $Next->rows === [['value' => 5]]
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'MySQL: an ERR answering the reset is absorbed, and the next command reads only its own; error='
            . json_encode($Next->error) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 3. A reset that cannot be written takes down only an unshared session
      //   The command is whole on the wire, but the stream has no room left
      //   for the reset: a session whose state cannot be reset is not lent
      //   on. Nobody else reads there, so severing it fails nobody.
      //   (PostgreSQL: 4.18 leg 11c.)
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $Alone = $SQL->query('SELECT 6 AS value');
      $SQL->advance($Alone);
      $Read($peer);
      $Jam($SQL);
      $Pool->withdraw($Alone);

      yield assert(
         assertion: $Alone->state === OperationStates::Failed
            && $SQL->Connection->connected === false
            && $Census($Pool) === ['created' => 0, 'idle' => 0, 'busy' => 0],
         description: 'MySQL: a kept session whose reset cannot be written is severed; census='
            . json_encode($Census($Pool))
      );

      fclose($peer);

      // @ PostgreSQL: the stream is just as full when a statement is withdrawn
      //   with a live caller's write pipelined behind it, already on the wire.
      //   Nothing is written while that caller still reads, so nothing can
      //   fail it: it resolves with its own answer, and the reset follows its
      //   release once the server reads again.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Front = $SQL->query('SELECT 13 AS value');
      $Back = $SQL->query('INSERT INTO audit VALUES (1)');
      $SQL->advance($Front);
      $SQL->advance($Back);
      $wire = $Read($peer);
      $Jam($SQL);
      $Pool->withdraw($Front);
      $spared = $Front->state === OperationStates::Failed
         && $Back->state === OperationStates::Reading
         && $Back->error === null
         && $SQL->Connection->connected
         && $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];

      // @ The server reads again — the junk first — and answers both.
      $Read($peer);
      fwrite($peer, "{$Select(13)}{$Complete('INSERT 0 1')}");
      $Drive($SQL, $Back);
      $reset = $Read($peer);

      yield assert(
         assertion: str_contains($wire, 'INSERT INTO audit')
            && $spared
            && $Back->finished
            && $Back->error === null
            && $Back->status === 'INSERT 0 1'
            && $reset === $ResetPG
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'PostgreSQL: a full stream at the withdrawal never fails the live caller pipelined behind it; the reset follows its release; error='
            . json_encode($Back->error) . ', reset=' . strlen($reset) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL: same, with the live caller's command still queued.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $Head = $SQL->query('SELECT 14 AS value');
      $SQL->advance($Head);
      $Read($peer);
      $Behind = $SQL->query('SELECT 15 AS value');
      $SQL->advance($Behind);
      $Jam($SQL);
      $Pool->withdraw($Head);
      $spared = $Head->state === OperationStates::Failed
         && $Behind->state === OperationStates::Querying
         && $Behind->error === null
         && $SQL->Connection->connected
         && $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];

      $Read($peer);
      fwrite($peer, $Value(14));

      // @@ Bounded: the head's answer sits in one socketpair buffer.
      for ($turn = 0; $turn < 50 && $Behind->state !== OperationStates::Reading && $Behind->finished === false; $turn++) {
         $SQL->advance($Behind);
      }

      $wire = $Read($peer);
      fwrite($peer, $Value(15));
      $Drive($SQL, $Behind);
      $reset = $Read($peer);

      yield assert(
         assertion: $spared
            && str_contains($wire, 'SELECT 15 AS value')
            && $Behind instanceof SQLOperation
            && $Behind->error === null
            && $Behind->rows === [['value' => 15]]
            && $reset === $ResetMY
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'MySQL: a full stream at the withdrawal never fails the live caller queued behind it; the reset follows its release; error='
            . json_encode($Behind->error) . ', reset=' . strlen($reset) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 4. One reset covers every withdrawal still before it
      //   Asked again while the last reset ends the FIFO, the driver writes
      //   nothing: the reset already queued runs after all of it.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Alone = $SQL->query('SELECT 7 AS value');
      $SQL->advance($Alone);
      $Read($peer);
      $Pool->withdraw($Alone);
      $Session = $SQL->Connection->Protocol;
      $again = $Session?->reset() === true && $Session->reset() === true;
      $reset = $Read($peer);

      yield assert(
         assertion: $again && $reset === $ResetPG,
         description: 'PostgreSQL: a reset already ending the FIFO is not written again; bytes=' . strlen($reset)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL: a stand-in head with two siblings queued behind it, withdrawn
      //   head first, then the later sibling, then the earlier one. Neither
      //   sibling wrote a byte, so the one reset the last release writes
      //   covers them all: nothing is left counted as live work, the
      //   connection goes idle, and a BEGIN — which never co-locates — is
      //   handed it and drains the stand-in and the reset ahead of its own.
      [$SQL, $peer] = $Open('mysql');
      $Pool = $SQL->Pool;
      $Head = $SQL->query('SELECT 21 AS value');
      $SQL->advance($Head);
      $Read($peer);
      $Earlier = $SQL->query('SELECT 22 AS value');
      $SQL->advance($Earlier);
      $Later = $SQL->query('SELECT 23 AS value');
      $SQL->advance($Later);
      $queued = $Earlier->state === OperationStates::Querying
         && $Later->state === OperationStates::Querying
         && $Later->Connection === $Head->Connection
         && $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];

      $Pool->withdraw($Head);
      $Pool->withdraw($Later);
      $Pool->withdraw($Earlier);
      $resets = $Read($peer);
      $idled = $SQL->Connection->Protocol?->check() === false
         && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0];

      $Transaction = $SQL->begin();
      $Opening = $Transaction->Operation;
      $handed = $Opening !== null
         && $Opening->Connection === $SQL->Connection
         && $Opening->finished === false;

      fwrite($peer, "{$Value(21)}{$OK}");

      // @@ Bounded: the stand-in's answer and the reset's sit in one buffer.
      for ($turn = 0; $turn < 50 && $Opening !== null && $Opening->state !== OperationStates::Reading && $Opening->finished === false; $turn++) {
         $SQL->advance($Opening);
      }

      $wire = $Read($peer);
      fwrite($peer, $OK);

      if ($Opening !== null) {
         $Drive($SQL, $Opening);
      }

      yield assert(
         assertion: $queued
            && $resets === $ResetMY
            && $idled
            && $handed
            && str_contains($wire, 'BEGIN')
            && $Opening !== null
            && $Opening->finished
            && $Opening->error === null
            && $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1],
         description: 'MySQL: a stand-in head and two queued siblings withdrawn out of order leave one reset, an idle connection, and a BEGIN served behind them; resets='
            . strlen($resets) . '/' . strlen($ResetMY) . ', idled=' . json_encode($idled) . ', begin=' . json_encode($Opening?->error)
            . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ PostgreSQL: the reader leaves first while another caller's batch
      //   holds the stream with nothing written; that caller leaves next. The
      //   reset is written by the writer's release — the one that idles the
      //   connection — so it reaches the server ahead of the next caller's
      //   batch, and the withdrawn writer's bytes never do.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Reader = $SQL->query('SELECT 24 AS value');
      $SQL->advance($Reader);
      $Read($peer);
      $Jam($SQL);
      $Writer = $SQL->query('UPDATE w SET v = 1');
      $SQL->advance($Writer);
      $Driver = $Writer->Protocol;
      $credit = $Driver instanceof PostgreSQL
         && (new ReflectionProperty(PostgreSQL::class, 'writing'))->getValue($Driver) === $Writer
         && (new ReflectionProperty(PostgreSQL::class, 'wrote'))->getValue($Driver) === 0;

      $Pool->withdraw($Reader);
      $waiting = $Census($Pool) === ['created' => 1, 'idle' => 0, 'busy' => 1];
      $junk = $Read($peer);
      $Pool->withdraw($Writer);
      $reset = $Read($peer);
      $Next = $SQL->query('SELECT 25 AS value');
      $SQL->advance($Next);
      $wire = $Read($peer);

      yield assert(
         assertion: $credit
            && $waiting
            && str_contains($junk, 'pg_advisory_unlock_all') === false
            && $reset === $ResetPG
            && $Next->Connection === $SQL->Connection
            && str_contains($wire, 'SELECT 25 AS value')
            && str_contains("{$junk}{$reset}{$wire}", 'UPDATE w') === false,
         description: 'PostgreSQL: a reader withdrawn before a zero-credit writer leaves the reset to the writer\'s release, ahead of the next caller; reset='
            . strlen($reset) . ', next=' . json_encode(str_contains($wire, 'SELECT 25 AS value')) . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ MySQL, at the driver: a reset still owed covers a repeated request
      //   even with a command queued behind it — that command has not been
      //   written, so nothing it does can precede the reset. Nothing more is
      //   written, and the queued command reads only its own answer.
      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $Config = new SQLConfig(['driver' => 'mysql', 'secure' => ['mode' => 'disable']]);
      $MySQLConnection = new Connection($Config);
      $MySQLConnection->attach($client);
      $Driver = new MySQL($Config, $MySQLConnection);
      $Head = $Driver->query('SELECT 28 AS value');
      $Driver->advance($Head);
      $Read($peer);
      $Driver->detach($Head);
      $first = $Driver->reset();
      $Queued = $Driver->query('SELECT 29 AS value');
      $Driver->advance($Queued);
      $second = $Driver->reset();
      $wire = $Read($peer);
      fwrite($peer, "{$Value(28)}{$OK}");

      // @@ Bounded: the stand-in's answer and the reset's sit in one buffer.
      for ($turn = 0; $turn < 50 && $Queued->state !== OperationStates::Reading && $Queued->finished === false; $turn++) {
         $Driver->advance($Queued);
      }

      $own = $Read($peer);
      fwrite($peer, $Value(29));

      // @@ Bounded: the answer sits in one socketpair buffer.
      for ($turn = 0; $turn < 50 && $Queued->finished === false; $turn++) {
         $Driver->advance($Queued);
      }

      yield assert(
         assertion: $first
            && $second
            && $wire === $ResetMY
            && str_contains($own, 'SELECT 29 AS value')
            && $Queued->error === null
            && $Queued->rows === [['value' => 29]]
            && $Driver->check() === false
            && $Driver->detached === false,
         description: 'MySQL: a reset still owed covers a repeated request with a command queued behind it; resets='
            . strlen($wire) . '/' . strlen($ResetMY) . ', rows=' . json_encode($Queued->rows) . ', error=' . json_encode($Queued->error)
      );

      fclose($peer);
      $MySQLConnection->disconnect();

      // # 5. A tainted connection is reset before it is lent again
      //   Its owner was orphaned another way — an adopted transaction — so the
      //   release that would return it to idle writes the reset first, once.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Owned = $SQL->query('SELECT 8 AS value');
      $SQL->advance($Owned);
      $Read($peer);
      $Pool->taint($SQL->Connection);
      fwrite($peer, $Select(8));
      $Drive($SQL, $Owned);
      $reset = $Read($peer);
      $idled = $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0]
         && $SQL->Connection->Protocol?->detached === true;

      $Next = $SQL->query('SELECT 9 AS value');
      $SQL->advance($Next);
      $Read($peer);
      fwrite($peer, "{$UnlockedPG}{$Select(9)}");
      $Drive($SQL, $Next);
      $once = $Read($peer);

      yield assert(
         assertion: $Owned->error === null
            && $reset === $ResetPG
            && $idled
            && $Next instanceof SQLOperation
            && $Next->rows === [['value' => 9]]
            && $once === ''
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'A tainted connection writes the reset before it goes idle, once; the next query drains it; census='
            . json_encode($Census($Pool)) . ', reset=' . strlen($reset) . ', again=' . strlen($once)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // @ A tainted connection that cannot take the reset is dropped.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Owned = $SQL->query('SELECT 10 AS value');
      $SQL->advance($Owned);
      $Read($peer);
      $Pool->taint($SQL->Connection);
      fwrite($peer, $Select(10));
      $Jam($SQL);
      $Drive($SQL, $Owned);
      $tainted = (array) (new ReflectionProperty(Pool::class, 'tainted'))->getValue($Pool);

      yield assert(
         assertion: $Owned->error === null
            && $SQL->Connection->connected === false
            && $tainted === []
            && $Census($Pool) === ['created' => 0, 'idle' => 0, 'busy' => 0],
         description: 'A tainted connection whose reset cannot be written is severed and dropped; census='
            . json_encode($Census($Pool))
      );

      fclose($peer);

      // @ A taint dies with the session it marked: a connection dropped before
      //   any release reset it carries nothing into the session the pool
      //   rebuilds on the same Connection object.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Owned = $SQL->query('SELECT 12 AS value');
      $SQL->advance($Owned);
      $Read($peer);
      $Pool->taint($SQL->Connection);
      fclose($peer);
      $Drive($SQL, $Owned);
      $dropped = $Owned->state === OperationStates::Failed
         && $Census($Pool) === ['created' => 0, 'idle' => 0, 'busy' => 0];

      [$client, $peer] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($peer, false);
      $SQL->Connection->attach($client);
      $Rebuilt = $SQL->query('SELECT 13 AS value');
      $SQL->advance($Rebuilt);
      $Read($peer);
      fwrite($peer, $Select(13));
      $Drive($SQL, $Rebuilt);
      $after = $Read($peer);

      yield assert(
         assertion: $dropped
            && $Rebuilt->error === null
            && $after === ''
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'A taint on a dropped session is not carried into the session rebuilt on the same Connection object; bytes after='
            . strlen($after)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 6. An answer read ahead of a batch still flushing is delivered to it
      //   The next caller's batch is larger than the socket buffers, and it is
      //   handed a session whose stand-in and reset still lead the FIFO. While
      //   it flushes, it drains what they are owed — and the server's answers
      //   to the batch itself may arrive in the same read, past every queued
      //   entry. They are kept, and delivered the moment the batch is whole on
      //   the wire: nothing more may ever arrive to announce them.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Gone = $SQL->query('SELECT 26 AS value');
      $SQL->advance($Gone);
      $Read($peer);
      $Pool->withdraw($Gone);
      $reset = $Read($peer);
      $padding = str_repeat('y', 4 * 1024 * 1024);
      $Next = $SQL->query("SELECT 27 AS value /* {$padding} */");
      $batch = $Next instanceof SQLOperation ? $Next->write : '';
      $SQL->advance($Next);
      $partial = $Next->state === OperationStates::Querying && $Next instanceof SQLOperation && $Next->write !== '';

      // @ Everything arrives in one read while the batch is still flushing.
      fwrite($peer, "{$Select(26)}{$UnlockedPG}{$Select(27)}");
      $SQL->advance($Next);
      $absorbed = $Next->state === OperationStates::Querying
         && $SQL->Connection->Protocol?->detached === false;
      $received = '';

      // @@ Bounded: the server reads the batch while its caller flushes it.
      for ($turn = 0; $turn < 20000 && $Next->state === OperationStates::Querying; $turn++) {
         $received = "{$received}{$Read($peer)}";
         $SQL->advance($Next);
      }

      // ! The state right after the call that put the batch whole on the wire.
      $flushed = $Next->state;
      $received = "{$received}{$Read($peer)}";

      yield assert(
         assertion: $reset === $ResetPG
            && $partial
            && $absorbed
            && $flushed === OperationStates::Finished
            && $received === $batch
            && $Next instanceof SQLOperation
            && $Next->error === null
            && $Next->rows === [['value' => 27]]
            && $Gone->Result === null
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'PostgreSQL: answers read ahead of a batch still flushing are delivered once it is whole on the wire; state='
            . $flushed->name . ', received=' . strlen($received) . '/' . strlen($batch) . ', rows=' . json_encode($Next->rows)
            . ', error=' . json_encode($Next->error)
      );

      fclose($peer);
      $SQL->Connection->disconnect();

      // # 7. A tainted session takes no new caller before its reset
      //   The pool is saturated: its one session carries a live caller behind
      //   a withdrawn statement, so it owes its reset to the release that will
      //   idle it. A new caller is not co-located there — it would run before
      //   the reset, inside what the withdrawn statement left, and under steady
      //   load the session would never drain for the reset to go out. It
      //   parks, and runs behind the reset once the session idles.
      [$SQL, $peer] = $Open('pgsql');
      $Pool = $SQL->Pool;
      $Front = $SQL->query('SELECT 34 AS value');
      $Back = $SQL->query('SELECT 35 AS value');
      $SQL->advance($Front);
      $SQL->advance($Back);
      $Read($peer);
      $Pool->withdraw($Front);
      $Late = $SQL->query('SELECT 36 AS value');
      $parked = $Late->state === OperationStates::Pending
         && $Late->Connection === null
         && count($Pool->pending) === 1;
      $early = $Read($peer);
      fwrite($peer, "{$Select(34)}{$Select(35)}");
      $Drive($SQL, $Back);
      $wire = $Read($peer);
      $reset = strpos($wire, 'pg_advisory_unlock_all');
      $late = strpos($wire, 'SELECT 36 AS value');
      fwrite($peer, "{$UnlockedPG}{$Select(36)}");
      $Drive($SQL, $Late);

      yield assert(
         assertion: $parked
            && $early === ''
            && $Back->error === null
            && $reset !== false
            && $late !== false
            && $reset < $late
            && $Late->Connection === $SQL->Connection
            && $Late instanceof SQLOperation
            && $Late->error === null
            && $Late->rows === [['value' => 36]]
            && $Census($Pool) === ['created' => 1, 'idle' => 1, 'busy' => 0],
         description: 'A tainted session takes no co-located caller: the next one parks and runs behind the reset once the session idles; parked='
            . json_encode($parked) . ', order=' . json_encode([$reset, $late]) . ', error=' . json_encode($Late->error)
            . ', census=' . json_encode($Census($Pool))
      );

      fclose($peer);
      $SQL->Connection->disconnect();
   }
);
