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
      // ! A window no stall of a loaded machine outlasts: a read made right
      //   after a mark is inside it, however late it runs.
      $sticky = 30.0;
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
      // ! The window passes for every read-after-write mark the facade holds:
      //   each one is aged past it, as a sleep longer than the window would
      //   age it, with no sleep — so the mark the write itself set has
      //   expired by the time the unit of work ends, and only a mark set
      //   after this call can route a read to the primary.
      $Written = new ReflectionProperty(SQL::class, 'Written');
      $outlast = static function (SQL $Database) use ($Written, $sticky): void {
         $Marks = $Written->getValue($Database);
         $Aged = [];
         foreach ($Marks as $Scope => $mark) {
            $Aged[] = [$Scope, $mark];
         }
         foreach ($Aged as [$Scope, $mark]) {
            $Marks[$Scope] = $mark - $sticky - 1.0;
         }
      };
      $Failure = new LogicException('the work failed');

      try {
         $Database->await($Database->query('CREATE TABLE marks (id INTEGER PRIMARY KEY, tag TEXT)'));
         $fresh = $route($Database);
         $outlast($Database);

         yield assert(
            assertion: $fresh === 'primary' && $route($Database) === 'replica',
            description: 'Control: a facade read right after a write runs on the primary, and outside the sticky window it goes to the replica'
         );

         // # Committed: the read right after transact() runs on the primary,
         //   although the write inside it is older than the window.
         $Database->transact(static function (Transaction $Transaction) use ($Database, $outlast): void {
            $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('committed')"));
            $outlast($Database);
         });

         yield assert(
            assertion: $route($Database) === 'primary',
            description: 'A facade read right after a committed transact() runs on the primary'
         );

         // # Rolled back: no new mark — the write's own mark has expired, and
         //   the rollback sets none.
         $outlast($Database);

         try {
            $Database->transact(static function (Transaction $Transaction) use ($Database, $outlast, $Failure): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('rolled back')"));
               $outlast($Database);

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
            $Database->transact(static function (Transaction $Transaction) use ($Database, $outlast): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('nested')"));
               // ! The write's own mark expires inside the nested call, so a
               //   mark found right after it could only be the nested commit's.
               $outlast($Database);
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
         $outlast($Refusing);
         $Caught = null;

         try {
            $Refusing->transact(static function (Transaction $Transaction) use ($Refusing, $outlast): void {
               $Transaction->await($Transaction->query("INSERT INTO marks (tag) VALUES ('refused')"));
               $outlast($Refusing);
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
