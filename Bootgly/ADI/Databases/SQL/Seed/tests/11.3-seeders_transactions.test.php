<?php

namespace Bootgly\ADI\Databases\SQL\Seed\Tests\Transactions;


use function array_column;
use function assert;
use function file_put_contents;
use function glob;
use function in_array;
use function is_dir;
use function is_file;
use function rmdir;
use function str_contains;
use function uniqid;
use function unlink;
use RuntimeException;

use Bootgly\ACI\Tests\Suite\Test\Specification;
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

   // * Data
   public null|string $fail = null;


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
            'sql'        => $Operation->sql,
            'parameters' => $Operation->parameters,
         ];

         $Operation->Connection ??= $this->Connection;

         if ($this->fail !== null && str_contains($Operation->sql, $this->fail)) {
            return $Operation->fail('forced failure');
         }
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
   public bool $advisory = true;


   public function __construct ()
   {
      parent::__construct(['driver' => 'pgsql', 'pool' => ['min' => 0, 'max' => 0]]);

      $this->Recorder = new RecordingPool($this->Config, $this->Connection, $this->drivers, $this);
      $this->Pool = $this->Recorder;
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

      $rows = str_contains($Operation->sql, 'pg_try_advisory_lock')
         ? [['locked' => $this->advisory]]
         : [];
      $Operation->resolve(new Result('OK', $rows));

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
   description: 'Database: SQL seed runner wraps transactional dialect seeders atomically',
   test: function () {
      $path = BOOTGLY_WORKING_DIR . 'workdata/tests/seeders-transactions-' . uniqid();
      $Database = new RecordingSQL;
      $Runner = new Runner($Database, $path, "{$path}.lock");

      $Runner->create('Failing');
      file_put_contents("{$path}/failing.php", <<<'PHP'
<?php
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Identifier;
use Bootgly\ADI\Databases\SQL\Seed;
use Bootgly\ADI\Databases\SQL\Seed\Seeder;

return new Seeder(
   Run: fn (SQL $Database, Seed $Seed) => $Database
      ->table(new Identifier('users'))
      ->insert()
      ->set(new Identifier('email'), 'fail@example.test')
);
PHP);

      $Database->Recorder->fail = 'INSERT INTO "users"';
      $failed = false;

      try {
         $Runner->run('failing');
      }
      catch (RuntimeException) {
         $failed = true;
      }

      $sql = array_column($Database->queries, 'sql');

      yield assert(
         assertion: $failed
            && in_array('BEGIN', $sql, true)
            && in_array('ROLLBACK', $sql, true)
            && in_array('COMMIT', $sql, true) === false,
         description: 'Runner rolls back failed transactional seeders without committing'
      );

      $Database->queries = [];
      $Database->Recorder->fail = null;
      $ran = $Runner->run('failing');
      $sql = array_column($Database->queries, 'sql');

      yield assert(
         assertion: $ran === ['failing']
            && in_array('BEGIN', $sql, true)
            && in_array('COMMIT', $sql, true),
         description: 'Runner commits successful transactional seeders'
      );

      $Database->queries = [];
      $Database->advisory = false;
      $blocked = false;

      try {
         $Runner->run('failing');
      }
      catch (RuntimeException) {
         $blocked = true;
      }

      $sql = array_column($Database->queries, 'sql');

      yield assert(
         assertion: $blocked
            && str_contains($sql[0] ?? '', 'pg_try_advisory_lock')
            && in_array('BEGIN', $sql, true) === false
            && is_file("{$path}.lock") === false,
         description: 'Runner rejects held advisory locks and releases the local seeder lock'
      );

      clean($path);
   }
);
