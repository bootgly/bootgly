<?php


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
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

      // # …and stating it is enough to mean a type change, in either order
      //   Otherwise the caller has to call a shaper whose argument this type
      //   discards, purely to keep the change typed. Both orders matter: naming
      //   nullability first clears the typed flag, and `generate()` has to put
      //   it back — anchored to the literal, because comparing the two results
      //   to each other stays green with the whole feature deleted.
      $after = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger);
         $Change->generate();
         $Change->nullable = false;
      });
      $before = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger);
         $Change->nullable = false;
         $Change->generate();
      });

      yield assert(
         assertion: $after === 'ALTER TABLE `t` MODIFY COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT'
            && $before === $after,
         description: 'Stating the identity keeps the change typed without a shaper'
      );

      // # The three types that carry an identity all compile it
      //   Naming only one of them leaves the other two free to be dropped from
      //   the allowlist without a test noticing — and dropping one is a refusal
      //   of something the server accepts, which is the direction that hurts.
      $carriers = [];

      foreach (['Integer' => Types::Integer, 'BigInteger' => Types::BigInteger, 'Boolean' => Types::Boolean] as $label => $Type) {
         $carriers[$label] = $compile(static function (Blueprint $Table) use ($Type): void {
            $Change = $Table->change('id', $Type)->generate();
            $Change->nullable = false;
         });
      }

      yield assert(
         assertion: $carriers === [
            'Integer'    => 'ALTER TABLE `t` MODIFY COLUMN `id` INT NOT NULL AUTO_INCREMENT',
            'BigInteger' => 'ALTER TABLE `t` MODIFY COLUMN `id` BIGINT NOT NULL AUTO_INCREMENT',
            'Boolean'    => 'ALTER TABLE `t` MODIFY COLUMN `id` BOOLEAN NOT NULL AUTO_INCREMENT',
         ],
         description: 'Every type that can carry an identity still compiles one'
      );

      // # …and an identity is never nullable
      //   MySQL makes the column NOT NULL whatever the statement says, so
      //   honouring a stated `true` would land the opposite of what was asked.
      $nullableKey = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger)->generate();
         $Change->nullable = true;
      });

      yield assert(
         assertion: str_contains($nullableKey, 'both AUTO_INCREMENT and nullable'),
         description: 'A nullable identity is refused rather than silently made NOT NULL'
      );

      // # The literal-default refusal covers all three column kinds
      //   `$texted` above exercises Text alone, and the JSON case reaches the
      //   compiler through the expression path, which bypasses this gate.
      $literals = [];

      foreach (['Text' => Types::Text, 'Json' => Types::Json, 'JsonB' => Types::JsonB] as $label => $Type) {
         $literals[$label] = str_contains(
            $compile(static function (Blueprint $Table) use ($Type): void {
               $Change = $Table->change('doc', $Type)->limit(1);
               $Change->nullable = true;
               $Change->default = 'hi';
            }),
            'take one only as an expression'
         );
      }

      yield assert(
         assertion: $literals === ['Text' => true, 'Json' => true, 'JsonB' => true],
         description: 'Every column kind that refuses a literal default is covered'
      );

      // # An identity contradicts a default, and most types cannot carry one
      //   Both are decidable from the change alone: MySQL answers 1067 to the
      //   first whichever order the clauses take, and 1063 to the second for
      //   every type but the integers.
      $defaulted = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::BigInteger)->generate();
         $Change->nullable = false;
         $Change->default = 0;
      });
      $mistyped = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('id', Types::String)->generate();
         $Change->nullable = false;
      });

      yield assert(
         assertion: str_contains($defaulted, 'supplies its own values')
            && str_contains($mistyped, 'only the integer types carry one'),
         description: 'An identity that MySQL could not accept is refused'
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
         assertion: str_contains($texted, 'take one only as an expression')
            && str_contains($contradicted, 'NOT NULL while it defaults to NULL'),
         description: 'Defaults MySQL rejects are refused rather than compiled'
      );

      // # …but the forms it accepts still compile
      //   `DEFAULT (expr)` is the documented way to default a TEXT or JSON
      //   column, and `DEFAULT NULL` is accepted outright. A gate that keys on
      //   "has a default" rather than "has a literal default" makes a supported
      //   feature inexpressible.
      $expressed = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('doc', Types::Json)->limit(1);
         $Change->nullable = false;
         $Change->default = new Expression('(JSON_OBJECT())');
      });
      $nulled = $compile(static function (Blueprint $Table): void {
         $Change = $Table->change('bio', Types::Text)->limit(1);
         $Change->nullable = true;
         $Change->default = null;
      });

      yield assert(
         assertion: $expressed === 'ALTER TABLE `t` MODIFY COLUMN `doc` JSON NOT NULL DEFAULT (JSON_OBJECT())'
            && $nulled === 'ALTER TABLE `t` MODIFY COLUMN `bio` LONGTEXT DEFAULT NULL',
         description: 'The default forms MySQL accepts on TEXT and JSON still compile'
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
