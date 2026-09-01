<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\Timestamptz;


use function assert;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;


/**
 * `Types::Timestamptz` — an instant with time zone, distinct from the
 * wall-clock `Types::Timestamp`.
 *
 * Before it existed, a PostgreSQL project needed one raw
 *   `ALTER TABLE t ALTER COLUMN c TYPE timestamptz` per timestamp column, in
 *   every migration, because `Types::Timestamp` compiles to a zoneless
 *   `TIMESTAMP` on that dialect. The case compiles to `TIMESTAMPTZ` there, to
 *   MySQL's UTC-normalized `TIMESTAMP` and to `TEXT` on SQLite — the same
 *   storage `Types::Timestamp` already gets on those two.
 */

return new Test(
   description: 'Database: SQL schema builder compiles Types::Timestamptz per dialect',
   test: function () {
      $PostgreSQL = (new SQL)->structure();
      $MySQL = (new SQL(['driver' => 'mysql']))->structure();
      $SQLite = (new SQL(['driver' => 'sqlite']))->structure();

      $shape = static function (Blueprint $Table): void {
         $Table->add('at', Types::Timestamp);
         $Table->add('seen_at', Types::Timestamptz);
      };

      // @ PostgreSQL — the dialect that tells the two apart.
      yield assert(
         assertion: $PostgreSQL->create('events', $shape)->SQL
            === 'CREATE TABLE "events" ("at" TIMESTAMP NOT NULL, "seen_at" TIMESTAMPTZ NOT NULL)',
         description: 'PostgreSQL compiles Timestamp as TIMESTAMP and Timestamptz as TIMESTAMPTZ'
      );

      $Query = $PostgreSQL->alter('events', static function (Blueprint $Table): void {
         $Table->change('at', Types::Timestamptz);
      });

      yield assert(
         assertion: $Query->SQL === 'ALTER TABLE "events" ALTER COLUMN "at" TYPE TIMESTAMPTZ',
         description: 'PostgreSQL compiles the column change that raw SQL used to spell'
      );

      // @ MySQL — TIMESTAMP is already the UTC-normalized type.
      yield assert(
         assertion: $MySQL->create('events', $shape)->SQL
            === 'CREATE TABLE `events` (`at` TIMESTAMP NOT NULL, `seen_at` TIMESTAMP NOT NULL)',
         description: 'MySQL compiles both Timestamp and Timestamptz as TIMESTAMP'
      );

      // @ SQLite — text storage, as every temporal type there.
      yield assert(
         assertion: $SQLite->create('events', $shape)->SQL
            === 'CREATE TABLE "events" ("at" TEXT NOT NULL, "seen_at" TEXT NOT NULL)',
         description: 'SQLite compiles both Timestamp and Timestamptz as TEXT'
      );
   }
);
