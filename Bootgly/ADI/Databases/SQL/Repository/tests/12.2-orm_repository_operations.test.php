<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Operations;


use function assert;
use Error;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Orders;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository;


#[Table('orm_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   #[Column('email')]
   public string $mail = '';
   #[Column(nullable: true)]
   public null|bool $active = null;
}

#[Table('orm_manual_users')]
class ManualUser
{
   #[Key(generated: false)]
   public string $id = '';
   #[Column]
   public string $name = '';
}

#[Table('orm_empty_inserts')]
class EmptyInsert
{
   #[Key]
   public null|int $id = null;
}

#[Table('orm_empty_updates')]
class EmptyUpdate
{
   #[Key]
   public null|int $id = null;
   #[Column(update: false)]
   public string $name = '';
}

class RecordingSQL extends SQL
{
   /** @var array<int,array{sql:string,parameters:array<int|string,mixed>}> */
   public array $queries = [];
   /** @var array<int,null|object> */
   public array $scopes = [];


   public function __construct ()
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
   }

   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters);
      $this->queries[] = [
         'sql' => $Operation->SQL,
         'parameters' => $Operation->parameters,
      ];
      $this->scopes[] = $Scope;

      return $Operation;
   }
}


return new Specification(
   description: 'Database: SQL ORM repository compiles CRUD operations',
   test: function () {
      $Database = new RecordingSQL;
      $Scope = new stdClass;
      $Repository = $Database->map(User::class, $Scope);
      $Created = Repository::create($Database, $Database->Dialect, $Database->Models, User::class);

      yield assert(
         assertion: $Created->Model === $Repository->Model,
         description: 'Repository::create centralizes ORM repository construction'
      );

      $Operation = $Repository->find(5);

      yield assert(
         assertion: $Operation->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "id" = ?1 LIMIT 1'
            && $Operation->parameters === [5]
            && $Database->scopes[0] === $Scope,
         description: 'Repository::find compiles a scoped primary-key lookup operation'
      );

      $Selection = $Repository
         ->select()
         ->filter(new Identifier('active'), Operators::Equal, true)
         ->order(Orders::Asc, new Identifier('name'));
      $Operation = $Repository->fetch($Selection);

      yield assert(
         assertion: $Operation->SQL === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "active" = ?1 ORDER BY "name" ASC'
            && $Operation->parameters === [true],
         description: 'Repository::fetch compiles explicit ORM selections through the SQL builder'
      );

      $User = new User;
      $User->name = 'Ada';
      $User->mail = 'ada@example.test';
      $User->active = true;
      $Operation = $Repository->save($User);

      yield assert(
         assertion: $Operation->SQL === 'INSERT INTO "orm_users" ("name", "email", "active") VALUES (?1, ?2, ?3) RETURNING "id", "name", "email", "active"'
            && $Operation->parameters === ['Ada', 'ada@example.test', true],
         description: 'Repository::save compiles INSERT for generated-key entities'
      );

      $ManualRepository = $Database->map(ManualUser::class);
      $protected = false;
      try {
         $Repository->Model = $ManualRepository->Model;
      }
      catch (Error) {
         $protected = true;
      }

      yield assert(
         assertion: $protected,
         description: 'Repository exposes constructor configuration as read-only properties'
      );

      $ManualUser = new ManualUser;
      $ManualUser->id = 'manual-1';
      $ManualUser->name = 'Manual Ada';
      $Operation = $ManualRepository->save($ManualUser);

      yield assert(
         assertion: $Operation->SQL === 'INSERT INTO "orm_manual_users" ("id", "name") VALUES (?1, ?2) RETURNING "id", "name"'
            && $Operation->parameters === ['manual-1', 'Manual Ada'],
         description: 'Repository::save compiles INSERT for new non-generated primary-key entities'
      );

      $Mapped = $ManualRepository->hydrate(new Result(
         rows: [[
            'id' => 'manual-2',
            'name' => 'Tracked Ada',
         ]],
         columns: ['id', 'name']
      ));
      /** @var ManualUser $Tracked */
      $Tracked = $Mapped->entity;
      $Tracked->name = 'Tracked Ada Updated';
      $Operation = $ManualRepository->save($Tracked);

      yield assert(
         assertion: $Operation->SQL === 'UPDATE "orm_manual_users" SET "name" = ?1 WHERE "id" = ?2 RETURNING "id", "name"'
            && $Operation->parameters === ['Tracked Ada Updated', 'manual-2'],
         description: 'Repository::save compiles UPDATE for tracked non-generated primary-key entities'
      );

      $User->id = 7;
      $Operation = $Repository->save($User);

      yield assert(
         assertion: $Operation->SQL === 'UPDATE "orm_users" SET "name" = ?1, "email" = ?2, "active" = ?3 WHERE "id" = ?4 RETURNING "id", "name", "email", "active"'
            && $Operation->parameters === ['Ada', 'ada@example.test', true, 7],
         description: 'Repository::save compiles UPDATE when the primary key is present'
      );

      $Operation = $Repository->delete($User);

      yield assert(
         assertion: $Operation->SQL === 'DELETE FROM "orm_users" WHERE "id" = ?1'
            && $Operation->parameters === [7],
         description: 'Repository::delete compiles DELETE from entity primary key'
      );

      $Operation = $Repository->delete(8);

      yield assert(
         assertion: $Operation->SQL === 'DELETE FROM "orm_users" WHERE "id" = ?1'
            && $Operation->parameters === [8],
         description: 'Repository::delete compiles DELETE from a raw primary-key value'
      );

      $EmptyInsertRepository = $Database->map(EmptyInsert::class);
      $EmptyInsert = new EmptyInsert;
      $rejected = false;
      try {
         $EmptyInsertRepository->save($EmptyInsert);
      }
      catch (RuntimeException) {
         $rejected = true;
      }

      yield assert(
         assertion: $rejected,
         description: 'Repository::save rejects INSERT operations without writable columns'
      );

      $EmptyUpdateRepository = $Database->map(EmptyUpdate::class);
      $EmptyUpdate = new EmptyUpdate;
      $EmptyUpdate->id = 9;
      $rejected = false;
      try {
         $EmptyUpdateRepository->save($EmptyUpdate);
      }
      catch (RuntimeException) {
         $rejected = true;
      }

      yield assert(
         assertion: $rejected,
         description: 'Repository::save rejects UPDATE operations without writable columns'
      );
   }
);
