<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Hydration;


use function assert;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\Hooks;
use Bootgly\ADI\Databases\SQL\Repository\Selection;


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

class DisplayName
{
   public string $value;


   public function __construct (string $value)
   {
      $this->value = $value;
   }
}

#[Table('orm_typed_users')]
class TypedUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public DisplayName $name;
}

class RecordingSQL extends SQL
{
   /** @var array<int,array{sql:string,parameters:array<int|string,mixed>}> */
   public array $queries = [];


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

      return $Operation;
   }
}


return new Specification(
   description: 'Database: SQL ORM hydrates result rows and applies local scopes/hooks',
   test: function () {
      $Database = new RecordingSQL;
      $Repository = $Database->map(User::class);
      $Result = new Result(
         status: 'SELECT 2',
         rows: [[
            'id' => '1',
            'name' => 'Ada',
            'email' => 'ada@example.test',
            'active' => '1',
         ], [
            'id' => '1',
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.test',
            'active' => '0',
         ]],
         columns: ['id', 'name', 'email', 'active'],
      );
      $Mapped = $Repository->hydrate($Result);
      /** @var User $User */
      $User = $Mapped->entity;

      yield assert(
         assertion: $Mapped->count === 2
            && $Mapped->entities[0] === $Mapped->entities[1]
            && $User->id === 1
            && $User->name === 'Ada Lovelace'
            && $User->active === false,
         description: 'Repository::hydrate casts rows and reuses identity-map instances by primary key'
      );

      $First = $Mapped->entity;
      $Repository->reset();
      $Mapped = $Repository->hydrate(new Result(
         rows: [[
            'id' => '1',
            'name' => 'Reset Ada',
            'email' => 'reset@example.test',
            'active' => '1',
         ]],
         columns: ['id', 'name', 'email', 'active'],
      ));
      $AfterReset = $Mapped->entity;

      yield assert(
         assertion: $AfterReset instanceof User
            && $AfterReset !== $First
            && $AfterReset->name === 'Reset Ada',
         description: 'Repository::reset clears tracked identity-map instances'
      );

      $failed = false;
      try {
         $Repository->hydrate(new Result(rows: [[
            'id' => 2,
            'email' => 'missing-name@example.test',
         ]]));
      }
      catch (RuntimeException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed,
         description: 'Repository::hydrate rejects missing required non-generated columns'
      );

      $failed = false;
      try {
         $Repository->hydrate(new Result(rows: [[
            'id' => 3,
            'name' => null,
            'email' => 'null-name@example.test',
         ]]));
      }
      catch (RuntimeException) {
         $failed = true;
      }

      yield assert(
         assertion: $failed,
         description: 'Repository::hydrate rejects null values for non-nullable properties'
      );

      $TypedRepository = $Database->map(TypedUser::class);
      $Name = new DisplayName('Typed Ada');
      $Mapped = $TypedRepository->hydrate(new Result(rows: [[
         'id' => 4,
         'name' => $Name,
      ]]));
      /** @var TypedUser $TypedUser */
      $TypedUser = $Mapped->entity;

      yield assert(
         assertion: $TypedUser instanceof TypedUser
            && $TypedUser->name === $Name,
         description: 'Repository::hydrate leaves non-builtin typed values unchanged'
      );

      $events = [];
      $Repository
         ->scope('active', function (Selection $Selection): void {
            $Selection->filter(new Identifier('active'), Operators::Equal, true);
         })
         ->listen(Hooks::Selecting, function () use (&$events): void {
            $events[] = 'selecting';
         })
         ->listen(Hooks::Selected, function () use (&$events): void {
            $events[] = 'selected';
         })
         ->listen(Hooks::Hydrating, function () use (&$events): void {
            $events[] = 'hydrating';
         })
         ->listen(Hooks::Hydrated, function () use (&$events): void {
            $events[] = 'hydrated';
         });

      $Operation = $Repository->fetch($Repository->select()->scope('active'));
      $Operation->resolve($Result);
      $Repository->hydrate($Operation);

      yield assert(
         assertion: $Database->queries[0]['sql'] === 'SELECT "id", "name", "email", "active" FROM "orm_users" WHERE "active" = ?1'
            && $Database->queries[0]['parameters'] === [true]
            && $events === ['selecting', 'selected', 'hydrating', 'hydrated'],
         description: 'Repository applies named scopes before query compilation and emits local hooks'
      );
   }
);
