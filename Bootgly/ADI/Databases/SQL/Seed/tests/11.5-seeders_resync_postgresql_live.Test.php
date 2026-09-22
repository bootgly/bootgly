<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\ResyncPostgreSQL;


use const BOOTGLY_STORAGE_DIR;
use function assert;
use function fclose;
use function file_put_contents;
use function fsockopen;
use function getenv;
use function glob;
use function is_dir;
use function is_int;
use function is_resource;
use function is_string;
use function json_encode;
use function mkdir;
use function rmdir;
use function str_contains;
use function strtr;
use function uniqid;
use function unlink;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;
use Bootgly\ADI\Databases\SQL\Seed\Runner;


// ! Opt-in live E2E — BOOTGLY_PGSQL_E2E=1 + DB_* environment (a role that can CREATE ROLE)
$optin = getenv('BOOTGLY_PGSQL_E2E') === '1';
$host = getenv('DB_HOST') !== false ? (string) getenv('DB_HOST') : '127.0.0.1';
$port = getenv('DB_PORT') !== false ? (int) getenv('DB_PORT') : 5432;
$reachable = false;

if ($optin) {
   $Probe = @fsockopen($host, $port, $errno, $error, 0.5);
   $reachable = is_resource($Probe);
   if ($reachable) {
      fclose($Probe);
   }
}


return new Test(
   description: 'PostgreSQL(live): seeded explicit keys move identity sequences forward (requires BOOTGLY_PGSQL_E2E=1)',
   skip: $optin === false || $reachable === false,
   test: function () use ($host, $port) {
      $config = [
         'driver' => 'pgsql',
         'host' => $host,
         'port' => $port,
         'database' => getenv('DB_NAME') !== false ? (string) getenv('DB_NAME') : 'postgres',
         'username' => getenv('DB_USER') !== false ? (string) getenv('DB_USER') : 'postgres',
         'password' => getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '',
         'timeout' => 5.0,
         'secure' => [
            'mode' => getenv('DB_SSLMODE') !== false ? (string) getenv('DB_SSLMODE') : 'disable',
         ],
         // ! One connection: the seeder advisory lock and its release share a session
         'pool' => ['min' => 0, 'max' => 1],
      ];
      $Database = new SQL($config);
      $Schema = $Database->structure();

      // ! Unique names — the server may be shared with other runs
      $suffix = uniqid();
      $space = "i15_{$suffix}";
      $role = "i15_{$suffix}";
      $tables = [
         'fresh' => "i15_{$suffix}_fresh",
         'interleaved' => "i15_{$suffix}_interleaved",
         'ahead' => "i15_{$suffix}_ahead",
         'restarted' => "i15_{$suffix}_restarted",
         'sentinel' => "i15_{$suffix}_sentinel",
         'maximum' => "i15_{$suffix}_maximum",
         'upper' => "I15_{$suffix}_Polls",
         'qualified' => "{$space}.polls",
         'options' => "i15_{$suffix}_options",
         'serial' => "i15_{$suffix}_serial",
         'frontier' => "i15_{$suffix}_frontier",
         'numeric' => "i15_{$suffix}_numeric",
         'privileged' => "i15_{$suffix}_privileged",
      ];
      $path = BOOTGLY_STORAGE_DIR . "tests/seeders-resync-postgresql-{$suffix}";

      $execute = static function (string|object $query) use ($Database): mixed {
         $Operation = $Database->query($query);
         $Database->await($Operation);

         return $Operation->Result?->cell;
      };
      // @ One generated row (no explicit id) — the application's first insert
      $generate = static function (
         string $table,
         string $columns = '"question"',
         string $values = "'app'"
      ) use ($Database): int|string {
         try {
            $Operation = $Database->query("INSERT INTO {$table} ({$columns}) VALUES ({$values}) RETURNING \"id\"");
            $Database->await($Operation);

            return (int) $Operation->Result?->cell;
         }
         catch (Throwable $Throwable) {
            return $Throwable->getMessage();
         }
      };
      $sequence = static function (string $table) use ($Database): array {
         $Operation = $Database->query("SELECT pg_get_serial_sequence('{$table}', 'id') AS \"name\"");
         $Database->await($Operation);
         $name = (string) $Operation->Result?->cell;

         $Operation = $Database->query("SELECT last_value, is_called FROM {$name}");
         $Database->await($Operation);

         return $Operation->Result->rows[0] ?? [];
      };
      $write = static function (string $seeder, string $body) use ($path): void {
         file_put_contents("{$path}/{$seeder}.php", <<<PHP
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL \$Database, Seed \$Seed) => {$body}
);
PHP);
      };
      $seed = static function (string $seeder, null|SQL $As = null) use ($Database, $path): null|string {
         try {
            (new Runner($As ?? $Database, $path, "{$path}.lock"))->run($seeder);
         }
         catch (Throwable $Throwable) {
            return $Throwable->getMessage();
         }

         return null;
      };
      // ! The idiomatic rerunnable seeder: fixed ids + upsert on the id
      $polls = static function (string $table, string $ids, string $questions): string {
         return strtr(<<<'PHP'
$Database->table(new Identifier('{table}'))
      ->insert()
      ->set(new Identifier('id'), {ids})
      ->set(new Identifier('question'), {questions})
      ->upsert(new Identifier('id'))
PHP, ['{table}' => $table, '{ids}' => $ids, '{questions}' => $questions]);
      };
      $create = static function (string $table, Types $Type = Types::BigInteger) use ($Schema, $execute): void {
         $execute($Schema->create($table, function (Blueprint $Table) use ($Type): void {
            $Table->add('id', $Type)->generate()->constrain(Keys::Primary);
            $Table->add('question', Types::Text);
         }));
      };

      try {
         is_dir($path) || mkdir($path, 0o775, true);
         $execute("CREATE SCHEMA \"{$space}\"");

         // # Fresh table — seeds 1,2 then the application's first insert

         $create($tables['fresh']);
         $write('fresh', $polls($tables['fresh'], '1, 2', "'A?', 'B?'"));
         $failure = $seed('fresh');
         $next = $generate("\"{$tables['fresh']}\"");

         yield assert(
            assertion: $failure === null && $next === 3,
            description: 'The first generated id after seeding ids 1,2 is 3, found: '
               . json_encode([$failure, $next])
         );

         // # A generated insert later in the same seeder

         $create($tables['interleaved']);
         $write('interleaved', '[' . $polls($tables['interleaved'], '1, 2', "'A?', 'B?'") . ",
      \$Database->table(new Identifier('{$tables['interleaved']}'))
         ->insert()
         ->set(new Identifier('question'), 'generated'),
   ]");
         $failure = $seed('interleaved');
         $next = $generate("\"{$tables['interleaved']}\"");

         yield assert(
            assertion: $failure === null && $next === 4,
            description: 'A generated insert after the explicit keys in the same seeder does not collide, found: '
               . json_encode([$failure, $next])
         );

         // # Sequence already ahead — a re-run leaves it untouched

         $create($tables['ahead']);
         for ($row = 1; $row <= 10; $row++) {
            $generate("\"{$tables['ahead']}\"");
         }
         $before = $sequence("\"{$tables['ahead']}\"");
         $write('ahead', $polls($tables['ahead'], '1, 2', "'A?', 'B?'"));
         $failure = $seed('ahead');
         $after = $sequence("\"{$tables['ahead']}\"");
         $next = $generate("\"{$tables['ahead']}\"");

         yield assert(
            assertion: $failure === null && $before === $after && $next === 11,
            description: 'A sequence already past the seeds is not touched and the next id is 11, found: '
               . json_encode([$failure, $before, $after, $next])
         );

         // # Re-run at the frontier — the sequence sits exactly at the key (every boot after the first)

         $create($tables['frontier']);
         $write('frontier', $polls($tables['frontier'], '1, 2', "'A?', 'B?'"));
         $first = $seed('frontier');
         $before = $sequence("\"{$tables['frontier']}\"");
         $second = $seed('frontier');
         $after = $sequence("\"{$tables['frontier']}\"");
         $next = $generate("\"{$tables['frontier']}\"");

         yield assert(
            assertion: $first === null && $second === null && $before === $after && $next === 3,
            description: 'Seeding again at the frontier spends no id: the sequence is untouched and the next id is 3, found: '
               . json_encode([$first, $second, $before, $after, $next])
         );

         // # RESTART WITH 100 — never lowered back to the seeds

         $create($tables['restarted']);
         $execute("ALTER TABLE \"{$tables['restarted']}\" ALTER COLUMN \"id\" RESTART WITH 100");
         $write('restarted', $polls($tables['restarted'], '1, 2', "'A?', 'B?'"));
         $failure = $seed('restarted');
         $next = $generate("\"{$tables['restarted']}\"");

         yield assert(
            assertion: $failure === null && is_int($next) && $next >= 100,
            description: 'A sequence restarted at 100 is never lowered to the seeds, found: '
               . json_encode([$failure, $next])
         );

         // # Keys at the edges — sentinels below MINVALUE and the int4 maximum

         $create($tables['sentinel']);
         $write('sentinel', $polls($tables['sentinel'], '0, -1', "'zero', 'minus'"));
         $sentinel = $seed('sentinel');
         $create($tables['maximum'], Types::Integer);
         $write('maximum', $polls($tables['maximum'], '2147483647', "'max'"));
         $maximum = $seed('maximum');
         // ! The contract at the edges: a sentinel below the start spends one id once; a key at
         //   the type maximum leaves nothing for generated ids
         $afterSentinels = $generate("\"{$tables['sentinel']}\"");
         $afterMaximum = $generate("\"{$tables['maximum']}\"");

         yield assert(
            assertion: $sentinel === null && $maximum === null
               && $afterSentinels === 2
               && is_string($afterMaximum) && str_contains($afterMaximum, 'reached maximum value'),
            description: 'Keys 0/-1 and the int4 maximum seed; the next ids are 2 and none, found: '
               . json_encode([$sentinel, $maximum, $afterSentinels, $afterMaximum])
         );

         // # Identifier shapes — mixed case and schema-qualified tables

         $create($tables['upper']);
         $write('upper', $polls($tables['upper'], '1, 2', "'A?', 'B?'"));
         $upper = [$seed('upper'), $generate("\"{$tables['upper']}\"")];
         $create($tables['qualified']);
         $write('qualified', $polls($tables['qualified'], '1, 2', "'A?', 'B?'"));
         $qualified = [$seed('qualified'), $generate("\"{$space}\".\"polls\"")];

         yield assert(
            assertion: $upper === [null, 3] && $qualified === [null, 3],
            description: 'Mixed-case and schema-qualified tables resync, found: '
               . json_encode([$upper, $qualified])
         );

         // # Integer columns without a sequence stay inert

         $execute($Schema->create($tables['options'], function (Blueprint $Table): void {
            $Table->add('id', Types::BigInteger)->generate()->constrain(Keys::Primary);
            $Table->add('poll_id', Types::BigInteger);
            $Table->add('position', Types::Integer);
         }));
         $write('options', strtr(<<<'PHP'
$Database->table(new Identifier('{table}'))
      ->insert()
      ->set(new Identifier('id'), 1, 2, 3, 4, 5, 6)
      ->set(new Identifier('poll_id'), 1, 1, 1, 2, 2, 2)
      ->set(new Identifier('position'), 1, 2, 3, 1, 2, 3)
      ->upsert(new Identifier('id'))
PHP, ['{table}' => $tables['options']]));
         $failure = $seed('options');
         $next = $generate("\"{$tables['options']}\"", '"poll_id", "position"', '1, 4');

         yield assert(
            assertion: $failure === null && $next === 7,
            description: 'Only the identity column resyncs; integer columns without a sequence are inert, found: '
               . json_encode([$failure, $next])
         );

         // # An all-digit column name next to the identity

         $execute("CREATE TABLE \"{$tables['numeric']}\" (\"id\" BIGINT GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY, \"2024\" INTEGER, \"question\" TEXT)");
         $write('numeric', strtr(<<<'PHP'
$Database->table(new Identifier('{table}'))
      ->insert()
      ->set(new Identifier('id'), 1)
      ->set(new Identifier('2024'), 5)
      ->upsert(new Identifier('id'))
PHP, ['{table}' => $tables['numeric']]));
         $failure = $seed('numeric');
         $next = $generate("\"{$tables['numeric']}\"");

         yield assert(
            assertion: $failure === null && $next === 2,
            description: 'An all-digit column name does not break the resync, found: '
               . json_encode([$failure, $next])
         );

         // # A serial column (not identity)

         $execute("CREATE TABLE \"{$tables['serial']}\" (\"id\" SERIAL PRIMARY KEY, \"question\" TEXT)");
         $write('serial', $polls($tables['serial'], '1, 2', "'A?', 'B?'"));
         $failure = $seed('serial');
         $next = $generate("\"{$tables['serial']}\"");

         yield assert(
            assertion: $failure === null && $next === 3,
            description: 'A serial column resyncs like an identity column, found: '
               . json_encode([$failure, $next])
         );

         // # A seeding role without privileges on the sequence fails loudly and atomically

         $create($tables['privileged']);
         $execute("CREATE ROLE \"{$role}\" LOGIN PASSWORD 'i15'");
         $execute("GRANT USAGE ON SCHEMA public TO \"{$role}\"");
         $execute("GRANT SELECT, INSERT, UPDATE ON \"{$tables['privileged']}\" TO \"{$role}\"");
         $Restricted = new SQL(['username' => $role, 'password' => 'i15'] + $config);
         $write('privileged', $polls($tables['privileged'], '1, 2', "'A?', 'B?'"));
         $failure = $seed('privileged', $Restricted);
         $rows = $execute("SELECT count(*) FROM \"{$tables['privileged']}\"");

         yield assert(
            assertion: $failure !== null
               && str_contains($failure, 'permission denied for sequence')
               && (int) $rows === 0,
            description: 'A role without sequence privileges fails the seed run and rolls the seeder back, found: '
               . json_encode([$failure, $rows])
         );
      }
      finally {
         foreach ($tables as $table) {
            try { $execute($Schema->drop($table)); }
            catch (Throwable) {}
         }
         try { $execute("DROP SCHEMA IF EXISTS \"{$space}\" CASCADE"); }
         catch (Throwable) {}
         try { $execute("DROP OWNED BY \"{$role}\""); }
         catch (Throwable) {}
         try { $execute("DROP ROLE IF EXISTS \"{$role}\""); }
         catch (Throwable) {}

         foreach (glob("{$path}/*.php") ?: [] as $file) {
            unlink($file);
         }
         if (is_dir($path)) {
            rmdir($path);
         }
         foreach (glob("{$path}.lock*") ?: [] as $file) {
            unlink($file);
         }
      }
   }
);
