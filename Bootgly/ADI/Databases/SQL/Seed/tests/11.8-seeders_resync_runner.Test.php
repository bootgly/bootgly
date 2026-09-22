<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\ResyncRunner;


use const BOOTGLY_STORAGE_DIR;
use function array_column;
use function array_filter;
use function array_keys;
use function array_values;
use function assert;
use function count;
use function file_put_contents;
use function glob;
use function is_dir;
use function json_encode;
use function mkdir;
use function rmdir;
use function str_contains;
use function str_starts_with;
use function uniqid;
use function unlink;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Seed\Runner;


class RecordingPool extends Pool
{
   // * Config
   public RecordingSQL $Database;


   /**
    * @param class-string<\Bootgly\ADI\Database\Drivers> $drivers
    */
   public function __construct (Config $Config, Connection $Connection, string $drivers, RecordingSQL $Database)
   {
      parent::__construct($Config, $Connection, $drivers);

      // * Config
      $this->Database = $Database;
   }

   public function assign (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation instanceof SQLOperation) {
         $this->Database->queries[] = [
            'sql'        => $Operation->SQL,
            'parameters' => $Operation->parameters,
         ];
      }

      $Operation->Connection ??= $this->Connection;

      return $Operation->resolve(new Result('OK'));
   }

   public function wait (DatabaseOperation $Operation): DatabaseOperation
   {
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }

      return $Operation;
   }
}

class RecordingSQL extends SQL
{
   /**
    * @var array<int,array{sql:string,parameters:array<int|string,mixed>}>
    */
   public array $queries = [];
   public RecordingPool $Recorder;


   public function __construct (string $driver)
   {
      parent::__construct(['driver' => $driver, 'pool' => ['min' => 0, 'max' => 0]]);

      $this->Recorder = new RecordingPool($this->Config, $this->Connection, $this->drivers, $this);
      $this->Pool = $this->Recorder;
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = [], null|object $Scope = null): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->SQL, $Normalized->parameters, $this->Config->timeout);

      // ? Advisory lock queries resolve granted and stay out of the recording
      if (str_contains($Operation->SQL, 'pg_try_advisory_lock') || str_contains($Operation->SQL, '_LOCK(')) {
         $Operation->resolve(new Result('OK', [['locked' => true]]));

         return $Operation;
      }

      $this->queries[] = [
         'sql'        => $Operation->SQL,
         'parameters' => $Operation->parameters,
      ];
      $Operation->resolve(new Result('OK'));

      return $Operation;
   }
}


return new Test(
   description: 'Database: SQL seed runner follows each INSERT with its identity resync',
   test: function () {
      $path = BOOTGLY_STORAGE_DIR . 'tests/seeders-resync-runner-' . uniqid();
      // ! The Cookbook polls seeder, without any hand-written setval()
      $polls = <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => [
      $Database->table(new Identifier('polls'))
         ->insert()
         ->set(new Identifier('id'), 1, 2)
         ->set(new Identifier('question'), 'Which editor?', 'Tabs or spaces?')
         ->upsert(new Identifier('id')),
      $Database->table(new Identifier('options'))
         ->insert()
         ->set(new Identifier('id'), 1, 2, 3, 4, 5, 6)
         ->set(new Identifier('poll_id'), 1, 1, 1, 2, 2, 2)
         ->set(new Identifier('label'), 'VS Code', 'PhpStorm', 'Vim', 'Tabs', 'Spaces', 'Both')
         ->set(new Identifier('position'), 1, 2, 3, 1, 2, 3)
         ->upsert(new Identifier('id')),
   ]
);
PHP;
      // ! Everything the resync must leave alone, in a known order
      $untouched = <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Auxiliaries\Operators;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => [
      null,
      'INSERT INTO "polls" ("id", "question") VALUES (7, \'raw\')',
      new Query('SELECT 2'),
      [
         $Database->table(new Identifier('polls'))
            ->update()
            ->set(new Identifier('id'), 9)
            ->filter(new Identifier('id'), Operators::Equal, 7),
         [$Database->table(new Identifier('polls'))->select(new Identifier('id'))],
      ],
      $Database->table(new Identifier('polls'))
         ->insert()
         ->set(new Identifier('question'), 'generated'),
   ]
);
PHP;

      try {
         is_dir($path) || mkdir($path, 0o775, true);
         file_put_contents("{$path}/polls.php", $polls);
         file_put_contents("{$path}/untouched.php", $untouched);

         // # Preview — the dry run lists exactly what runs

         $Database = new SQL(['driver' => 'pgsql', 'pool' => ['min' => 0, 'max' => 0]]);
         $preview = (new Runner($Database, $path, "{$path}.lock"))->preview('polls')['polls'] ?? [];
         $SQL = array_column($preview, 'sql');

         yield assert(
            assertion: count($preview) === 4
               && str_starts_with($SQL[0] ?? '', 'INSERT INTO "polls"')
               && str_starts_with($SQL[1] ?? '', 'SELECT setval(')
               && str_starts_with($SQL[2] ?? '', 'INSERT INTO "options"')
               && str_starts_with($SQL[3] ?? '', 'SELECT setval('),
            description: 'The PostgreSQL preview follows each INSERT with its resync, found: ' . json_encode($SQL)
         );

         yield assert(
            assertion: ($preview[1]['parameters'] ?? null) === ['"polls"', 'id', 2]
               && ($preview[3]['parameters'] ?? null) === ['"options"', 'id', 6, 'poll_id', 2, 'position', 3],
            description: 'Each resync carries its table and the highest key of every integer column, found: '
               . json_encode([$preview[1]['parameters'] ?? null, $preview[3]['parameters'] ?? null])
         );

         // # Run — inside the seeder's transaction, right after its own INSERT

         $Recording = new RecordingSQL('pgsql');
         (new Runner($Recording, $path, "{$path}.lock"))->run('polls');
         $SQL = array_column($Recording->queries, 'sql');
         $kinds = [];
         foreach ($SQL as $statement) {
            $kinds[] = match (true) {
               str_starts_with($statement, 'INSERT INTO "polls"') => 'polls',
               str_starts_with($statement, 'INSERT INTO "options"') => 'options',
               str_starts_with($statement, 'SELECT setval(') => 'resync',
               str_contains($statement, 'pg_advisory_unlock') => 'unlock',
               default => $statement,
            };
         }

         yield assert(
            assertion: $kinds === ['BEGIN', 'polls', 'resync', 'options', 'resync', 'COMMIT', 'unlock'],
            description: 'The run interleaves each resync after its INSERT inside the transaction, found: '
               . json_encode($kinds)
         );

         // # MySQL — AUTO_INCREMENT moves by itself, nothing is added

         $Recording = new RecordingSQL('mysql');
         (new Runner($Recording, $path, "{$path}.lock"))->run('polls');
         $SQL = array_column($Recording->queries, 'sql');

         yield assert(
            assertion: count($SQL) === 2
               && array_filter($SQL, static fn (string $statement): bool => str_contains($statement, 'setval')) === [],
            description: 'The MySQL run holds the two INSERTs alone, found: ' . json_encode($SQL)
         );

         // # Everything else passes through untouched and in order

         $preview = (new Runner($Database, $path, "{$path}.lock"))->preview('untouched')['untouched'] ?? [];
         $SQL = array_column($preview, 'sql');

         yield assert(
            assertion: count($SQL) === 5
               && str_starts_with($SQL[0], 'INSERT INTO "polls" ("id", "question") VALUES (7')
               && $SQL[1] === 'SELECT 2'
               && str_starts_with($SQL[2], 'UPDATE "polls"')
               && str_starts_with($SQL[3], 'SELECT "id"')
               && str_starts_with($SQL[4], 'INSERT INTO "polls" ("question")')
               && array_values(array_keys($preview)) === [0, 1, 2, 3, 4],
            description: 'Raw SQL, Query, UPDATE, SELECT and key-less INSERTs get no resync, found: '
               . json_encode($SQL)
         );

         // # Invalid returns still fail as before

         file_put_contents("{$path}/invalid.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(Run: fn (SQL $Database, Seed $Seed) => [1]);
PHP);
         $invalid = null;
         try {
            (new Runner($Database, $path, "{$path}.lock"))->preview('invalid');
         }
         catch (InvalidArgumentException $Exception) {
            $invalid = $Exception->getMessage();
         }

         yield assert(
            assertion: $invalid === 'Seeder must return null, string, Builder, Query, or an array of those.',
            description: 'An invalid seeder return still raises the same error, found: ' . json_encode($invalid)
         );

         file_put_contents("{$path}/tableless.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(Run: fn (SQL $Database, Seed $Seed) => (new Builder)->insert()->set(new Identifier('id'), 1));
PHP);
         $tableless = null;
         try {
            (new Runner($Database, $path, "{$path}.lock"))->preview('tableless');
         }
         catch (InvalidArgumentException $Exception) {
            $tableless = $Exception->getMessage();
         }

         yield assert(
            assertion: $tableless === 'SQL builder requires a table.',
            description: 'A table-less INSERT keeps failing with the builder\'s own error, found: ' . json_encode($tableless)
         );
      }
      finally {
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
