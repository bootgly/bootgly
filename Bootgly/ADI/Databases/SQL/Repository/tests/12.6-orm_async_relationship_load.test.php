<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\AsyncRelations;


use function assert;
use function count;
use function getenv;
use function stream_select;
use RuntimeException;

use Bootgly\ACI\Events\Scheduler;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Repository\LazyCollection;
use Bootgly\ADI\Databases\SQL\Repository\LazyReference;


#[Table('orm_async_relation_users')]
class AsyncRelationUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var array<int,AsyncRelationPost> */
   #[Relation(Relations::HasMany, AsyncRelationPost::class, 'id', 'user_id')]
   public array $posts = [];
   #[Relation(Relations::HasOne, AsyncRelationProfile::class, 'id', 'user_id')]
   public null|AsyncRelationProfile $profile = null;
   /** @var array<int,AsyncRelationGroup> */
   #[Relation(Relations::BelongsToMany, AsyncRelationGroup::class, 'id', 'id', table: 'orm_async_relation_memberships', pivotLocal: 'user_id', pivotForeign: 'group_id')]
   public array $groups = [];
}

#[Table('orm_async_relation_posts')]
class AsyncRelationPost
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $title = '';
}

#[Table('orm_async_relation_profiles')]
class AsyncRelationProfile
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $bio = '';
}

#[Table('orm_async_relation_groups')]
class AsyncRelationGroup
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

#[Table('orm_async_relation_users')]
class AsyncLazyUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var LazyCollection<AsyncLazyPost> */
   #[Relation(Relations::HasMany, AsyncLazyPost::class, 'id', 'user_id', lazy: true)]
   public LazyCollection $posts;
   #[Relation(Relations::HasOne, AsyncLazyProfile::class, 'id', 'user_id', lazy: true)]
   public LazyReference $profile;
   /** @var LazyCollection<AsyncLazyGroup> */
   #[Relation(Relations::BelongsToMany, AsyncLazyGroup::class, 'id', 'id', table: 'orm_async_relation_memberships', pivotLocal: 'user_id', pivotForeign: 'group_id', lazy: true)]
   public LazyCollection $groups;
}

#[Table('orm_async_relation_posts')]
class AsyncLazyPost
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $title = '';
   #[Relation(Relations::BelongsTo, AsyncLazyUser::class, 'user', 'id', lazy: true)]
   public LazyReference $author;
}

#[Table('orm_async_relation_profiles')]
class AsyncLazyProfile
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $bio = '';
}

#[Table('orm_async_relation_groups')]
class AsyncLazyGroup
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}


return new Specification(
   description: 'Database: SQL ORM loads and attaches deferred relationships through the native async PostgreSQL driver',
   skip: getenv('BOOTGLY_ORM_ASYNC_E2E') !== '1',
   test: function () {
      /**
       * @return array{Operation,bool}
       */
      $await = static function (SQL $Database, Operation $Operation): array {
         $readied = false;
         $attempts = 0;

         while ($Operation->finished === false && $attempts < 300) {
            $Database->advance($Operation);

            if ($Operation->Readiness !== null) {
               $readied = true;
               $Readiness = $Operation->Readiness;
               $reads = $Readiness->flag === Scheduler::SCHEDULE_READ ? [$Readiness->socket] : [];
               $writes = $Readiness->flag === Scheduler::SCHEDULE_WRITE ? [$Readiness->socket] : [];
               $except = [];

               if ($reads !== [] || $writes !== []) {
                  stream_select($reads, $writes, $except, 1, 0);
               }
            }

            $attempts++;
         }

         if ($Operation->finished === false) {
            throw new RuntimeException('ORM async relation operation did not finish.');
         }

         if ($Operation->error !== null) {
            throw new RuntimeException($Operation->error);
         }

         return [$Operation, $readied];
      };

      $Database = new SQL([
         'driver' => 'pgsql',
         'host' => getenv('DB_HOST') ?: '127.0.0.1',
         'port' => getenv('DB_PORT') ?: 5432,
         'database' => getenv('DB_NAME') ?: 'bootgly',
         'username' => getenv('DB_USER') ?: 'postgres',
         'password' => getenv('DB_PASS') ?: '',
         'secure' => ['mode' => 'disable'],
         'pool' => ['min' => 0, 'max' => 1],
         'timeout' => 5.0,
      ]);

      foreach ([
         'DROP TABLE IF EXISTS orm_async_relation_memberships',
         'DROP TABLE IF EXISTS orm_async_relation_posts',
         'DROP TABLE IF EXISTS orm_async_relation_profiles',
         'DROP TABLE IF EXISTS orm_async_relation_groups',
         'DROP TABLE IF EXISTS orm_async_relation_users',
         'CREATE TABLE orm_async_relation_users (id integer PRIMARY KEY, name text NOT NULL)',
         'CREATE TABLE orm_async_relation_posts (id integer PRIMARY KEY, user_id integer NOT NULL, title text NOT NULL)',
         'CREATE TABLE orm_async_relation_profiles (id integer PRIMARY KEY, user_id integer NOT NULL, bio text NOT NULL)',
         'CREATE TABLE orm_async_relation_groups (id integer PRIMARY KEY, name text NOT NULL)',
         'CREATE TABLE orm_async_relation_memberships (user_id integer NOT NULL, group_id integer NOT NULL)',
         "INSERT INTO orm_async_relation_users (id, name) VALUES (1, 'Async Ada'), (2, 'Async Grace')",
         "INSERT INTO orm_async_relation_posts (id, user_id, title) VALUES (10, 1, 'Analytical engine notes'), (11, 1, 'Symbolic computation'), (12, 2, 'Compiler operations')",
         "INSERT INTO orm_async_relation_profiles (id, user_id, bio) VALUES (20, 1, 'First programmer'), (21, 2, 'Compiler pioneer')",
         "INSERT INTO orm_async_relation_groups (id, name) VALUES (30, 'Computing'), (31, 'Mathematics')",
         'INSERT INTO orm_async_relation_memberships (user_id, group_id) VALUES (1, 30), (1, 31), (2, 30)',
      ] as $statement) {
         $await($Database, $Database->query($statement));
      }

      $readiness = false;
      $Repository = $Database->map(AsyncRelationUser::class);
      [$Operation, $ready] = $await(
         $Database,
         $Repository->fetch(
            $Repository
               ->select()
               ->load('posts', 'profile', 'groups')
         )
      );
      $readiness = $readiness || $ready;
      $Mapped = $Repository->hydrate($Operation);

      yield assert(
         assertion: count($Mapped->entities) === 2
            && isset($Mapped->loads['posts'], $Mapped->loads['profile'], $Mapped->loads['groups'])
            && count($Mapped->loads) === 3,
         description: 'Repository::hydrate exposes one deferred Operation per requested relation after a real root fetch'
      );

      foreach ($Mapped->loads as $relation => $Operation) {
         [$Operation, $ready] = $await($Database, $Operation);
         $readiness = $readiness || $ready;
         $Repository->attach($Mapped->entities, $relation, $Operation);
      }

      $users = [];
      foreach ($Mapped->entities as $Entity) {
         if ($Entity instanceof AsyncRelationUser && $Entity->id !== null) {
            $users[$Entity->id] = $Entity;
         }
      }

      $Ada = $users[1] ?? null;
      $Grace = $users[2] ?? null;

      yield assert(
         assertion: $Ada instanceof AsyncRelationUser
            && $Grace instanceof AsyncRelationUser
            && count($Ada->posts) === 2
            && $Ada->posts[0] instanceof AsyncRelationPost
            && $Ada->posts[0]->user === 1
            && $Ada->profile instanceof AsyncRelationProfile
            && $Ada->profile->bio === 'First programmer'
            && count($Ada->groups) === 2
            && $Ada->groups[0] instanceof AsyncRelationGroup
            && count($Grace->posts) === 1
            && $Grace->profile instanceof AsyncRelationProfile
            && count($Grace->groups) === 1
            && $readiness,
         description: 'Repository::attach materializes hasMany hasOne and belongsToMany relations after real async waits'
      );

      $EagerRepository = $Database->map(AsyncRelationUser::class, Awaiting: $Database);
      [$EagerOperation, $ready] = $await(
         $Database,
         $EagerRepository->fetch(
            $EagerRepository
               ->select()
               ->load('posts', 'profile', 'groups')
         )
      );
      $readiness = $readiness || $ready;
      $EagerMapped = $EagerRepository->hydrate($EagerOperation);

      $eagerUsers = [];
      foreach ($EagerMapped->entities as $Entity) {
         if ($Entity instanceof AsyncRelationUser && $Entity->id !== null) {
            $eagerUsers[$Entity->id] = $Entity;
         }
      }

      $EagerAda = $eagerUsers[1] ?? null;
      $EagerGrace = $eagerUsers[2] ?? null;

      yield assert(
         assertion: $EagerMapped->loads === []
            && $EagerAda instanceof AsyncRelationUser
            && $EagerGrace instanceof AsyncRelationUser
            && count($EagerAda->posts) === 2
            && $EagerAda->profile instanceof AsyncRelationProfile
            && count($EagerAda->groups) === 2
            && count($EagerGrace->posts) === 1
            && $EagerGrace->profile instanceof AsyncRelationProfile
            && count($EagerGrace->groups) === 1,
         description: 'Repository::hydrate eagerly awaits and attaches real relation operations when SQL is injected as await bridge'
      );

      $LazyRepository = $Database->map(AsyncLazyUser::class, Awaiting: $Database);
      [$LazyOperation, $ready] = $await($Database, $LazyRepository->fetch());
      $readiness = $readiness || $ready;
      $LazyMapped = $LazyRepository->hydrate($LazyOperation);

      $lazyUsers = [];
      foreach ($LazyMapped->entities as $Entity) {
         if ($Entity instanceof AsyncLazyUser && $Entity->id !== null) {
            $lazyUsers[$Entity->id] = $Entity;
         }
      }

      $LazyAda = $lazyUsers[1] ?? null;
      $LazyGrace = $lazyUsers[2] ?? null;
      $LazyAdaProfile = $LazyAda instanceof AsyncLazyUser ? $LazyAda->profile->fetch() : null;

      yield assert(
         assertion: $LazyMapped->loads === []
            && $LazyAda instanceof AsyncLazyUser
            && $LazyGrace instanceof AsyncLazyUser
            && $LazyAda->posts instanceof LazyCollection
            && count($LazyAda->posts) === 2
            && $LazyAda->posts[0] instanceof AsyncLazyPost
            && $LazyAda->posts[0]->title === 'Analytical engine notes'
            && count($LazyGrace->posts) === 1
            && $LazyAdaProfile instanceof AsyncLazyProfile
            && $LazyAdaProfile->bio === 'First programmer'
            && count($LazyAda->groups) === 2
            && $LazyAda->groups[0] instanceof AsyncLazyGroup
            && count($LazyGrace->groups) === 1,
         description: 'Lazy ORM relations batch-load and materialize through the real async PostgreSQL driver'
      );

      $LazyPostRepository = $Database->map(AsyncLazyPost::class, Awaiting: $Database);
      [$LazyPostOperation, $ready] = $await($Database, $LazyPostRepository->fetch());
      $readiness = $readiness || $ready;
      $LazyPosts = $LazyPostRepository->hydrate($LazyPostOperation);
      /** @var AsyncLazyPost $LazyPost */
      $LazyPost = $LazyPosts->entities[0];
      $LazyAuthor = $LazyPost->author->fetch();

      yield assert(
         assertion: $LazyAuthor instanceof AsyncLazyUser
            && $LazyAuthor->name === 'Async Ada',
         description: 'Lazy ORM belongsTo references resolve through the real async PostgreSQL driver'
      );

      foreach ([
         'DROP TABLE IF EXISTS orm_async_relation_memberships',
         'DROP TABLE IF EXISTS orm_async_relation_posts',
         'DROP TABLE IF EXISTS orm_async_relation_profiles',
         'DROP TABLE IF EXISTS orm_async_relation_groups',
         'DROP TABLE IF EXISTS orm_async_relation_users',
      ] as $statement) {
         $await($Database, $Database->query($statement));
      }
   }
);
