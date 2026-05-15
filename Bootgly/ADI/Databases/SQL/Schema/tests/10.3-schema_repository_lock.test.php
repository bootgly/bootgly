<?php

namespace Bootgly\ADI\Databases\SQL\Schema\Tests\RepositoryLock;


use const BOOTGLY_WORKING_DIR;
use function assert;
use function file_put_contents;
use function is_dir;
use function mkdir;
use function rmdir;
use function uniqid;
use function unlink;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Schema\Lock;
use Bootgly\ADI\Databases\SQL\Schema\Repository;


return new Specification(
   description: 'Database: SQL schema repository queries and lock file behavior',
   test: function () {
      $Database = new SQL;
      $Repository = new Repository($Database->Dialect, $Database->SQLConfig->migrations);

      yield assert(
         assertion: $Repository->create()->sql === 'CREATE TABLE IF NOT EXISTS "_bootgly_migrations" ("migration" VARCHAR(255) NOT NULL PRIMARY KEY, "batch" INTEGER NOT NULL, "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
         description: 'Repository compiles PostgreSQL migration status table creation through the Schema Builder'
      );

      $Query = $Repository->insert('20260514000000_create_accounts', 3);

      yield assert(
         assertion: $Query->sql === 'INSERT INTO "_bootgly_migrations" ("migration", "batch") VALUES ($1, $2)'
            && $Query->parameters === ['20260514000000_create_accounts', 3],
         description: 'Repository compiles migration insert with parameters'
      );

      yield assert(
         assertion: $Repository->peek()->sql === 'SELECT COALESCE(MAX("batch"), 0) AS "batch" FROM "_bootgly_migrations"'
            && $Repository->delete('x')->sql === 'DELETE FROM "_bootgly_migrations" WHERE "migration" = $1'
            && $Repository->fetch()->sql === 'SELECT "migration", "batch", "created_at" FROM "_bootgly_migrations" ORDER BY "batch" DESC, "migration" DESC',
         description: 'Repository compiles PostgreSQL lookup fetch and delete queries'
      );

      $MySQL = new SQL(['driver' => 'mysql']);
      $MySQLRepository = new Repository($MySQL->Dialect, $MySQL->SQLConfig->migrations);
      $MySQLInsert = $MySQLRepository->insert('20260514000000_create_accounts', 3);

      yield assert(
         assertion: $MySQLRepository->create()->sql === 'CREATE TABLE IF NOT EXISTS `_bootgly_migrations` (`migration` VARCHAR(255) NOT NULL PRIMARY KEY, `batch` INT NOT NULL, `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            && $MySQLRepository->fetch()->sql === 'SELECT `migration`, `batch`, `created_at` FROM `_bootgly_migrations` ORDER BY `batch` DESC, `migration` DESC'
            && $MySQLRepository->peek()->sql === 'SELECT COALESCE(MAX(`batch`), 0) AS `batch` FROM `_bootgly_migrations`'
            && $MySQLInsert->sql === 'INSERT INTO `_bootgly_migrations` (`migration`, `batch`) VALUES (?, ?)'
            && $MySQLInsert->parameters === ['20260514000000_create_accounts', 3]
            && $MySQLRepository->delete('x')->sql === 'DELETE FROM `_bootgly_migrations` WHERE `migration` = ?',
         description: 'Repository compiles MySQL migration repository queries with MySQL quoting and markers'
      );

      $SQLite = new SQL(['driver' => 'sqlite']);
      $SQLiteRepository = new Repository($SQLite->Dialect, $SQLite->SQLConfig->migrations);
      $SQLiteInsert = $SQLiteRepository->insert('20260514000000_create_accounts', 3);

      yield assert(
         assertion: $SQLiteRepository->create()->sql === 'CREATE TABLE IF NOT EXISTS "_bootgly_migrations" ("migration" TEXT NOT NULL PRIMARY KEY, "batch" INTEGER NOT NULL, "created_at" TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP)'
            && $SQLiteRepository->fetch()->sql === 'SELECT "migration", "batch", "created_at" FROM "_bootgly_migrations" ORDER BY "batch" DESC, "migration" DESC'
            && $SQLiteRepository->peek()->sql === 'SELECT COALESCE(MAX("batch"), 0) AS "batch" FROM "_bootgly_migrations"'
            && $SQLiteInsert->sql === 'INSERT INTO "_bootgly_migrations" ("migration", "batch") VALUES (?1, ?2)'
            && $SQLiteInsert->parameters === ['20260514000000_create_accounts', 3]
            && $SQLiteRepository->delete('x')->sql === 'DELETE FROM "_bootgly_migrations" WHERE "migration" = ?1',
         description: 'Repository compiles SQLite migration repository queries with SQLite quoting and markers'
      );

      $dir = BOOTGLY_WORKING_DIR . 'workdata/tests/schema-lock-' . uniqid() . '/';
      if (is_dir($dir) === false) {
         mkdir($dir, 0775, true);
      }

      $Lock = new Lock($dir . 'migrations.lock');
      $Second = new Lock($dir . 'migrations.lock');

      yield assert(
         assertion: $Lock->acquire() && $Lock->check(),
         description: 'Lock acquires a local migration lock file'
      );

      yield assert(
         assertion: $Second->acquire() === false,
         description: 'Lock rejects concurrent acquisition'
      );

      $Lock->release();

      yield assert(
         assertion: $Lock->check() === false,
         description: 'Lock releases the local migration lock file'
      );

      $Stale = new Lock($dir . 'stale.lock');
      file_put_contents($dir . 'stale.lock', "pid=0\ntime=0\n");

      yield assert(
         assertion: $Stale->acquire() && $Stale->check(),
         description: 'Lock reclaims stale migration lock files'
      );

      $Stale->release();

      unlink($dir . 'migrations.lock.guard');
      unlink($dir . 'stale.lock.guard');

      rmdir($dir);
   }
);
