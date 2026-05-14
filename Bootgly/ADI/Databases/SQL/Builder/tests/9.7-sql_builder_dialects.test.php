<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Dialects;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


enum Tables: string
{
   case Users = 'users';
}

enum Columns: string
{
   case Id = 'id';
   case Name = 'name';
}

class TestDialect extends PostgreSQL
{
   public function quote (string $name): string
   {
      return "`{$name}`";
   }

   public function mark (int $position): string
   {
      return "?{$position}";
   }
}


return new Specification(
   description: 'Database: SQL builder compiles through concrete dialects',
   test: function () {
      $Dialect = new TestDialect;
      $Query = (new Builder($Dialect))
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 1)
         ->compile();

      yield assert(
         assertion: $Query->sql === 'SELECT `id` FROM `users` WHERE `id` = ?1'
            && $Query->parameters === [1],
         description: 'Builder delegates quoting and placeholders to the injected dialect strategy'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 1)
         ->compile($Dialect);

      yield assert(
         assertion: $Query->sql === 'SELECT `id` FROM `users` WHERE `id` = ?1'
            && $Query->parameters === [1],
         description: 'Builder replays fluent actions for compile-time dialect selection'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->select(Columns::Id)
         ->filter(Columns::Id, Operators::Equal, 1)
         ->compile(new SQLite);

      yield assert(
         assertion: $Query->sql === 'SELECT "id" FROM "users" WHERE "id" = ?1'
            && $Query->parameters === [1],
         description: 'Builder compiles SQLite SQL through a concrete dialect'
      );

      $Query = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1)
         ->set(Columns::Name, 'Ada')
         ->upsert(Columns::Id)
         ->compile(new MySQL);

      yield assert(
         assertion: $Query->sql === 'INSERT INTO `users` (`id`, `name`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `name` = VALUES(`name`)'
            && $Query->parameters === [1, 'Ada'],
         description: 'Builder compiles MySQL SQL through a concrete dialect'
      );

      $Builder = (new Builder)
         ->table(Tables::Users)
         ->insert()
         ->set(Columns::Id, 1)
         ->set(Columns::Name, 'Ada')
         ->upsert(Columns::Id);
      $First = $Builder->compile(new MySQL);
      $Second = $Builder->compile(new MySQL);

      yield assert(
         assertion: $First === $Second,
         description: 'Builder memoizes compile-time dialect replay by dialect class'
      );

      Identifier::configure(new MySQL);

      try {
         $quoted = Identifier::quote(Columns::Name);
      }
      finally {
         Identifier::configure();
      }

      yield assert(
         assertion: $quoted === '`name`',
         description: 'Identifier can configure the default dialect for direct quote() calls'
      );

      yield assert(
         assertion: Auxiliaries::check(Operators::class)
            && Auxiliaries::check(Builder::class) === false,
         description: 'Auxiliaries exposes a load-bearing enum registry check'
      );

      $blocked = false;

      try {
         (new Builder(new MySQL))
            ->table(Tables::Users)
            ->insert()
            ->set(Columns::Id, 1)
            ->output(Columns::Id);
      }
      catch (InvalidArgumentException) {
         $blocked = true;
      }

      yield assert(
         assertion: $blocked,
         description: 'Builder rejects unsupported output columns when the dialect is configured'
      );
   }
);
