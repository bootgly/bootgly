<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Backfill;


use function assert;
use function extension_loaded;
use function str_starts_with;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;


#[Table('backfill_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

class RecordingSQL extends SQL
{
   /** @var array<int,string> */
   public array $queries = [];
   public null|Result $Next = null;


   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $this->queries[] = $Normalized->SQL;

      $Operation->resolve($this->Next ?? new Result('OK'));
      $this->Next = null;

      return $Operation;
   }
}


return new Specification(
   description: 'ORM: generated keys backfill from Result->inserted on dialects without RETURNING',
   test: function () {
      // # MySQL dialect — no RETURNING capability
      $Database = new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $Repository = $Database->map(User::class);

      $User = new User;
      $User->name = 'Ada';

      $Database->Next = new Result('INSERT 0 1', [], [], 1, 7);
      $Operation = $Repository->save($User);

      yield assert(
         assertion: str_starts_with($Database->queries[0], 'INSERT INTO `backfill_users`'),
         description: 'The insert compiles through the MySQL dialect without RETURNING'
      );

      $Mapped = $Repository->hydrate($Operation);

      yield assert(
         assertion: $Mapped->entity === $User && $User->id === 7,
         description: 'hydrate() writes Result->inserted back into the generated key'
      );

      // @ Identity registration routes the next save() to an UPDATE
      $User->name = 'Ada Lovelace';
      $Database->Next = new Result('UPDATE 1', [], [], 1);
      $Repository->save($User);

      yield assert(
         assertion: str_starts_with($Database->queries[1], 'UPDATE `backfill_users`'),
         description: 'The backfilled entity is identity-tracked and updates on the next save'
      );

      // # No generated id — no backfill
      $Fresh = new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);
      $FreshRepository = $Fresh->map(User::class);
      $Missing = new User;
      $Missing->name = 'Ghost';

      $Fresh->Next = new Result('INSERT 0 1', [], [], 1, 0);
      $Mapped = $FreshRepository->hydrate($FreshRepository->save($Missing));

      yield assert(
         assertion: $Mapped->entities === [] && $Missing->id === null,
         description: 'Inserts without a reported id stay untouched'
      );

      // # PostgreSQL dialect — RETURNING keeps the pre-existing hydration path
      $PostgreSQL = new RecordingSQL(['driver' => 'pgsql', 'pool' => ['min' => 0, 'max' => 0]]);
      $PostgreSQLRepository = $PostgreSQL->map(User::class);
      $Returned = new User;
      $Returned->name = 'Grace';

      $PostgreSQL->Next = new Result('INSERT 0 1', [['id' => 9, 'name' => 'Grace']], ['id', 'name'], 1);
      $Mapped = $PostgreSQLRepository->hydrate($PostgreSQLRepository->save($Returned));

      yield assert(
         assertion: $Mapped->entity instanceof User && $Mapped->entity->id === 9,
         description: 'Dialects with RETURNING keep hydrating entities from rows'
      );

      // # SQLite E2E — the real driver backfills through Result->inserted
      if (extension_loaded('sqlite3')) {
         $SQLite = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $SQLite->query('CREATE TABLE backfill_users (id INTEGER PRIMARY KEY, name TEXT)');
         $SQLiteRepository = $SQLite->map(User::class);

         $Real = new User;
         $Real->name = 'Edsger';

         $Mapped = $SQLiteRepository->hydrate($SQLiteRepository->save($Real));

         yield assert(
            assertion: $Mapped->entity === $Real && $Real->id === 1
               && $SQLite->query('SELECT count(*) AS total FROM backfill_users')->Result?->cell === 1,
            description: 'The SQLite driver inserts exactly once and backfills the generated key'
         );
      }
   }
);
