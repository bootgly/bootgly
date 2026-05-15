<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\Sync;


use const BOOTGLY_WORKING_DIR;
use function assert;
use function file_put_contents;
use function glob;
use function hash;
use function hexdec;
use function is_dir;
use function is_scalar;
use function max;
use function mkdir;
use function rmdir;
use function str_starts_with;
use function substr;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Fixture;
use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder;
use Bootgly\ADI\Databases\SQL\Builder\Query as SQLQuery;
use Bootgly\ADI\Databases\SQL\Normalized;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\ADI\Databases\SQL\Schema\Repository;
use Bootgly\ADI\Databases\SQL\Schema\Runner;


class SyncRepository extends Repository
{
   public const string CREATE = 'schema.repository.create';
   public const string DELETE = 'schema.repository.delete';
   public const string FETCH = 'schema.repository.fetch';
   public const string INSERT = 'schema.repository.insert';
   public const string PEEK = 'schema.repository.peek';


   public function create (): SQLQuery
   {
      return new SQLQuery(self::CREATE);
   }

   public function fetch (): SQLQuery
   {
      return new SQLQuery(self::FETCH);
   }

   public function peek (): SQLQuery
   {
      return new SQLQuery(self::PEEK);
   }

   public function insert (string $migration, int $batch): SQLQuery
   {
      return new SQLQuery(self::INSERT, [$migration, $batch]);
   }

   public function delete (string $migration): SQLQuery
   {
      return new SQLQuery(self::DELETE, [$migration]);
   }
}


class SyncDatabase extends SQL
{
   public int $advisoryLocks = 0;
   public int $advisoryUnlocks = 0;
   /** @var array<int,int> */
   public array $advisoryKeys = [];
   public int $creates = 0;

   /** @var array<int,array<string,mixed>> */
   public array $history = [
      [
         'migration'  => '20260514000000_create_users',
         'batch'      => 1,
         'created_at' => 'now',
      ],
      [
         'migration'  => '20260513000000_removed_table',
         'batch'      => 2,
         'created_at' => 'now',
      ],
   ];


   /**
    * @param array<int|string,mixed> $parameters
    */
   public function query (string|Builder|SQLQuery $query, array $parameters = []): Operation
   {
      $Normalized = new Normalized($query, $parameters);
      $Operation = new Operation(null, $Normalized->sql, $Normalized->parameters);
      $rows = [];
      $affected = 0;

      if ($Operation->sql === SyncRepository::CREATE) {
         $this->creates++;
      }
      else if (str_starts_with($Operation->sql, 'SELECT pg_try_advisory_lock')) {
         $this->advisoryLocks++;
         $this->advisoryKeys[] = (int) ($Operation->parameters[0] ?? 0);
         $rows = [['locked' => true]];
      }
      else if (str_starts_with($Operation->sql, 'SELECT pg_advisory_unlock')) {
         $this->advisoryUnlocks++;
         $this->advisoryKeys[] = (int) ($Operation->parameters[0] ?? 0);
         $rows = [['unlocked' => true]];
      }
      else if ($Operation->sql === SyncRepository::FETCH) {
         $rows = $this->history;
      }
      else if ($Operation->sql === SyncRepository::PEEK) {
         $batch = 0;
         foreach ($this->history as $row) {
            $value = $row['batch'] ?? 0;
            $batch = is_scalar($value) ? max($batch, (int) $value) : $batch;
         }

         $rows = [['batch' => $batch]];
      }
      else if ($Operation->sql === SyncRepository::INSERT) {
         $this->history[] = [
            'migration'  => (string) ($Operation->parameters[0] ?? ''),
            'batch'      => (int) ($Operation->parameters[1] ?? 0),
            'created_at' => 'now',
         ];
         $affected = 1;
      }
      else if ($Operation->sql === SyncRepository::DELETE) {
         $migration = (string) ($Operation->parameters[0] ?? '');
         $history = [];

         foreach ($this->history as $row) {
            if (($row['migration'] ?? null) === $migration) {
               $affected = 1;
               continue;
            }

            $history[] = $row;
         }

         $this->history = $history;
      }

      $Operation->resolve(new Result(rows: $rows, affected: $affected));

      return $Operation;
   }
}


class SyncFixture extends Fixture
{
   public string $path;
   public SyncDatabase $Database;
   public Runner $Runner;


   public function __construct ()
   {
      parent::__construct();

      $this->path = BOOTGLY_WORKING_DIR . 'workdata/tests/schema-sync-' . uniqid() . '/';
      $this->Database = new SyncDatabase(['migrations' => 'schema_history', 'pool' => ['min' => 0, 'max' => 0]]);
      // The fixture resolves operations synchronously and does not emulate pooled transactions.
      $this->Database->structure()->Dialect->transactions = false;
      $this->Runner = new Runner(
         $this->Database,
         $this->path,
         $this->path . 'sync.lock',
         Repository: new SyncRepository($this->Database->Dialect, $this->Database->SQLConfig->migrations)
      );
   }

   protected function setup (): void
   {
      if (is_dir($this->path) === false) {
         mkdir($this->path, 0775, true);
      }

      file_put_contents($this->path . '20260514000000_create_users.php', <<<'PHP'
<?php

use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;

return new Migration(
   Up: fn (Migrating $Schema) => null,
   Down: fn (Migrating $Schema) => null
);

PHP);
      file_put_contents($this->path . '20260514000100_create_posts.php', <<<'PHP'
<?php

use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;

return new Migration(
   Up: fn (Migrating $Schema) => null,
   Down: fn (Migrating $Schema) => null
);

PHP);
      file_put_contents($this->path . '20260514000200_raw_sql.php', <<<'PHP'
<?php

use Bootgly\ADI\Databases\SQL\Schema\Migrating;
use Bootgly\ADI\Databases\SQL\Schema\Migration;

return new Migration(
   Up: fn (Migrating $Schema) => null,
   Down: fn (Migrating $Schema) => null
);

PHP);
   }

   protected function teardown (): void
   {
      foreach (glob($this->path . '*') ?: [] as $file) {
         unlink($file);
      }

      if (is_dir($this->path)) {
         rmdir($this->path);
      }

      parent::teardown();
   }
}


return new Specification(
   description: 'Database: SQL schema sync reconciles migration files with migration history',
   Fixture: new SyncFixture,
   test: function (SyncFixture $Fixture) {
      $Database = $Fixture->Database;
      $Runner = $Fixture->Runner;
      $hash = hash('sha256', $Fixture->path);
      $high = (int) hexdec(substr($hash, 0, 8));
      $low = (int) hexdec(substr($hash, 8, 8));
      $advisory = ($high << 32) | $low;
      $Status = $Runner->report();

      yield assert(
         assertion: $Status['pending'] === ['20260514000100_create_posts', '20260514000200_raw_sql']
            && $Status['missing'] === ['20260513000000_removed_table']
            && $Runner->Repository->table === 'schema_history',
         description: 'Sync report detects local-only database-only migrations and uses configured repository table'
      );

      yield assert(
         assertion: $Database->history === [[
            'migration'  => '20260514000000_create_users',
            'batch'      => 1,
            'created_at' => 'now',
         ], [
            'migration'  => '20260513000000_removed_table',
            'batch'      => 2,
            'created_at' => 'now',
         ]]
            && $Database->creates === 1,
         description: 'Sync report does not modify migration history'
      );

      $Sync = $Runner->sync();

      yield assert(
         assertion: $Sync['added'] === ['20260514000100_create_posts', '20260514000200_raw_sql']
            && $Sync['deleted'] === ['20260513000000_removed_table'],
         description: 'Sync applies migration history additions and deletions'
      );

      yield assert(
         assertion: $Database->history === [[
            'migration'  => '20260514000000_create_users',
            'batch'      => 1,
            'created_at' => 'now',
         ], [
            'migration'  => '20260514000100_create_posts',
            'batch'      => 2,
            'created_at' => 'now',
         ], [
            'migration'  => '20260514000200_raw_sql',
            'batch'      => 2,
            'created_at' => 'now',
         ]]
            && $Database->creates === 1
            && $Database->advisoryLocks === 1
            && $Database->advisoryUnlocks === 1
            && $Database->advisoryKeys === [$advisory, $advisory],
         description: 'Sync only changes the migration history repository'
      );

      $Reverted = $Runner->down(10, batch: 2);

      yield assert(
         assertion: $Reverted === ['20260514000100_create_posts', '20260514000200_raw_sql']
            && $Database->history === [[
               'migration'  => '20260514000000_create_users',
               'batch'      => 1,
               'created_at' => 'now',
            ]]
            && $Database->advisoryLocks === 2
            && $Database->advisoryUnlocks === 2
            && $Database->advisoryKeys === [$advisory, $advisory, $advisory, $advisory],
         description: 'Down can target one applied migration batch'
      );

   }
);
