<?php

namespace Bootgly\ADI\Databases\SQL\Builder\Tests\Slice;


use function assert;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\MySQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\PostgreSQL;
use Bootgly\ADI\Databases\SQL\Builder\Dialects\SQLite;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;


return new Test(
   description: 'Database: SQL builder routes row slicing through the dialect',
   test: function () {
      $compile = static fn (object $Dialect, callable $slice): string => $slice(
         (new Builder($Dialect))->table(new Identifier('t'))->select(new Identifier('id'))
      )->compile()->SQL;

      $skip = static fn (Builder $Builder): Builder => $Builder->skip(2);
      $both = static fn (Builder $Builder): Builder => $Builder->limit(5, 2);
      $only = static fn (Builder $Builder): Builder => $Builder->limit(5);
      $none = static fn (Builder $Builder): Builder => $Builder;

      // # Offset without limit — the shape two of three dialects reject
      //   Only PostgreSQL's grammar takes OFFSET on its own. MySQL answers a
      //   bare one with 1064 and SQLite with `near "2": syntax error`, so each
      //   needs a row count standing in for "everything after n" — and the two
      //   sentinels are not interchangeable: MySQL rejects `LIMIT -1`, SQLite
      //   rejects the unsigned BIGINT maximum.
      yield assert(
         assertion: $compile(new PostgreSQL, $skip) === 'SELECT "id" FROM "t" OFFSET 2',
         description: 'PostgreSQL keeps the standalone OFFSET its grammar accepts'
      );

      yield assert(
         assertion: $compile(new MySQL, $skip)
            === 'SELECT `id` FROM `t` LIMIT 18446744073709551615 OFFSET 2',
         description: 'MySQL gets the unsigned maximum as its row count'
      );

      yield assert(
         assertion: $compile(new SQLite, $skip) === 'SELECT "id" FROM "t" LIMIT -1 OFFSET 2',
         description: 'SQLite gets the negative row count it reads as unlimited'
      );

      // # The forms every dialect already spelled the same way
      //   Regression guard: routing the clause must not move any of them.
      yield assert(
         assertion: $compile(new PostgreSQL, $both) === 'SELECT "id" FROM "t" LIMIT 5 OFFSET 2'
            && $compile(new MySQL, $both) === 'SELECT `id` FROM `t` LIMIT 5 OFFSET 2'
            && $compile(new SQLite, $both) === 'SELECT "id" FROM "t" LIMIT 5 OFFSET 2',
         description: 'A limit with an offset is unchanged in every dialect'
      );

      yield assert(
         assertion: $compile(new PostgreSQL, $only) === 'SELECT "id" FROM "t" LIMIT 5'
            && $compile(new MySQL, $only) === 'SELECT `id` FROM `t` LIMIT 5'
            && $compile(new SQLite, $only) === 'SELECT "id" FROM "t" LIMIT 5',
         description: 'A limit without an offset is unchanged in every dialect'
      );

      yield assert(
         assertion: $compile(new PostgreSQL, $none) === 'SELECT "id" FROM "t"'
            && $compile(new MySQL, $none) === 'SELECT `id` FROM `t`'
            && $compile(new SQLite, $none) === 'SELECT "id" FROM "t"',
         description: 'A query that slices nothing emits no clause'
      );

      // # skip(0) is not a slice
      //   `Builder::$offset` defaults to 0, so an explicit skip(0) must stay
      //   indistinguishable from never calling it — otherwise the sentinel
      //   would appear in queries that never asked to paginate.
      yield assert(
         assertion: $compile(new MySQL, static fn (Builder $Builder): Builder => $Builder->skip(0))
            === 'SELECT `id` FROM `t`',
         description: 'Skipping no rows emits no clause at all'
      );

      // # The ORM reaches the same emitter
      //   Repository/Selection::compile() routes skip() to the builder, so the
      //   dialect decides there too rather than in the compiler.
      yield assert(
         assertion: $compile(new SQLite, static fn (Builder $Builder): Builder => $Builder->skip(7))
            === 'SELECT "id" FROM "t" LIMIT -1 OFFSET 7',
         description: 'The offset value reaches the dialect verbatim'
      );
   }
);
