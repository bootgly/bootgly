<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\NonFiniteDefaults;


use const INF;
use const NAN;
use function assert;
use function bin2hex;
use function count;
use function extension_loaded;
use function getenv;
use function json_encode;
use function random_bytes;
use function strtolower;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Builder\Expression;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;
use Bootgly\ADI\Databases\SQL\Schema\Blueprint;


return new Test(
   description: 'Database: schema dialects refuse non-finite float defaults before emitting SQL',
   test: function () {
      $Schemas = [
         'pgsql' => (new SQL(['driver' => 'pgsql']))->structure(),
         'mysql' => (new SQL(['driver' => 'mysql']))->structure(),
         'sqlite' => (new SQL(['driver' => 'sqlite']))->structure(),
      ];
      $Types = Types::cases();
      $values = [
         'nan' => NAN,
         'positive_infinity' => INF,
         'negative_infinity' => -INF,
      ];

      /**
       * Compile one shape without losing the vulnerable SQL or the refusal details.
       *
       * @return array{kind:string,class?:class-string<Throwable>,message?:string,sql?:string}
       */
      $Capture = static function (Closure $Compiler): array {
         try {
            $Query = $Compiler();

            return [
               'kind' => 'sql',
               'sql' => $Query->SQL,
            ];
         }
         catch (Throwable $Failure) {
            return [
               'kind' => 'exception',
               'class' => $Failure::class,
               'message' => $Failure->getMessage(),
            ];
         }
      };
      /**
       * Check the exact public refusal contract.
       *
       * @param array{kind:string,class?:class-string<Throwable>,message?:string,sql?:string} $outcome
       */
      $Secure = static function (array $outcome): bool {
         return $outcome['kind'] === 'exception'
            && ($outcome['class'] ?? null) === InvalidArgumentException::class
            && ($outcome['message'] ?? null) === 'Schema default float must be finite.';
      };

      // # Assignment remains a data-shaping operation. The portable rejection
      //   belongs to dialect compilation, after a complete blueprint exists.
      $assignmentEvidence = [];

      foreach ($values as $label => $value) {
         foreach (['column', 'change'] as $shape) {
            try {
               $AssignmentBlueprint = new Blueprint("sch13_assignment_{$shape}_{$label}");

               if ($shape === 'column') {
                  $Default = $AssignmentBlueprint->add('c', Types::Float);
               }
               else {
                  $Default = $AssignmentBlueprint->change('c', Types::Float);
               }

               $Default->default = $value;
               $assignmentEvidence["{$shape}:{$label}"] = [
                  'error' => null,
                  'defaulted' => $Default->defaulted,
               ];
            }
            catch (Throwable $Failure) {
               $assignmentEvidence["{$shape}:{$label}"] = [
                  'error' => $Failure->getMessage(),
                  'defaulted' => false,
               ];
            }
         }
      }

      $stats = [
         'create' => ['total' => 0, 'secure' => 0, 'samples' => []],
         'alter_add' => ['total' => 0, 'secure' => 0, 'samples' => []],
         'alter_default' => ['total' => 0, 'secure' => 0, 'samples' => []],
         'alter_typed' => ['total' => 0, 'secure' => 0, 'samples' => []],
      ];
      /**
       * Record every matrix cell while retaining one diagnostic per dialect/value pair.
       *
       * @param array{kind:string,class?:class-string<Throwable>,message?:string,sql?:string} $outcome
       */
      $Observe = static function (
         string $route,
         string $driver,
         string $value,
         array $outcome
      ) use (&$stats, $Secure): void {
         $stats[$route]['total']++;
         if ($Secure($outcome)) {
            $stats[$route]['secure']++;
         }

         $sample = "{$driver}:{$value}";
         $stats[$route]['samples'][$sample] ??= $outcome;
      };

      // # Column definitions — every declared SQL type reaches the same literal renderer.
      foreach ($Schemas as $driver => $Schema) {
         foreach ($Types as $Type) {
            foreach ($values as $label => $value) {
               $table = 'sch13_create_' . strtolower($Type->name) . '_' . $label;
               $outcome = $Capture(static function () use ($Schema, $Type, $table, $value) {
                  return $Schema->create($table, static function (Blueprint $Table) use ($Type, $value): void {
                     $Table->add('c', $Type)->default = $value;
                  });
               });
               $Observe('create', $driver, $label, $outcome);

               $table = 'sch13_add_' . strtolower($Type->name) . '_' . $label;
               $outcome = $Capture(static function () use ($Schema, $Type, $table, $value) {
                  return $Schema->alter($table, static function (Blueprint $Table) use ($Type, $value): void {
                     $Table->add('c', $Type)->default = $value;
                  });
               });
               $Observe('alter_add', $driver, $label, $outcome);
            }
         }
      }

      // # Change definitions — SQLite refuses this capability before rendering a default.
      foreach (['pgsql', 'mysql'] as $driver) {
         $Schema = $Schemas[$driver];

         foreach ($values as $label => $value) {
            $outcome = $Capture(static function () use ($Schema, $value) {
               return $Schema->alter('sch13_default', static function (Blueprint $Table) use ($value): void {
                  $Table->change('c', Types::Float)->default = $value;
               });
            });
            $Observe('alter_default', $driver, $label, $outcome);

            // ! This is the exact shaped route that emitted MySQL's broken
            //   `MODIFY COLUMN ... DOUBLE NOT NULL DEFAULT NaN` statement.
            $outcome = $Capture(static function () use ($Schema, $value) {
               return $Schema->alter('sch13_typed', static function (Blueprint $Table) use ($value): void {
                  $Change = $Table->change('c', Types::Float)->size(10);
                  $Change->nullable = false;
                  $Change->default = $value;
               });
            });
            $Observe('alter_typed', $driver, $label, $outcome);
         }
      }

      // # SQLite source-to-sink — two bare identifiers silently become TEXT;
      //   the signed spelling instead fails at CREATE TABLE.
      $sqliteEvidence = [
         'available' => extension_loaded('sqlite3'),
         'values' => [],
      ];

      if ($sqliteEvidence['available']) {
         $SQLiteDatabase = new SQL(['driver' => 'sqlite', 'database' => ':memory:']);
         $SQLiteSchema = $SQLiteDatabase->structure();

         foreach ($values as $label => $value) {
            $table = "sch13_sink_{$label}";
            $outcome = $Capture(static function () use ($SQLiteSchema, $table, $value) {
               return $SQLiteSchema->create($table, static function (Blueprint $Table) use ($value): void {
                  $Table->add('c', Types::Float)->default = $value;
               });
            });
            $evidence = ['compile' => $outcome];

            try {
               if ($outcome['kind'] === 'sql') {
                  $Create = $SQLiteDatabase->query($outcome['sql']);
                  $Insert = $SQLiteDatabase->query("INSERT INTO \"{$table}\" DEFAULT VALUES");
                  $Read = $SQLiteDatabase->query("SELECT c, typeof(c) AS kind FROM \"{$table}\"");
                  $evidence += [
                     'create_error' => $Create->error,
                     'insert_error' => $Insert->error,
                     'read_error' => $Read->error,
                     'row' => $Read->Result?->row,
                  ];
               }
            }
            finally {
               $SQLiteDatabase->query("DROP TABLE IF EXISTS \"{$table}\"");
            }

            $sqliteEvidence['values'][$label] = $evidence;
         }
      }

      // # Controls — finite floats keep their round-trip digits, while string
      //   tokens remain quoted and trusted expressions remain raw.
      $finite = [];
      $strings = [];
      $expressions = [];

      foreach ($Schemas as $driver => $Schema) {
         $finite[$driver] = $Schema->create('sch13_finite', static function (Blueprint $Table): void {
            $Table->add('c', Types::Float)->default = 0.1 + 0.2;
         })->SQL;

         foreach (['NaN', 'Infinity', '-Infinity'] as $token) {
            $strings[$driver][$token] = $Schema->create(
               'sch13_string_' . strtolower($token),
               static function (Blueprint $Table) use ($token): void {
                  $Table->add('c', Types::String)->default = $token;
               }
            )->SQL;
         }

         $Expression = new Expression('CURRENT_TIMESTAMP');
         $expressions[$driver] = $Schema->create(
            'sch13_expression',
            static function (Blueprint $Table) use ($Expression): void {
               $Table->add('c', Types::Timestamp)->default = $Expression;
            }
         )->SQL;
      }

      // # Optional source-to-sink confirmation against one real server.
      //   An honoured opt-in always connects or fails explicitly; it never skips.
      $live = getenv('BOOTGLY_SCHEMA_E2E');
      $live = $live === false ? '' : $live;
      $liveEvidence = null;

      if ($live !== '') {
         if ($live !== 'mysql' && $live !== 'pgsql') {
            throw new RuntimeException(
               'SCH-13: BOOTGLY_SCHEMA_E2E must be exactly "mysql" or "pgsql".'
            );
         }

         $host = getenv('DB_HOST');
         $port = getenv('DB_PORT');
         $database = getenv('DB_NAME');
         $username = getenv('DB_USER');
         $password = getenv('DB_PASS');
         $SSLMode = getenv('DB_SSLMODE');
         $config = [
            'driver' => $live,
            'host' => $host === false ? '127.0.0.1' : $host,
            'port' => $port === false ? ($live === 'mysql' ? 3306 : 5432) : (int) $port,
            'database' => $database === false ? ($live === 'mysql' ? 'bootgly' : 'postgres') : $database,
            'username' => $username === false ? ($live === 'mysql' ? 'root' : 'postgres') : $username,
            'password' => $password === false ? '' : $password,
            'timeout' => 5.0,
            'secure' => [
               'mode' => $live === 'pgsql' && $SSLMode !== false ? $SSLMode : 'disable',
            ],
            'pool' => ['min' => 0, 'max' => 1],
         ];
         $LiveDatabase = new SQL($config);
         $LiveSchema = $LiveDatabase->structure();
         $table = 'sch13_live_' . bin2hex(random_bytes(6));
         $tableSQL = $live === 'mysql' ? "`{$table}`" : "\"{$table}\"";
         $columnSQL = $live === 'mysql' ? '`c`' : '"c"';
         $typeSQL = $live === 'mysql' ? 'DOUBLE' : 'DOUBLE PRECISION';
         $connected = false;
         $cleanupFailure = null;

         /**
          * Execute one network operation to completion.
          */
         $Await = static function (SQL $Database, string $SQL) {
            $Operation = $Database->query($SQL);
            $Database->await($Operation);

            return $Operation;
         };

         try {
            try {
               $Warm = $Await($LiveDatabase, 'SELECT 1 AS warm');
            }
            catch (Throwable $Failure) {
               throw new RuntimeException(
                  "SCH-13: {$live} live opt-in could not connect with DB_*: {$Failure->getMessage()}",
                  previous: $Failure
               );
            }

            if ($Warm->error !== null) {
               throw new RuntimeException(
                  "SCH-13: {$live} live opt-in could not connect with DB_*: {$Warm->error}"
               );
            }
            $connected = true;

            $Create = $Await(
               $LiveDatabase,
               "CREATE TABLE {$tableSQL} ({$columnSQL} {$typeSQL} NOT NULL DEFAULT 1.5)"
            );
            $insertSQL = $live === 'mysql'
               ? "INSERT INTO {$tableSQL} () VALUES ()"
               : "INSERT INTO {$tableSQL} DEFAULT VALUES";
            $Insert = $Await($LiveDatabase, $insertSQL);
            $Read = $Await($LiveDatabase, "SELECT {$columnSQL} FROM {$tableSQL}");

            if ($Create->error !== null || $Insert->error !== null || $Read->error !== null) {
               throw new RuntimeException(
                  'SCH-13: live finite-default control failed: '
                  . json_encode([$Create->error, $Insert->error, $Read->error])
               );
            }

            $outcome = $Capture(static function () use ($LiveSchema, $table) {
               return $LiveSchema->alter($table, static function (Blueprint $Table): void {
                  $Change = $Table->change('c', Types::Float)->size(10);
                  $Change->nullable = false;
                  $Change->default = NAN;
               });
            });
            $liveEvidence = [
               'driver' => $live,
               'finite' => $Read->Result?->cell,
               'compile' => $outcome,
            ];

            if ($outcome['kind'] === 'sql') {
               try {
                  $Broken = $Await($LiveDatabase, $outcome['sql']);
                  $liveEvidence['server_error'] = $Broken->error;
               }
               catch (Throwable $Failure) {
                  $liveEvidence['server_error'] = $Failure->getMessage();
               }
            }
         }
         finally {
            try {
               if ($connected) {
                  $Cleanup = $Await($LiveDatabase, "DROP TABLE IF EXISTS {$tableSQL}");
                  if ($Cleanup->error !== null) {
                     $cleanupFailure = $Cleanup->error;
                  }
               }
            }
            catch (Throwable $Failure) {
               $cleanupFailure = $Failure->getMessage();
            }
            finally {
               $LiveDatabase->Connection->disconnect();
            }
         }

         if ($cleanupFailure !== null) {
            throw new RuntimeException(
               "SCH-13: failed to clean live table {$table}: {$cleanupFailure}"
            );
         }
      }

      $assignmentsSafe = true;
      foreach ($assignmentEvidence as $evidence) {
         $assignmentsSafe = $assignmentsSafe
            && $evidence === ['error' => null, 'defaulted' => true];
      }

      yield assert(
         assertion: $assignmentsSafe && count($assignmentEvidence) === 6,
         description: 'SCH-13 control: Column and Change accept non-finite float data until Schema compilation; evidence='
            . json_encode($assignmentEvidence)
      );

      foreach (['create', 'alter_add', 'alter_default', 'alter_typed'] as $route) {
         yield assert(
            assertion: $stats[$route]['secure'] === $stats[$route]['total'],
            description: "SCH-13: {$route} must reject every non-finite float with "
               . 'InvalidArgumentException before SQL emission; evidence='
               . json_encode([
                  'stats' => $stats[$route],
                  'sqlite_sink' => $sqliteEvidence,
                  'live' => $liveEvidence,
               ])
         );
      }

      $sqliteSecure = true;
      foreach ($sqliteEvidence['values'] as $evidence) {
         $sqliteSecure = $sqliteSecure && $Secure($evidence['compile']);
      }

      yield assert(
         assertion: $sqliteSecure,
         description: 'SCH-13: no non-finite default may reach the SQLite engine; evidence='
            . json_encode($sqliteEvidence)
      );

      if ($liveEvidence !== null) {
         yield assert(
            assertion: $liveEvidence['finite'] == 1.5 && $Secure($liveEvidence['compile']),
            description: 'SCH-13: live finite DDL must work and non-finite DDL must be refused before wire; evidence='
               . json_encode($liveEvidence)
         );
      }

      yield assert(
         assertion: $finite === [
            'pgsql' => 'CREATE TABLE "sch13_finite" ("c" DOUBLE PRECISION NOT NULL DEFAULT 0.30000000000000004)',
            'mysql' => 'CREATE TABLE `sch13_finite` (`c` DOUBLE NOT NULL DEFAULT 0.30000000000000004)',
            'sqlite' => 'CREATE TABLE "sch13_finite" ("c" REAL NOT NULL DEFAULT 0.30000000000000004)',
         ],
         description: 'SCH-13: finite floats keep the shared shortest-round-trip rendering'
      );

      yield assert(
         assertion: $strings === [
            'pgsql' => [
               'NaN' => 'CREATE TABLE "sch13_string_nan" ("c" VARCHAR(255) NOT NULL DEFAULT \'NaN\')',
               'Infinity' => 'CREATE TABLE "sch13_string_infinity" ("c" VARCHAR(255) NOT NULL DEFAULT \'Infinity\')',
               '-Infinity' => 'CREATE TABLE "sch13_string_-infinity" ("c" VARCHAR(255) NOT NULL DEFAULT \'-Infinity\')',
            ],
            'mysql' => [
               'NaN' => 'CREATE TABLE `sch13_string_nan` (`c` VARCHAR(255) NOT NULL DEFAULT \'NaN\')',
               'Infinity' => 'CREATE TABLE `sch13_string_infinity` (`c` VARCHAR(255) NOT NULL DEFAULT \'Infinity\')',
               '-Infinity' => 'CREATE TABLE `sch13_string_-infinity` (`c` VARCHAR(255) NOT NULL DEFAULT \'-Infinity\')',
            ],
            'sqlite' => [
               'NaN' => 'CREATE TABLE "sch13_string_nan" ("c" TEXT NOT NULL DEFAULT \'NaN\')',
               'Infinity' => 'CREATE TABLE "sch13_string_infinity" ("c" TEXT NOT NULL DEFAULT \'Infinity\')',
               '-Infinity' => 'CREATE TABLE "sch13_string_-infinity" ("c" TEXT NOT NULL DEFAULT \'-Infinity\')',
            ],
         ],
         description: 'SCH-13: textual spellings remain ordinary safely quoted defaults'
      );

      yield assert(
         assertion: $expressions === [
            'pgsql' => 'CREATE TABLE "sch13_expression" ("c" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'mysql' => 'CREATE TABLE `sch13_expression` (`c` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            'sqlite' => 'CREATE TABLE "sch13_expression" ("c" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)',
         ],
         description: 'SCH-13: trusted schema expressions remain raw escape hatches'
      );
   }
);
