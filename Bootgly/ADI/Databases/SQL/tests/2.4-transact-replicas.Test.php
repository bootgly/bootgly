<?php

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Transaction;


[$open, $close, $supported] = require __DIR__ . '/0.peer.php';


return new Test(
   description: 'SQL::transact(): the read-after-write window counts from the outermost COMMIT, and only from a COMMIT',
   skip: $supported === false,
   test: function () use ($open, $close) {
      // ! The primary is a scripted peer; the replica is never dialled: a read
      //   routed to it parks in its pool, and the pool an operation was
      //   assigned to tells where it was sent.
      $sticky = 0.4;
      $config = [
         'routing' => ['sticky' => $sticky],
         'replicas' => [
            ['host' => 'replica.invalid', 'pool' => ['min' => 0, 'max' => 0]],
         ],
      ];
      [$Database, $Peer] = $open('pgsql', [], $config);
      [$Refusing, $Refuser] = $open('pgsql', ['COMMIT' => 'could not serialize access'], $config);
      $route = static function (SQL $Database): string {
         $Read = $Database->query('SELECT count(*) AS n FROM marks');
         $route = $Read->Pool === $Database->Pool ? 'primary' : 'replica';

         // ! Only the routing is observed: the read leaves its pool again.
         $Database->withdraw($Read);

         return $route;
      };
      // ! Longer than the sticky window: the mark the write itself set has
      //   expired by the time the unit of work ends.
      $outlast = static function () use ($sticky): void {
         usleep((int) (($sticky + 0.15) * 1_000_000));
      };
      $Failure = new LogicException('the work failed');

      try {
         $Database->await($Database->query('CREATE TABLE marks (id INTEGER PRIMARY KEY, tag TEXT)'));
         $outlast();

         yield assert(
            assertion: $route($Database) === 'replica',
            description: 'Control: outside the sticky window a facade read goes to the replica'
         );

         // # Committed: the read right after transact() runs on the primary,
         //   although the write inside it is older than the window.
         $Database->transact(static function (Transaction $Transaction) use ($outlast): void {
            $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('committed')"));
            $outlast();
         });

         yield assert(
            assertion: $route($Database) === 'primary',
            description: 'A facade read right after a committed transact() runs on the primary'
         );

         // # Rolled back: no new mark — the write's own mark has expired, and
         //   the rollback sets none.
         $outlast();

         try {
            $Database->transact(static function (Transaction $Transaction) use ($outlast, $Failure): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('rolled back')"));
               $outlast();

               throw $Failure;
            });
         }
         catch (LogicException) {
            // ? Expected
         }

         yield assert(
            assertion: $route($Database) === 'replica',
            description: 'A rolled-back transact() sets no new read-after-write mark'
         );

         // # A nested call that commits its savepoint sets none either: the
         //   replicas see nothing until the outermost COMMIT.
         $routes = [];

         $Database->transact(static function (Transaction $Transaction) use ($Database, $outlast, $route, &$routes): void {
            $Database->transact(static function (Transaction $Transaction) use ($outlast): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('nested')"));
               // ! The write's own mark expires inside the nested call, so a
               //   mark found right after it could only be the nested commit's.
               $outlast();
            });

            $routes[] = $route($Database);
         });

         $routes[] = $route($Database);

         yield assert(
            assertion: $routes === ['replica', 'primary'],
            description: 'Only the outermost COMMIT renews the mark, found: ' . json_encode($routes)
         );

         // # A COMMIT the server refuses sets none: nothing was committed.
         $Refusing->await($Refusing->query('CREATE TABLE marks (id INTEGER PRIMARY KEY, tag TEXT)'));
         $outlast();
         $Caught = null;

         try {
            $Refusing->transact(static function (Transaction $Transaction) use ($outlast): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('refused')"));
               $outlast();
            });
         }
         catch (RuntimeException $Exception) {
            $Caught = $Exception;
         }

         yield assert(
            assertion: str_contains((string) $Caught?->getMessage(), 'could not serialize access')
               && $route($Refusing) === 'replica',
            description: 'A transact() whose COMMIT fails sets no read-after-write mark, found: ' . json_encode($Caught?->getMessage())
         );
      }
      finally {
         $close($Peer);
         $close($Refuser);
      }
   }
);
