<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Relationships;


use function assert;
use function count;
use function str_contains;
use RuntimeException;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test\Specification;
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


#[Table('orm_users')]
class User
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var array<int,Post> */
   #[Relation(Relations::HasMany, Post::class, 'id', 'user_id')]
   public array $posts = [];
   #[Relation(Relations::HasOne, Profile::class, 'id', 'user_id')]
   public null|Profile $profile = null;
   /** @var array<int,Group> */
   #[Relation(Relations::BelongsToMany, Group::class, 'id', 'id', table: 'orm_memberships', pivotLocal: 'user_id', pivotForeign: 'group_id')]
   public array $groups = [];
}

#[Table('orm_profiles')]
class Profile
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $bio = '';
}

#[Table('orm_posts')]
class Post
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $title = '';
   #[Relation(Relations::BelongsTo, User::class, 'user', 'id')]
   public null|User $author = null;
   #[Relation(Relations::BelongsTo, User::class, 'user_id', 'id', name: 'authorByColumn')]
   public null|User $AuthorByColumn = null;
}

#[Table('orm_groups')]
class Group
{
   #[Key]
   public null|int $id = null;
   #[Column]
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

class RecordingAwaiting implements Awaiting
{
   /** @var array<int,Operation> */
   public array $operations = [];


   public function await (Operation $Operation): Operation
   {
      $this->operations[] = $Operation;

      if (str_contains($Operation->SQL, 'FROM "orm_posts"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 13,
               'user_id' => 3,
               'title' => 'Eager compiler notes',
            ]],
            columns: ['id', 'user_id', 'title']
         ));
      }

      if (str_contains($Operation->SQL, 'FROM "orm_profiles"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 32,
               'user_id' => 3,
               'bio' => 'Eager pioneer',
            ]],
            columns: ['id', 'user_id', 'bio']
         ));
      }

      return $Operation->fail('Unexpected eager relation operation.');
   }
}


return new Specification(
   description: 'Database: SQL ORM loads relationships through explicit batch operations',
   test: function () {
      $Database = new RecordingSQL;
      $User = new User;
      $User->id = 1;
      $Repository = $Database->map(User::class);
      $Operations = $Repository->load($User, ['posts', 'groups']);

      yield assert(
         assertion: isset($Operations['posts'], $Operations['groups'])
            && $Operations['posts']->SQL === 'SELECT "id", "user_id", "title" FROM "orm_posts" WHERE "user_id" IN (?1)'
            && $Operations['posts']->parameters === [1],
         description: 'Repository::load compiles hasMany batch queries with an IN filter'
      );

      $Scope = new stdClass;
      $scopedOperations = $Repository->load($User, ['posts'], $Scope);

      yield assert(
         assertion: isset($scopedOperations['posts'])
            && $Database->scopes[2] === $Scope,
         description: 'Repository::load forwards a per-call SQL stickiness scope to relation operations'
      );

      yield assert(
         assertion: $Operations['groups']->SQL === 'SELECT "orm_groups"."id", "orm_groups"."name", "orm_memberships"."user_id" AS "__orm_local" FROM "orm_groups" JOIN "orm_memberships" ON "orm_groups"."id" = "orm_memberships"."group_id" WHERE "orm_memberships"."user_id" IN (?1)'
            && $Operations['groups']->parameters === [1],
         description: 'Repository::load compiles belongsToMany batch queries through a pivot table'
      );

      $Operations['posts']->resolve(new Result(
         rows: [[
            'id' => 10,
            'user_id' => 1,
            'title' => 'Analytical engine notes',
         ], [
            'id' => 11,
            'user_id' => 1,
            'title' => 'Symbolic computation',
         ]],
         columns: ['id', 'user_id', 'title']
      ));
      $Mapped = $Repository->attach($User, 'posts', $Operations['posts']);

      yield assert(
         assertion: $Mapped->count === 2
            && $User->posts[0] instanceof Post
            && $User->posts[0]->title === 'Analytical engine notes'
            && $User->posts[1] instanceof Post
            && $User->posts[1]->title === 'Symbolic computation',
         description: 'Repository::attach hydrates hasMany rows and assigns them to the parent entity'
      );

      $ProfileOperations = $Repository->load($User, ['profile']);

      yield assert(
         assertion: $ProfileOperations['profile']->SQL === 'SELECT "id", "user_id", "bio" FROM "orm_profiles" WHERE "user_id" IN (?1)'
            && $ProfileOperations['profile']->parameters === [1],
         description: 'Repository::load compiles hasOne batch queries from parent local keys'
      );

      $ProfileOperations['profile']->resolve(new Result(
         rows: [[
            'id' => 30,
            'user_id' => 1,
            'bio' => 'First programmer',
         ]],
         columns: ['id', 'user_id', 'bio']
      ));
      $Repository->attach($User, 'profile', $ProfileOperations['profile']);

      yield assert(
         assertion: $User->profile instanceof Profile
            && $User->profile->bio === 'First programmer',
         description: 'Repository::attach hydrates hasOne rows and assigns a single related entity'
      );

      $Operations['groups']->resolve(new Result(
         rows: [[
            'id' => 20,
            'name' => 'Computing',
            '__orm_local' => 1,
         ]],
         columns: ['id', 'name', '__orm_local']
      ));
      $Repository->attach($User, 'groups', $Operations['groups']);

      yield assert(
         assertion: $User->groups[0] instanceof Group
            && $User->groups[0]->name === 'Computing',
         description: 'Repository::attach hydrates belongsToMany rows and assigns them through the pivot local key'
      );

      $Operation = $Repository->fetch($Repository->select()->load('posts', 'profile'));
      $Operation->resolve(new Result(
         rows: [[
            'id' => 2,
            'name' => 'Grace',
         ]],
         columns: ['id', 'name']
      ));
      $Mapped = $Repository->hydrate($Operation);
      /** @var User $Loaded */
      $Loaded = $Mapped->entity;

      yield assert(
         assertion: $Loaded instanceof User
            && isset($Mapped->loads['posts'], $Mapped->loads['profile'])
            && $Mapped->loads['posts']->SQL === 'SELECT "id", "user_id", "title" FROM "orm_posts" WHERE "user_id" IN (?1)'
            && $Mapped->loads['posts']->parameters === [2]
            && $Mapped->loads['profile']->SQL === 'SELECT "id", "user_id", "bio" FROM "orm_profiles" WHERE "user_id" IN (?1)'
            && $Mapped->loads['profile']->parameters === [2],
         description: 'Selection::load is wired into hydration as deferred relation operations'
      );

      $Mapped->loads['posts']->resolve(new Result(
         rows: [[
            'id' => 12,
            'user_id' => 2,
            'title' => 'Compiler operations',
         ]],
         columns: ['id', 'user_id', 'title']
      ));
      $Mapped->loads['profile']->resolve(new Result(
         rows: [[
            'id' => 31,
            'user_id' => 2,
            'bio' => 'Compiler pioneer',
         ]],
         columns: ['id', 'user_id', 'bio']
      ));

      foreach ($Mapped->loads as $relation => $RelationOperation) {
         $Repository->attach($Mapped->entities, $relation, $RelationOperation);
      }

      yield assert(
         assertion: $Loaded->posts[0] instanceof Post
            && $Loaded->posts[0]->title === 'Compiler operations'
            && $Loaded->profile instanceof Profile
            && $Loaded->profile->bio === 'Compiler pioneer',
         description: 'MappedResult relation operations are consumed by awaiting each relation and attaching it explicitly'
      );

      $EagerDatabase = new RecordingSQL;
      $Awaiting = new RecordingAwaiting;
      $EagerRepository = $EagerDatabase->map(User::class, Awaiting: $Awaiting);
      $EagerOperation = $EagerRepository->fetch($EagerRepository->select()->load('posts', 'profile'));
      $EagerOperation->resolve(new Result(
         rows: [[
            'id' => 3,
            'name' => 'Eager Grace',
         ]],
         columns: ['id', 'name']
      ));
      $EagerMapped = $EagerRepository->hydrate($EagerOperation);
      /** @var User $EagerLoaded */
      $EagerLoaded = $EagerMapped->entity;

      yield assert(
         assertion: $EagerLoaded instanceof User
            && $EagerMapped->loads === []
            && count($Awaiting->operations) === 2
            && $EagerLoaded->posts[0] instanceof Post
            && $EagerLoaded->posts[0]->title === 'Eager compiler notes'
            && $EagerLoaded->profile instanceof Profile
            && $EagerLoaded->profile->bio === 'Eager pioneer',
         description: 'Selection::load eagerly awaits and attaches relation operations when an await bridge is injected'
      );

      $Post = new Post;
      $Post->id = 10;
      $Post->user = 1;
      $PostRepository = $Database->map(Post::class);
      $Operations = $PostRepository->load($Post, ['author']);

      yield assert(
         assertion: $Operations['author']->SQL === 'SELECT "id", "name" FROM "orm_users" WHERE "id" IN (?1)'
            && $Operations['author']->parameters === [1],
         description: 'Repository::load compiles belongsTo batch queries from local foreign keys'
      );

      $Operations['author']->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $PostRepository->attach($Post, 'author', $Operations['author']);

      yield assert(
         assertion: $Post->author instanceof User
            && $Post->author->name === 'Ada',
         description: 'Repository::attach hydrates belongsTo rows and assigns the related entity'
      );

      $columnOperations = $PostRepository->load($Post, ['authorByColumn']);

      yield assert(
         assertion: $columnOperations['authorByColumn']->SQL === 'SELECT "id", "name" FROM "orm_users" WHERE "id" IN (?1)'
            && $columnOperations['authorByColumn']->parameters === [1],
         description: 'Repository resolves relation local keys provided as mapped column names'
      );

      $mismatch = false;
      try {
         $PostRepository->attach($Post, 'author', new Result(
            rows: [[
               'name' => 'Ada',
            ]],
            columns: ['name']
         ));
      }
      catch (RuntimeException) {
         $mismatch = true;
      }

      yield assert(
         assertion: $mismatch,
         description: 'Repository::attach rejects a result missing the relation key column instead of resolving empty'
      );
   }
);
