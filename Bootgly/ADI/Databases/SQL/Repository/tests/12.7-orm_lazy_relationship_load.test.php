<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\LazyRelationships;


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
use Bootgly\ADI\Databases\SQL\Repository\Hooks;
use Bootgly\ADI\Databases\SQL\Repository\LazyCollection;
use Bootgly\ADI\Databases\SQL\Repository\LazyReference;


#[Table('orm_lazy_users')]
class LazyUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var LazyCollection<LazyPost> */
   #[Relation(Relations::HasMany, LazyPost::class, 'id', 'user_id', lazy: true)]
   public LazyCollection $posts;
   #[Relation(Relations::HasOne, LazyProfile::class, 'id', 'user_id', lazy: true)]
   public LazyReference $profile;
   /** @var LazyCollection<LazyGroup> */
   #[Relation(Relations::BelongsToMany, LazyGroup::class, 'id', 'id', table: 'orm_lazy_memberships', pivotLocal: 'user_id', pivotForeign: 'group_id', lazy: true)]
   public LazyCollection $groups;
}

#[Table('orm_lazy_posts')]
class LazyPost
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $title = '';
   #[Relation(Relations::BelongsTo, LazyUser::class, 'user', 'id', lazy: true)]
   public LazyReference $author;
}

#[Table('orm_lazy_profiles')]
class LazyProfile
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
   #[Column]
   public string $bio = '';
}

#[Table('orm_lazy_groups')]
class LazyGroup
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
}

#[Table('orm_invalid_lazy_users')]
class InvalidLazyUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name = '';
   /** @var array<int,LazyPost> */
   #[Relation(Relations::HasMany, LazyPost::class, 'id', 'user_id', lazy: true)]
   public array $posts = [];
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
      $Operation = new Operation(null, $Normalized->sql, $Normalized->parameters);
      $this->queries[] = [
         'sql' => $Operation->sql,
         'parameters' => $Operation->parameters,
      ];
      $this->scopes[] = $Scope;

      return $Operation;
   }
}

class LazyAwaiting implements Awaiting
{
   /** @var array<int,Operation> */
   public array $operations = [];
   public bool $fail = false;


   public function await (Operation $Operation): Operation
   {
      $this->operations[] = $Operation;

      if ($this->fail) {
         return $Operation->fail('lazy failed');
      }

      if (str_contains($Operation->sql, 'FROM "orm_lazy_posts"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 10,
               'user_id' => 1,
               'title' => 'Analytical engine notes',
            ], [
               'id' => 11,
               'user_id' => 1,
               'title' => 'Symbolic computation',
            ], [
               'id' => 12,
               'user_id' => 2,
               'title' => 'Compiler operations',
            ]],
            columns: ['id', 'user_id', 'title']
         ));
      }

      if (str_contains($Operation->sql, 'FROM "orm_lazy_profiles"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 20,
               'user_id' => 1,
               'bio' => 'First programmer',
            ]],
            columns: ['id', 'user_id', 'bio']
         ));
      }

      if (str_contains($Operation->sql, 'FROM "orm_lazy_groups"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 30,
               'name' => 'Computing',
               '__orm_local' => 1,
            ], [
               'id' => 31,
               'name' => 'Mathematics',
               '__orm_local' => 1,
            ], [
               'id' => 30,
               'name' => 'Computing',
               '__orm_local' => 2,
            ]],
            columns: ['id', 'name', '__orm_local']
         ));
      }

      if (str_contains($Operation->sql, 'FROM "orm_lazy_users"')) {
         return $Operation->resolve(new Result(
            rows: [[
               'id' => 1,
               'name' => 'Ada',
            ], [
               'id' => 2,
               'name' => 'Grace',
            ]],
            columns: ['id', 'name']
         ));
      }

      return $Operation->fail('Unexpected lazy relation operation.');
   }
}


return new Specification(
   description: 'Database: SQL ORM lazy-loads relationships through batched relation wrappers',
   test: function () {
      $Database = new RecordingSQL;
      $Awaiting = new LazyAwaiting;
      $Scope = new stdClass;
      $Repository = $Database->map(LazyUser::class, Awaiting: $Awaiting);
      $Operation = $Repository->fetch(Scope: $Scope);
      $Operation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ], [
            'id' => 2,
            'name' => 'Grace',
         ]],
         columns: ['id', 'name']
      ));
      $Mapped = $Repository->hydrate($Operation);
      /** @var LazyUser $Ada */
      $Ada = $Mapped->entities[0];
      /** @var LazyUser $Grace */
      $Grace = $Mapped->entities[1];

      yield assert(
         assertion: count($Database->queries) === 1
            && $Ada->posts instanceof LazyCollection
            && $Ada->profile instanceof LazyReference
            && $Ada->groups instanceof LazyCollection,
         description: 'Repository::hydrate installs lazy wrappers without querying relations'
      );

      yield assert(
         assertion: count($Ada->posts) === 2
            && $Ada->posts[0] instanceof LazyPost
            && $Ada->posts[0]->title === 'Analytical engine notes'
            && count($Grace->posts) === 1
            && count($Database->queries) === 2
            && ($Database->queries[1]['parameters'] ?? []) === [1, 2]
            && ($Database->scopes[1] ?? null) === $Scope
            && count($Awaiting->operations) === 1,
         description: 'LazyCollection first access loads hasMany rows once for the whole hydration window'
      );

      /** @var LazyPost $FirstLazyPost */
      $FirstLazyPost = $Ada->posts[0];
      $ReloadOperation = $Repository->fetch();
      $ReloadOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ], [
            'id' => 2,
            'name' => 'Grace',
         ]],
         columns: ['id', 'name']
      ));
      $Reloaded = $Repository->hydrate($ReloadOperation);
      /** @var LazyUser $ReloadedAda */
      $ReloadedAda = $Reloaded->entities[0];
      /** @var LazyPost $ReloadedFirstPost */
      $ReloadedFirstPost = $ReloadedAda->posts[0];

      yield assert(
         assertion: $ReloadedAda === $Ada
            && $ReloadedFirstPost === $FirstLazyPost,
         description: 'LazyCollection target hydration reuses identity-map entities across hydration windows'
      );

      $queries = count($Database->queries);
      $operations = count($Awaiting->operations);

      yield assert(
         assertion: count($Ada->posts) === 2
            && count($Grace->posts) === 1
            && count($Database->queries) === $queries
            && count($Awaiting->operations) === $operations,
         description: 'LazyCollection reuses the loaded batch on later access'
      );

      /** @var null|LazyProfile $AdaProfile */
      $AdaProfile = $Ada->profile->fetch();
      $GraceProfile = $Grace->profile->fetch();
      $profileQueries = count($Database->queries);

      yield assert(
         assertion: $AdaProfile instanceof LazyProfile
            && $AdaProfile->bio === 'First programmer'
            && $GraceProfile === null
            && $Grace->profile->fetch() === null
            && count($Database->queries) === $profileQueries,
         description: 'LazyReference loads hasOne rows once and preserves missing relations as null'
      );

      yield assert(
         assertion: count($Ada->groups) === 2
            && $Ada->groups[0] instanceof LazyGroup
            && $Ada->groups[0]->name === 'Computing'
            && count($Grace->groups) === 1
            && str_contains($Database->queries[count($Database->queries) - 1]['sql'] ?? '', 'JOIN "orm_lazy_memberships"'),
         description: 'LazyCollection loads belongsToMany rows through the pivot batch query'
      );

      $PostRepository = $Database->map(LazyPost::class, Awaiting: $Awaiting);
      $PostOperation = $PostRepository->fetch();
      $PostOperation->resolve(new Result(
         rows: [[
            'id' => 50,
            'user_id' => 1,
            'title' => 'Post A',
         ], [
            'id' => 51,
            'user_id' => 2,
            'title' => 'Post B',
         ]],
         columns: ['id', 'user_id', 'title']
      ));
      $Posts = $PostRepository->hydrate($PostOperation);
      /** @var LazyPost $FirstPost */
      $FirstPost = $Posts->entities[0];
      /** @var LazyPost $SecondPost */
      $SecondPost = $Posts->entities[1];
      $authorQueries = count($Database->queries);
      /** @var null|LazyUser $Author */
      $Author = $FirstPost->author->fetch();
      /** @var null|LazyUser $SecondAuthor */
      $SecondAuthor = $SecondPost->author->fetch();

      yield assert(
         assertion: $Author instanceof LazyUser
            && $Author->name === 'Ada'
            && $SecondAuthor instanceof LazyUser
            && $SecondAuthor->name === 'Grace'
            && count($Database->queries) === $authorQueries + 1
            && ($Database->queries[$authorQueries]['parameters'] ?? []) === [1, 2],
         description: 'LazyReference loads belongsTo rows once for all parents in the hydration window'
      );

      $ReloadedPostOperation = $PostRepository->fetch();
      $ReloadedPostOperation->resolve(new Result(
         rows: [[
            'id' => 50,
            'user_id' => 1,
            'title' => 'Post A',
         ], [
            'id' => 51,
            'user_id' => 2,
            'title' => 'Post B',
         ]],
         columns: ['id', 'user_id', 'title']
      ));
      $ReloadedPosts = $PostRepository->hydrate($ReloadedPostOperation);
      /** @var LazyPost $ReloadedFirstPost */
      $ReloadedFirstPost = $ReloadedPosts->entities[0];
      /** @var null|LazyUser $ReloadedAuthor */
      $ReloadedAuthor = $ReloadedFirstPost->author->fetch();

      yield assert(
         assertion: $ReloadedFirstPost === $FirstPost
            && $ReloadedAuthor === $Author,
         description: 'LazyReference target hydration reuses identity-map entities across hydration windows'
      );

      $HookDatabase = new RecordingSQL;
      $HookAwaiting = new LazyAwaiting;
      $HookRepository = $HookDatabase->map(LazyUser::class, Awaiting: $HookAwaiting);
      $hookPosts = [];
      $hookProfiles = [];
      $hookQueries = [];
      $HookRepository->listen(Hooks::Hydrated, function ($Mapped) use (&$hookPosts, &$hookProfiles, &$hookQueries, $HookDatabase): void {
         /** @var LazyUser $HookUser */
         $HookUser = $Mapped->entity;
         $hookPosts[] = count($HookUser->posts);
         $Profile = $HookUser->profile->fetch();
         $hookProfiles[] = $Profile instanceof LazyProfile ? $Profile->bio : null;
         $hookQueries[] = count($HookDatabase->queries);
      });
      $HookOperation = $HookRepository->fetch();
      $HookOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $HookMapped = $HookRepository->hydrate($HookOperation);
      /** @var LazyUser $HookUser */
      $HookUser = $HookMapped->entity;
      $hookQueryCount = count($HookDatabase->queries);

      yield assert(
         assertion: $hookPosts === [2]
            && $hookProfiles === ['First programmer']
            && $hookQueries === [3]
            && count($HookUser->posts) === 2
            && $HookUser->profile->fetch() instanceof LazyProfile
            && count($HookDatabase->queries) === $hookQueryCount,
         description: 'Lazy relations can load inside Hydrated hooks without re-entrant state corruption or repeat queries'
      );

      $EagerDatabase = new RecordingSQL;
      $EagerAwaiting = new LazyAwaiting;
      $EagerRepository = $EagerDatabase->map(LazyUser::class, Awaiting: $EagerAwaiting);
      $EagerOperation = $EagerRepository->fetch($EagerRepository->select()->load('posts'));
      $EagerOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $EagerMapped = $EagerRepository->hydrate($EagerOperation);
      /** @var LazyUser $EagerUser */
      $EagerUser = $EagerMapped->entity;
      $eagerQueries = count($EagerDatabase->queries);

      yield assert(
         assertion: $EagerUser->posts instanceof LazyCollection
            && count($EagerUser->posts) === 2
            && count($EagerDatabase->queries) === $eagerQueries,
         description: 'Selection::load materializes a lazy relation eagerly without an extra first-access query'
      );

      $NoAwaitDatabase = new RecordingSQL;
      $NoAwaitRepository = $NoAwaitDatabase->map(LazyUser::class);
      $NoAwaitOperation = $NoAwaitRepository->fetch();
      $NoAwaitOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $missingAwait = false;

      try {
         $NoAwaitRepository->hydrate($NoAwaitOperation);
      }
      catch (RuntimeException $RuntimeException) {
         $missingAwait = $RuntimeException->getMessage() === 'ORM lazy loading requires an await bridge.';
      }

      yield assert(
         assertion: $missingAwait,
         description: 'Repository::hydrate rejects lazy relations when no await bridge is available'
      );

      $InvalidDatabase = new RecordingSQL;
      $InvalidRepository = $InvalidDatabase->map(InvalidLazyUser::class, Awaiting: new LazyAwaiting);
      $InvalidOperation = $InvalidRepository->fetch();
      $InvalidOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $incompatible = false;

      try {
         $InvalidRepository->hydrate($InvalidOperation);
      }
      catch (RuntimeException $RuntimeException) {
         $incompatible = str_contains($RuntimeException->getMessage(), LazyCollection::class);
      }

      yield assert(
         assertion: $incompatible,
         description: 'Repository::hydrate rejects lazy plural relations on array-only properties'
      );

      $FailDatabase = new RecordingSQL;
      $FailAwaiting = new LazyAwaiting;
      $FailAwaiting->fail = true;
      $FailRepository = $FailDatabase->map(LazyUser::class, Awaiting: $FailAwaiting);
      $FailOperation = $FailRepository->fetch();
      $FailOperation->resolve(new Result(
         rows: [[
            'id' => 1,
            'name' => 'Ada',
         ]],
         columns: ['id', 'name']
      ));
      $FailMapped = $FailRepository->hydrate($FailOperation);
      /** @var LazyUser $FailUser */
      $FailUser = $FailMapped->entity;
      $failed = false;

      try {
         count($FailUser->posts);
      }
      catch (RuntimeException $RuntimeException) {
         $failed = $RuntimeException->getMessage() === 'lazy failed';
      }

      yield assert(
         assertion: $failed,
         description: 'Lazy relation access propagates failed relation operations'
      );
   }
);