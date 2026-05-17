<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\Runner;


use function assert;
use function file_put_contents;
use function glob;
use function is_dir;
use function rmdir;
use function uniqid;
use function unlink;
use InvalidArgumentException;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Lock;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation as SQLOperation;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Runner;


class RecordingSQL extends SQL
{
   /**
    * @var array<int,array{sql:string,parameters:array<int|string,mixed>}>
    */
   public array $queries = [];


   public function __construct ()
   {
      parent::__construct(['driver' => 'sqlite', 'pool' => ['min' => 0, 'max' => 0]]);
   }

   /**
    * @param string|Builder|SQLQuery $query
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = []): SQLOperation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new SQLOperation(null, $Normalized->sql, $Normalized->parameters, $this->Config->timeout);

      $this->queries[] = [
         'sql'        => $Operation->sql,
         'parameters' => $Operation->parameters,
      ];

      $Operation->resolve(new Result('OK'));

      return $Operation;
   }
}

function clean (string $path): void
{
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


return new Specification(
   description: 'Database: SQL seed runner executes one or all rerunnable seeders',
   test: function () {
      $path = BOOTGLY_WORKING_DIR . 'workdata/tests/seeders-runner-' . uniqid();
      $Database = new RecordingSQL;
      $Runner = new Runner($Database, $path, "{$path}.lock");

      $expectedEmail = (new Seed)->fake('Email', seed: 5);

      $Runner->create('Second');
      file_put_contents("{$path}/first.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => $Database
      ->table(new Identifier('users'))
      ->insert()
      ->set(new Identifier('email'), $Seed->fake('Email', seed: 5))
);
PHP);
      file_put_contents("{$path}/second.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Query;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => [
      'SELECT 1',
      new Query('SELECT 2')
   ]
);
PHP);

      $preview = $Runner->preview();

      yield assert(
         assertion: $preview === [
            'first' => [[
               'sql'        => 'INSERT INTO "users" ("email") VALUES (?1)',
               'parameters' => [$expectedEmail],
            ]],
            'second' => [[
               'sql'        => 'SELECT 1',
               'parameters' => [],
            ], [
               'sql'        => 'SELECT 2',
               'parameters' => [],
            ]],
         ]
            && $Database->queries === [],
         description: 'Runner previews SQL and parameters without executing seeder queries'
      );

      $ran = $Runner->run();

      yield assert(
         assertion: $ran === ['first', 'second']
            && $Database->queries[0]['sql'] === 'INSERT INTO "users" ("email") VALUES (?1)'
            && $Database->queries[0]['parameters'] === [$expectedEmail]
            && $Database->queries[1]['sql'] === 'SELECT 1'
            && $Database->queries[2]['sql'] === 'SELECT 2',
         description: 'Runner runs all seeders in filename order and executes Builder string Query arrays'
      );

      $Database->queries = [];
      $ran = $Runner->run('second');

      yield assert(
         assertion: $ran === ['second']
            && array_column($Database->queries, 'sql') === ['SELECT 1', 'SELECT 2'],
         description: 'Runner can run one named seeder'
      );

      $missing = false;
      try {
         $Runner->run('missing');
      }
      catch (RuntimeException) {
         $missing = true;
      }

      yield assert(
         assertion: $missing,
         description: 'Runner reports a missing named seeder'
      );

      $Lock = new Lock("{$path}.lock");
      $locked = $Lock->acquire();
      $blocked = false;

      try {
         $Runner->run('second');
      }
      catch (RuntimeException) {
         $blocked = true;
      }

      $Lock->release();

      yield assert(
         assertion: $locked && $blocked,
         description: 'Runner rejects execution when the local seeder lock is already active'
      );

      file_put_contents("{$path}/invalid.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => 1
);
PHP);

      $invalid = false;
      try {
         $Runner->run('invalid');
      }
      catch (InvalidArgumentException) {
         $invalid = true;
      }

      yield assert(
         assertion: $invalid,
         description: 'Runner rejects unsupported seeder return values'
      );

      clean($path);
   }
);
