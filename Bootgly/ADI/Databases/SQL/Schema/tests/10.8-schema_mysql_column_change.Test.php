<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;


return new Test(
   description: 'Database: MySQL compiles a column change as a whole definition or not at all',
   test: function () {
      $Database = new SQL(['driver' => 'mysql']);
      /**
       * Compiles one alter closure, returning the SQL or the refusal.
       */
      $compile = static function (callable $shape) use ($Database): string {
         try {
            return $Database->structure()->alter('t', $shape)->SQL;
         }
         catch (Throwable $Refused) {
            return $Refused->getMessage();
         }
      };

      // # A type change that names only a type is refused
      //   `MODIFY COLUMN` takes a whole column definition, and MySQL silently
      //   reverts every attribute the statement leaves out. Compiling
      //   `MODIFY COLUMN `age` BIGINT` for a column declared
      //   `int NOT NULL DEFAULT '0' COMMENT 'user age'` drops the lot with
      //   `@@warning_count` at zero — and the next insert omitting the column
      //   stores NULL where it stored 0, or dies on 1364 if the column was the
      //   AUTO_INCREMENT key. A compiler never saw those attributes and cannot
      //   restate them, so it refuses rather than emitting the loss.
      $refused = $compile(static function (Blueprint $Table): void {
         $Table->change('age', Types::BigInteger);
      });

      yield assert(
         assertion: str_contains($refused, 'MySQL cannot retype the column "age" on its own')
            && str_contains($refused, 'limit() or size()'),
         description: 'A bare type change is refused, naming the shape that works'
      );

      // # Shaped and stated, it compiles the whole definition
      //   The refusal names this: shape the type, then state what the column
      //   must keep. Both ride inside the one `MODIFY`, because a standalone
      //   `ALTER COLUMN` beside it targets a column that `MODIFY` has already
      //   redefined, which MySQL rejects outright.
      $whole = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('age', Types::BigInteger)->size(20);
         $Change->nullable = false;
         $Change->default = 0;
      });

      yield assert(
         assertion: $whole === 'ALTER TABLE `t` MODIFY COLUMN `age` BIGINT NOT NULL DEFAULT 0',
         description: 'A shaped type change carries nullability and default in one action'
      );

      // # A nullable target simply omits NOT NULL
      $nullable = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('name', Types::String)->limit(320);
         $Change->nullable = true;
      });

      yield assert(
         assertion: $nullable === 'ALTER TABLE `t` MODIFY COLUMN `name` VARCHAR(320)',
         description: 'A shaped change to a nullable column omits NOT NULL'
      );

      // # Nullability on its own is still refused by the capability
      //   Stating it without shaping the type turns the change into a
      //   nullability-only one, and MySQL has no standalone action for that.
      //   The capability says so, and it must keep saying so: guarding it
      //   inside the typed branch would refuse the one statement that is right.
      $alone = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('age', Types::BigInteger);
         $Change->nullable = false;
      });

      yield assert(
         assertion: str_contains($alone, 'lacks capability: AlterColumnNullability'),
         description: 'Nullability without a shaped type is refused by the capability'
      );

      // # A default change on its own is untouched
      //   It needs no `MODIFY`, so it keeps the standalone action it always had.
      $defaulted = $compile(static function (Blueprint $Table): void {
         $Table->change('age', Types::BigInteger)->default = 7;
      });

      yield assert(
         assertion: $defaulted === 'ALTER TABLE `t` ALTER COLUMN `age` SET DEFAULT 7',
         description: 'A default-only change keeps its standalone action'
      );
   }
);
