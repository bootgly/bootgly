<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\IdentityResync;


use function assert;
use function json_encode;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;


return new Test(
   description: 'Database: SQL schema dialects compile the identity resync of an INSERT with explicit keys',
   test: function () {
      $PostgreSQL = (new SQL)->structure()->Dialect;
      $MySQL = (new SQL(['driver' => 'mysql']))->structure()->Dialect;
      $SQLite = (new SQL(['driver' => 'sqlite']))->structure()->Dialect;

      // # The statement — one (column, key) row per integer column, forward-only

      $Query = $PostgreSQL->resync('"options"', [
         '"id"' => [1, 2, 3, 4, 5, 6],
         '"poll_id"' => [1, 1, 1, 2, 2, 2],
         '"label"' => ['VS Code', 'PhpStorm', 'Vim', 'Tabs', 'Spaces', 'Both'],
         '"position"' => [1, 2, 3, 1, 2, 3],
      ]);

      yield assert(
         assertion: $Query?->SQL === 'SELECT setval("identity"."sequence", "keys"."key", true) '
            . 'FROM (VALUES ($2, $3::bigint), ($4, $5::bigint), ($6, $7::bigint)) AS "keys" ("column", "key") '
            . 'CROSS JOIN LATERAL (SELECT pg_get_serial_sequence($1, "keys"."column")::regclass AS "sequence") AS "identity" '
            . 'WHERE CASE WHEN pg_sequence_last_value("identity"."sequence") >= "keys"."key" THEN false '
            . 'ELSE nextval("identity"."sequence") <= "keys"."key" END',
         description: 'PostgreSQL compiles one forward-only statement for the whole INSERT, found: '
            . json_encode($Query?->SQL)
      );

      yield assert(
         assertion: $Query?->parameters === ['"options"', 'id', 6, 'poll_id', 2, 'position', 3]
            && $Query->reading === false,
         description: 'The quoted table leads, then each integer column with its highest key; the statement is a write, found: '
            . json_encode([$Query?->parameters, $Query?->reading])
      );

      // # Keys — the highest per column, integer strings, and everything that is not an integer

      $Query = $PostgreSQL->resync('"polls"', [
         '"id"' => [5, '7', 2, true, 2.0, null, new Expression('DEFAULT'), '007', 'x'],
      ]);

      yield assert(
         assertion: $Query?->parameters === ['"polls"', 'id', 7],
         description: 'The highest integer wins; integer strings count; bool, float, null, Expression and non-integer strings do not, found: '
            . json_encode($Query?->parameters)
      );

      $Query = $PostgreSQL->resync('"polls"', [
         '"id"' => [true, 2.0, null, new Expression('DEFAULT')],
         '"question"' => ['A?', 'B?'],
      ]);

      yield assert(
         assertion: $Query === null,
         description: 'No integer key written means no statement, found: ' . json_encode($Query?->SQL)
      );

      // # Identifiers — unquoting, schema-qualified tables, raw SQL skipped

      $Query = $PostgreSQL->resync('"audit"."Polls"', [
         '"we""ird"' => [3],
         'lower_id' => [9],
         '"a"."b"' => [9],
      ]);

      yield assert(
         assertion: $Query?->parameters === ['"audit"."Polls"', 'we"ird', 3],
         description: 'The table passes quoted as-is; a column is unquoted; raw SQL and dotted keys are skipped, found: '
            . json_encode($Query?->parameters)
      );

      $Query = $PostgreSQL->resync('"reports"', ['"id"' => [4], '"2024"' => [5]]);

      yield assert(
         assertion: $Query?->parameters === ['"reports"', 'id', 4, '2024', 5],
         description: 'An all-digit column name is bound as text, not as an integer, found: '
            . json_encode($Query?->parameters)
      );

      $Query = $PostgreSQL->resync('lower("polls")', ['"id"' => [1]]);

      yield assert(
         assertion: $Query === null,
         description: 'A raw SQL (Expression) table compiles no statement, found: ' . json_encode($Query?->SQL)
      );

      // # Engines whose generated keys already move past explicit values

      yield assert(
         assertion: $MySQL->resync('`polls`', ['`id`' => [1, 2]]) === null
            && $SQLite->resync('"polls"', ['"id"' => [1, 2]]) === null,
         description: 'MySQL and SQLite compile no resync'
      );
   }
);
