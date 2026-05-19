<?php

namespace Bootgly\ADI\Databases\SQL\Repository\Tests\Metadata;


use function assert;
use InvalidArgumentException;
use ReflectionProperty;
use stdClass;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;
use Bootgly\ADI\Databases\SQL\Model\Column;
use Bootgly\ADI\Databases\SQL\Model\Key;
use Bootgly\ADI\Databases\SQL\Model\Relation;
use Bootgly\ADI\Databases\SQL\Model\Table;
use Bootgly\ADI\Databases\SQL\Models;


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
   /** @var array<int,Post> */
   #[Relation(Relations::HasMany, Post::class, 'id', 'user_id')]
   public array $posts = [];
}

#[Table('orm_posts')]
class Post
{
   #[Key]
   public null|int $id = null;
   #[Column('user_id')]
   public int $user = 0;
}

#[Table('orm_broken')]
class MissingKey
{
   #[Column]
   public string $name = '';
}

#[Table('orm_duplicates')]
class DuplicateColumn
{
   #[Key]
   public null|int $id = null;
   #[Column('name')]
   public string $name = '';
   #[Column('name')]
   public string $label = '';
}

#[Table('orm_constructed_users')]
class ConstructedUser
{
   #[Key]
   public null|int $id = null;
   #[Column]
   public string $name;


   public function __construct (string $name)
   {
      $this->name = $name;
   }
}


return new Specification(
   description: 'Database: SQL ORM compiles model metadata from attributes',
   test: function () {
      $Models = new Models;
      $Model = $Models->fetch(User::class);

      yield assert(
         assertion: $Model === $Models->fetch(User::class)
            && $Model->table === 'orm_users'
            && $Model->key === 'id'
            && $Model->keyProperty === 'id'
            && $Model->generated
            && $Model->columns === [
               'id' => 'id',
               'name' => 'name',
               'email' => 'mail',
               'active' => 'active',
            ],
         description: 'Models caches compiled table key and column metadata'
      );

      yield assert(
         assertion: $Model->insertions === [
            'name' => 'name',
            'email' => 'mail',
            'active' => 'active',
         ]
            && $Model->updates === [
               'name' => 'name',
               'email' => 'mail',
               'active' => 'active',
            ],
         description: 'Model separates generated primary keys from insert and update columns'
      );

      yield assert(
         assertion: isset($Model->relations['posts'])
            && $Model->relations['posts']->Type === Relations::HasMany
            && $Model->relations['posts']->target === Post::class,
         description: 'Model maps relationship attributes by property name'
      );

      $invalid = false;
      try {
         $Model->validate(new stdClass);
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield assert(
         assertion: $invalid,
         description: 'Model::validate rejects objects from a different entity class'
      );

      $Constructed = $Models->fetch(ConstructedUser::class);
      $Entity = $Constructed->create();
      $Property = new ReflectionProperty($Entity, 'name');

      yield assert(
         assertion: $Entity instanceof ConstructedUser
            && $Property->isInitialized($Entity) === false,
         description: 'Model::create hydrates entities with required constructor parameters without invoking the constructor'
      );

      $missing = false;
      try {
         $Models->fetch(MissingKey::class);
      }
      catch (InvalidArgumentException) {
         $missing = true;
      }

      yield assert(
         assertion: $missing,
         description: 'Model rejects entities without a primary key'
      );

      $duplicated = false;
      try {
         $Models->fetch(DuplicateColumn::class);
      }
      catch (InvalidArgumentException) {
         $duplicated = true;
      }

      yield assert(
         assertion: $duplicated,
         description: 'Model rejects duplicated column mappings'
      );
   }
);
