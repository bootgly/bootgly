<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\EmptyWindow;


use function assert;
use function count;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Awaiting;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;


#[Table('window_users')]
class Author
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

#[Table('window_posts')]
class Post
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id', nullable: true)]
   public null|int $user = null;
   #[Relation(Relations::BelongsTo, Author::class, 'user', 'id')]
   public null|Author $Author = null;
   /** @var array<int,Author> */
   #[Relation(Relations::HasMany, Author::class, 'user', 'id', name: 'Peers')]
   public array $Peers = [];
}

class RecordingAwaiting implements Awaiting
{
   public function await (Operation $Operation): Operation
   {
      // ! No relation query should ever reach here in these sections.
      return $Operation->resolve(new Result('SELECT 0'));
   }
}

class RecordingSQL extends SQL
{
   public null|Result $Next = null;


   /**
    * @param string|Builder|Query $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|Query $query, array $parameters = [], null|object $Scope = null): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      $Operation->resolve($this->Next ?? new Result('OK'));
      $this->Next = null;

      return $Operation;
   }
}


return new Test(
   description: 'ORM: a relation nobody can look up is written empty, not left as it was',
   test: function () {
      $open = static fn (): RecordingSQL =>
         new RecordingSQL(['driver' => 'mysql', 'pool' => ['min' => 0, 'max' => 0]]);

      // # Every parent's local key is null
      //   load() collects the keys and finds none, so it produces no operation.
      //   The relation still has an answer — the empty one — and abandoning it
      //   leaves the property holding whatever it held before.
      $Database = $open();
      $Repository = $Database->map(Post::class);

      $Database->Next = new Result('SELECT 1', [
         ['id' => 1, 'user_id' => null],
      ]);
      $Operation = $Repository->fetch($Repository->select()->load('Author', 'Peers'));
      $Mapped = $Repository->hydrate($Operation);
      $Post = $Mapped->entity;

      yield assert(
         assertion: $Mapped->loads === [],
         description: 'A window with no local key produces no relation operation'
      );

      yield assert(
         assertion: $Post->Author === null && $Post->Peers === [],
         description: 'Both cardinalities are written to their empty form'
      );

      // # A stale relation does not survive the re-hydration
      //   The identity map hands the same object back, so whatever a previous
      //   window wrote stays unless this one overwrites it.
      $Stale = new Author;
      $Stale->name = 'STALE';
      $Post->Author = $Stale;
      $Post->Peers = [$Stale];

      $Database->Next = new Result('SELECT 1', [
         ['id' => 1, 'user_id' => null],
      ]);
      $Operation = $Repository->fetch($Repository->select()->load('Author', 'Peers'));
      $Again = $Repository->hydrate($Operation)->entity;

      yield assert(
         assertion: $Again === $Post && $Again->Author === null && $Again->Peers === [],
         description: 'A stale related entity is cleared by the window that carries no key'
      );

      // # A window where a parent does carry the key is untouched
      $Database = $open();
      $Repository = $Database->map(Post::class);

      $Database->Next = new Result('SELECT 1', [
         ['id' => 2, 'user_id' => 7],
      ]);
      $Operation = $Repository->fetch($Repository->select()->load('Author'));
      $Mapped = $Repository->hydrate($Operation);

      yield assert(
         assertion: count($Mapped->loads) === 1 && isset($Mapped->loads['Author']),
         description: 'A window that carries a key still produces its relation operation'
      );

      // # The eager path has the same hole and needs the same write
      //   With an await bridge configured, pull() drives the relations instead
      //   of handing them back in $loads — and it skipped the same relation for
      //   the same reason.
      $Database = $open();
      $Repository = $Database->map(Post::class, Awaiting: new RecordingAwaiting);

      $Database->Next = new Result('SELECT 1', [
         ['id' => 4, 'user_id' => null],
      ]);
      $Operation = $Repository->fetch($Repository->select()->load('Author', 'Peers'));
      $Eager = $Repository->hydrate($Operation)->entity;

      $Stale = new Author;
      $Stale->name = 'STALE';
      $Eager->Author = $Stale;
      $Eager->Peers = [$Stale];

      $Database->Next = new Result('SELECT 1', [
         ['id' => 4, 'user_id' => null],
      ]);
      $Operation = $Repository->fetch($Repository->select()->load('Author', 'Peers'));
      $Again = $Repository->hydrate($Operation)->entity;

      yield assert(
         assertion: $Again === $Eager && $Again->Author === null && $Again->Peers === [],
         description: 'The eager path clears the relation it cannot look up'
      );
   }
);
