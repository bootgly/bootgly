<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Aggregates;


use function assert;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Aggregates;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Joins;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


return new Test(
   description: 'Database: SQL builder counts a column and resolves aggregate columns through table aliases',
   test: function () {
      $I = static fn (string $name): Identifier => new Identifier($name);

      // # COUNT(column) — the aggregate that skips NULLs
      //   `count()` is `COUNT(*)`, which counts the NULL-filled row a LEFT JOIN
      //   produces for an unmatched parent as one. Counting the joined column is
      //   the only form that reports that parent at zero.
      yield assert(
         assertion: (new Builder)
            ->table($I('votes'))
            ->aggregate(Aggregates::Count, $I('id'), $I('total'))
            ->compile()->SQL === 'SELECT COUNT("id") AS "total" FROM "votes"',
         description: 'Aggregates::Count compiles COUNT(column) with its alias'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('votes'))
            ->count($I('total'))
            ->compile()->SQL === 'SELECT COUNT(*) AS "total" FROM "votes"',
         description: 'count() still compiles COUNT(*)'
      );

      // # The per-parent report under table aliases
      //   One alias is registered before the aggregate and one after it: the
      //   aggregated column must resolve at compile time either way, exactly like
      //   the plain projections, the JOIN and the WHERE of the same statement.
      $report = static fn (Builder $Builder, bool $distinct = false): Builder => $Builder
         ->table($I('options'))
         ->alias($I('options'), $I('o'))
         ->select($I('options.id'))
         ->aggregate(Aggregates::Count, $I('votes.id'), $I('votes'), distinct: $distinct)
         ->join($I('votes'), $I('votes.option_id'), Operators::Equal, $I('options.id'), Joins::Left)
         ->alias($I('votes'), $I('v'))
         ->filter($I('options.poll_id'), Operators::Equal, 1)
         ->group($I('options.id'));

      $Query = $report(new Builder)->compile();

      yield assert(
         assertion: $Query->SQL === 'SELECT "o"."id", COUNT("v"."id") AS "votes" FROM "options" AS "o" LEFT JOIN "votes" AS "v" ON "v"."option_id" = "o"."id" WHERE "o"."poll_id" = $1 GROUP BY "o"."id"'
            && $Query->parameters === [1],
         description: 'An aggregated column follows a table alias registered after the aggregate call'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('orders'), $I('o'))
            ->select($I('orders.status'))
            ->aggregate(Aggregates::Sum, $I('orders.amount'), $I('total'))
            ->group($I('orders.status'))
            ->compile()->SQL === 'SELECT "o"."status", SUM("o"."amount") AS "total" FROM "orders" AS "o" GROUP BY "o"."status"',
         description: 'An aggregated column follows a table alias given to table()'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('votes'))
            ->select($I('votes.id'))
            ->alias($I('votes.id'), $I('vid'))
            ->aggregate(Aggregates::Count, $I('votes.id'), $I('n'))
            ->compile()->SQL === 'SELECT "votes"."id" AS "vid", COUNT("votes"."id") AS "n" FROM "votes"',
         description: 'A select-list column alias never replaces the aggregated column'
      );

      // # DISTINCT inside the aggregate
      //   A named argument of `aggregate()`, never `distinct()`: that verb means
      //   `SELECT DISTINCT` for the whole row and must keep its SQL.
      yield assert(
         assertion: $report(new Builder, distinct: true)->compile()->SQL === 'SELECT "o"."id", COUNT(DISTINCT "v"."id") AS "votes" FROM "options" AS "o" LEFT JOIN "votes" AS "v" ON "v"."option_id" = "o"."id" WHERE "o"."poll_id" = $1 GROUP BY "o"."id"',
         description: 'distinct: true compiles COUNT(DISTINCT column)'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('orders'))
            ->aggregate(Aggregates::Sum, $I('amount'), distinct: true)
            ->compile()->SQL === 'SELECT SUM(DISTINCT "amount") FROM "orders"',
         description: 'distinct: true applies to every aggregate, not only COUNT'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('votes'))
            ->distinct()
            ->aggregate(Aggregates::Count, $I('voter'))
            ->compile()->SQL === 'SELECT DISTINCT COUNT("voter") FROM "votes"',
         description: 'distinct() keeps meaning SELECT DISTINCT and does not enter the aggregate'
      );

      // # Dialect replay
      //   Compiling for another dialect replays the recorded calls, so every
      //   argument of `aggregate()` has to be part of the record.
      yield assert(
         assertion: $report(new Builder, distinct: true)->compile(new MySQL)->SQL === 'SELECT `o`.`id`, COUNT(DISTINCT `v`.`id`) AS `votes` FROM `options` AS `o` LEFT JOIN `votes` AS `v` ON `v`.`option_id` = `o`.`id` WHERE `o`.`poll_id` = ? GROUP BY `o`.`id`',
         description: 'A dialect replay keeps the alias rewrite and the DISTINCT argument'
      );

      // # Aliasing an aggregate through its text
      //   Before the parts were kept, an aggregate without its own alias could be
      //   aliased by registering its compiled text as an Expression. That path
      //   still works. An aggregate with its own alias keeps it, even when an
      //   Expression alias is registered on its full stored text.
      yield assert(
         assertion: (new Builder)
            ->table($I('users'))
            ->aggregate(Aggregates::Maximum, $I('id'))
            ->alias(new Expression('MAX("id")'), $I('total'))
            ->compile()->SQL === 'SELECT MAX("id") AS "total" FROM "users"',
         description: 'An Expression alias on an aggregate text is still honoured'
      );

      yield assert(
         assertion: (new Builder)
            ->table($I('users'))
            ->aggregate(Aggregates::Maximum, $I('id'), $I('highest'))
            ->alias(new Expression('MAX("id") AS "highest"'), $I('total'))
            ->compile()->SQL === 'SELECT MAX("id") AS "highest" FROM "users"',
         description: 'The alias given to aggregate() wins over an Expression alias'
      );

      // # A bare `*`
      //   `COUNT(*)` already has its verb, `count()`, and `*` is invalid in every
      //   other aggregate and after DISTINCT on every engine: refused, not emitted.
      $refused = 0;

      foreach ([[Aggregates::Count, false], [Aggregates::Count, true], [Aggregates::Sum, false]] as [$Aggregate, $distinct]) {
         try {
            (new Builder)->table($I('votes'))->aggregate($Aggregate, $I('*'), distinct: $distinct);
         }
         catch (InvalidArgumentException) {
            $refused++;
         }
      }

      yield assert(
         assertion: $refused === 3,
         description: 'aggregate() refuses a bare * column'
      );

      $Builder = (new Builder)->table($I('votes'));

      try {
         $Builder->aggregate(Aggregates::Sum, $I('*'));
      }
      catch (InvalidArgumentException) {
         // Refused: the assertion below proves nothing was appended.
      }

      yield assert(
         assertion: $Builder->compile()->SQL === 'SELECT * FROM "votes"',
         description: 'A refused aggregate leaves the builder untouched'
      );
   }
);
