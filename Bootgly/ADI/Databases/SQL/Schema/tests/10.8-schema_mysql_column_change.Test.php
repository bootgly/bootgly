<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Defaults;
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
            && str_contains($refused, 'generate()')
            && str_contains($refused, 'COMMENT or COLLATE'),
         description: 'A bare type change is refused, naming what must be restated'
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

      // # An identity key keeps generating
      //   `MODIFY` drops AUTO_INCREMENT with everything else it does not
      //   restate, and the next insert then has nothing to supply the key —
      //   `1364: Field 'id' doesn't have a default value`. Nothing in the
      //   blueprint could say it before, so the shape this dialect recommends
      //   destroyed exactly what the refusal exists to protect.
      $identity = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger)->generate();
         $Change->nullable = false;
      });

      yield assert(
         assertion: $identity === 'ALTER TABLE `t` MODIFY COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT',
         description: 'A retyped identity column keeps generating'
      );

      // # …and stating it is enough to mean a type change
      //   Otherwise the caller has to call a shaper whose argument this type
      //   discards, purely to keep the change typed.
      $unshaped = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger);
         $Change->generate();
         $Change->nullable = false;
      });

      yield assert(
         assertion: $unshaped === $identity,
         description: 'Stating the identity keeps the change typed without a shaper'
      );

      // # Dropping a default alongside a type change rides in the same action
      //   A `MODIFY` that carries no DEFAULT clause IS the drop, so emitting
      //   `ALTER COLUMN … DROP DEFAULT` beside it targets a column the MODIFY
      //   has already redefined — which MySQL rejects with 1054.
      $dropped = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('n', Types::Integer)->limit(1);
         $Change->nullable = false;
         $Change->default = Defaults::None;
      });

      yield assert(
         assertion: $dropped === 'ALTER TABLE `t` MODIFY COLUMN `n` INT NOT NULL',
         description: 'A type change that also drops the default emits one action'
      );

      // # What MySQL forbids outright is refused before it reaches the server
      //   Opening the typed branch made these reachable for the first time: the
      //   unconditional capability guard used to refuse every typed-and-defaulted
      //   shape, so neither could be composed at all.
      $texted = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('bio', Types::Text)->limit(1);
         $Change->nullable = false;
         $Change->default = 'hi';
      });
      $contradicted = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('age', Types::BigInteger)->limit(1);
         $Change->nullable = false;
         $Change->default = null;
      });

      yield assert(
         assertion: str_contains($texted, 'BLOB, TEXT and JSON columns take none')
            && str_contains($contradicted, 'NOT NULL while it defaults to NULL'),
         description: 'Defaults MySQL rejects are refused rather than compiled'
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
