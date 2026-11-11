<?php

use Bootgly\ABI\Events\Emitter;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;
use Bootgly\ADI\Databases\SQL\Transaction\Events;


[$open, $close, $supported] = require __DIR__ . '/0.peer.php';


return new Test(
   description: 'SQL::transact(): a wait that throws or a Fiber destroyed inside it never leaves the transaction or its reservation behind',
   skip: $supported === false,
   test: function () use ($open, $close) {
      $census = static function (SQL $Database): array {
         $Pool = $Database->Pool;
         $locked = new ReflectionProperty($Pool, 'locked')->getValue($Pool);
         $open = new ReflectionProperty(SQL::class, 'Transactions')->getValue($Database);

         return [count($locked), count($Pool->busy), count($Pool->pending), count($open)];
      };
      $insert = "INSERT INTO marks (tag) VALUES ('a')";
      $Failure = new LogicException('the work failed');

      foreach (['pgsql', 'mysql'] as $driver) {
         // # The wait for BEGIN throws: BEGIN is in flight, so it is torn down
         //   with abort() — withdrawn, its session dropped — and the work
         //   never runs.
         [$Database, $Peer] = $open($driver);
         $Database->trap = 'BEGIN';
         $ran = false;
         $Caught = null;
         $heard = 0;
         Emitter::$Instance = new Emitter;
         Emitter::$Instance->listen(Events::Rollback, static function () use (&$heard): void {
            $heard++;
         });

         try {
            $Database->transact(static function () use (&$ran): void {
               $ran = true;
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }
         finally {
            Emitter::$Instance = new Emitter;
         }

         $state = [$ran, $heard, $Caught?->getMessage(), $census($Database), $close($Peer)];

         yield assert(
            assertion: $state === [false, 0, 'Database operation readiness wait failed.', [0, 0, 0, 0], ['BEGIN']],
            description: "{$driver}: a BEGIN whose wait throws is withdrawn — no level was open, so no Rollback is announced — and reserves nothing, found: " . json_encode($state)
         );

         // # The wait for COMMIT throws: depth is already 0, so abort() has
         //   nothing left — the COMMIT itself is withdrawn before the
         //   throwable is rethrown. Its outcome on the server is unknown.
         [$Database, $Peer] = $open($driver);
         $Database->trap = 'COMMIT';
         $Held = null;
         $Caught = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($insert, &$Held): void {
               $Held = $Transaction;
               $Transaction->await($Transaction->query($insert));
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         $Commit = $Held?->Operation;
         $state = [
            $Caught?->getMessage(),
            $Held?->depth,
            [$Commit?->SQL, $Commit?->finished, $Commit?->revoked],
            $census($Database),
            $close($Peer),
         ];

         yield assert(
            assertion: $state === [
               'Database operation readiness wait failed.',
               0,
               ['COMMIT', true, true],
               [0, 0, 0, 0],
               ['BEGIN', $insert, 'COMMIT'],
            ],
            description: "{$driver}: a COMMIT whose wait throws is withdrawn and the wait's failure rethrown, found: " . json_encode($state)
         );

         // # The wait for the ROLLBACK of a failed work throws: the work's
         //   failure survives, and the ROLLBACK is withdrawn too.
         [$Database, $Peer] = $open($driver);
         $Database->trap = 'ROLLBACK';
         $Held = null;
         $Caught = null;

         try {
            $Database->transact(static function (Transaction $Transaction) use ($insert, $Failure, &$Held): void {
               $Held = $Transaction;
               $Transaction->await($Transaction->query($insert));

               throw $Failure;
            });
         }
         catch (Throwable $Throwable) {
            $Caught = $Throwable;
         }

         $Rollback = $Held?->Operation;
         $state = [
            $Caught === $Failure,
            $Held?->depth,
            [$Rollback?->SQL, $Rollback?->finished, $Rollback?->revoked],
            $census($Database),
            $close($Peer),
         ];

         yield assert(
            assertion: $state === [true, 0, ['ROLLBACK', true, true], [0, 0, 0, 0], ['BEGIN', $insert, 'ROLLBACK']],
            description: "{$driver}: a ROLLBACK whose wait throws is withdrawn and the work's failure kept, found: " . json_encode($state)
         );

         // # A Fiber destroyed in the middle of the work: only `finally` runs.
         //   Nothing may wait there, so the ROLLBACK is withdrawn before the
         //   wire and the session severed — the server rolls the work back.
         foreach (['outermost' => 1, 'nested' => 2] as $shape => $depth) {
            [$Database, $Peer] = $open($driver);
            $Held = null;

            $Fiber = new Fiber(static function () use ($Database, $insert, $shape, &$Held): void {
               $Database->transact(static function (Transaction $Transaction) use ($Database, $insert, $shape, &$Held): void {
                  $Held = $Transaction;
                  $Transaction->await($Transaction->query($insert));

                  if ($shape === 'nested') {
                     $Database->transact(static function (): void {
                        Fiber::suspend();
                     });

                     return;
                  }

                  Fiber::suspend();
               });
            });
            $Fiber->start();

            $parked = [$Held?->depth, $census($Database)];

            unset($Fiber);

            $Teardown = $Held?->Operation;
            $state = [
               $parked,
               $Held?->depth,
               [$Teardown?->SQL, $Teardown?->finished],
               $census($Database),
               in_array('ROLLBACK', $close($Peer), true),
            ];

            yield assert(
               assertion: $state === [[$depth, [1, 1, 0, 1]], 0, ['ROLLBACK', true], [0, 0, 0, 0], false],
               description: "{$driver}: a Fiber destroyed inside the {$shape} work leaves depth 0 and no reservation, found: " . json_encode($state)
            );
         }
      }
   }
);
