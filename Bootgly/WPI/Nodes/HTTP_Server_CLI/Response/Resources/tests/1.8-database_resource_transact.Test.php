<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests;


use const STREAM_IPPROTO_IP;
use const STREAM_PF_UNIX;
use const STREAM_SOCK_STREAM;
use function array_shift;
use function assert;
use function count;
use function fclose;
use function fread;
use function fwrite;
use function pack;
use function str_contains;
use function stream_set_blocking;
use function stream_socket_pair;
use function strlen;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;


return new Test(
   description: 'Resources: transact() gives the pinned connection back when the work throws mid-write',
   test: function () {
      [$client, $server] = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
      stream_set_blocking($client, false);
      stream_set_blocking($server, false);

      $complete = static function (string $command): string {
         $command = "{$command}\0";
         $commandLength = pack('N', strlen($command) + 4);
         $readyLength = pack('N', 5);

         return "C{$commandLength}{$command}Z{$readyLength}I";
      };

      $SQL = new SQL([
         'timeout' => 30.0,
         'pool' => [
            'min' => 0,
            'max' => 1,
         ],
      ]);
      $SQL->Connection->attach($client);

      $Pool = $SQL->Pool;
      $Resource = new Database($SQL);

      // ! The scheduler bridge answers whatever the driver just wrote.
      $wire = '';
      $replies = [$complete('BEGIN'), $complete('ROLLBACK')];

      $Resource->schedule(function (mixed $value = null) use ($server, &$wire, &$replies): void {
         $wire .= (string) fread($server, 8192);
         $reply = array_shift($replies);

         if ($reply !== null) {
            fwrite($server, $reply);
         }
      });

      // @ The exact shape the audit describes: an ORM-style write is issued and
      //   never awaited, then a listener throws before the work returns.
      $Failure = new RuntimeException('work failed after the write');
      $Orphan = null;
      $caught = null;

      try {
         $Resource->transact(function ($Transaction) use (&$Orphan, $Failure) {
            $Orphan = $Transaction->query("INSERT INTO marker (v) VALUES ('reach')");

            throw $Failure;
         });
      }
      catch (Throwable $Throwable) {
         $caught = $Throwable;
      }

      yield assert(
         assertion: $caught === $Failure,
         description: 'transact() propagates the original work failure'
      );

      yield assert(
         assertion: $Orphan !== null && $Orphan->finished && $Orphan->write === '',
         description: 'The un-awaited write is discarded instead of surviving the rollback'
      );

      yield assert(
         assertion: str_contains($wire, 'BEGIN') && str_contains($wire, 'ROLLBACK'),
         description: 'Both the begin and the rollback reached the server'
      );

      yield assert(
         assertion: str_contains($wire, 'INSERT') === false,
         description: 'The discarded write never reached the server'
      );

      yield assert(
         assertion: $Pool->busy === [] && count($Pool->idle) === 1 && $Pool->pending === [],
         description: 'The connection the transaction pinned is back in the pool'
      );

      // @ The worker keeps serving: an ordinary query borrows the same slot.
      $Next = $SQL->query('SELECT 1 AS v');

      yield assert(
         assertion: $Next->Connection !== null && $Pool->pending === [],
         description: 'A later query is assigned instead of parking behind a reservation nobody holds'
      );

      fclose($server);
      $SQL->Connection->disconnect();
   }
);
