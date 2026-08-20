<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Provenance;


use function assert;
use function str_contains;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;


#[Table('provenance_people')]
class Person
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   #[Column(nullable: true)]
   public null|string $email = null;
   // ! A class default that reads exactly like a value the caller chose.
   #[Column(nullable: true)]
   public null|string $bio = 'PLACEHOLDER';
   #[Column(generated: true, nullable: true)]
   public null|string $created = null;
}

#[Table('provenance_sparse')]
class Sparse
{
   #[Key]
   public null|int $id = null;
   #[Column(nullable: true)]
   public null|string $note = 'DEFAULT';
}

class RecordingSQL extends SQL
{
   /** @var array<int,string> */
   public array $queries = [];
   /** @var array<int,array<int|string,mixed>> */
   public array $parameters = [];
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
      $this->parameters[] = $Normalized->parameters;

      $Operation->resolve($this->Next ?? new Result('OK'));
      $this->Next = null;

      return $Operation;
   }
}


return new Test(
   description: 'ORM: an update writes the columns its entity actually carries',
   test: function () {
      $open = static fn (): RecordingSQL =>
         new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);

      // # A row that omits a column leaves a default nobody chose
      //   `nullable` and `generated` columns are legalized when a projection
      //   omits them, so the property keeps its class default — indistinguishable
      //   from a value the caller set. Writing the whole map back replaced
      //   stored data with those defaults and reported success.
      $Database = $open();
      $Repository = $Database->map(Person::class);

      $Mapped = $Repository->hydrate(new Result('SELECT 1', [
         ['id' => 1, 'name' => 'Ada'],
      ]));
      $Person = $Mapped->entity;

      $Person->name = 'Ada Lovelace';
      $Repository->save($Person);

      $update = $Database->queries[0] ?? '';

      yield assert(
         assertion: str_contains($update, '`name`')
            && str_contains($update, '`email`') === false
            && str_contains($update, '`bio`') === false,
         description: 'Only the column the row carried is written back'
      );

      yield assert(
         assertion: $Database->parameters[0] === ['Ada Lovelace', 1],
         description: 'The omitted columns contribute no parameters either'
      );

      // # An entity the repository never hydrated keeps the full-row semantics
      //   Nothing is known about it, so every mapped column is the caller's.
      $Database = $open();
      $Repository = $Database->map(Person::class);

      $Built = new Person;
      $Built->id = 7;
      $Built->name = 'Grace';
      $Built->email = 'grace@example.com';
      $Repository->save($Built);

      $update = $Database->queries[0] ?? '';

      yield assert(
         assertion: str_contains($update, 'UPDATE')
            && str_contains($update, '`name`')
            && str_contains($update, '`email`')
            && str_contains($update, '`bio`'),
         description: 'An entity nobody hydrated still writes every mapped column'
      );

      // # A generated column with no value is the database's to fill
      //   insert() has always skipped it; update() nulled it out instead.
      yield assert(
         assertion: str_contains($update, '`created`') === false,
         description: 'A generated column holding null is never written'
      );

      // # A second hydration adds what it carried
      //   Provenance is the union of the rows an entity came from, so a wider
      //   projection later unlocks the columns the first one omitted.
      $Database = $open();
      $Repository = $Database->map(Person::class);

      $Mapped = $Repository->hydrate(new Result('SELECT 1', [
         ['id' => 3, 'name' => 'Ada'],
      ]));
      $Person = $Mapped->entity;

      $Repository->hydrate(new Result('SELECT 1', [
         ['id' => 3, 'name' => 'Ada', 'email' => 'ada@example.com'],
      ]));

      $Person->name = 'Ada L';
      $Repository->save($Person);

      $update = $Database->queries[0] ?? '';

      yield assert(
         assertion: str_contains($update, '`name`')
            && str_contains($update, '`email`')
            && str_contains($update, '`bio`') === false,
         description: 'A wider projection later unlocks the column it carried'
      );

      // # An update with nothing it may write is refused, not emitted empty
      $Database = $open();
      $Repository = $Database->map(Sparse::class);

      $Mapped = $Repository->hydrate(new Result('SELECT 1', [
         ['id' => 5],
      ]));
      $Bare = $Mapped->entity;

      $refused = null;

      try {
         $Repository->save($Bare);
      }
      catch (Throwable $Thrown) {
         $refused = $Thrown->getMessage();
      }

      yield assert(
         assertion: $refused === 'ORM update has no column carrying a value to write.',
         description: 'An update that may write nothing is refused instead of emitted'
      );
   }
);
