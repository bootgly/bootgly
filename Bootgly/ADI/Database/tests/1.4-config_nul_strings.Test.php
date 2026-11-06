<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Database;


use function assert;
use function json_encode;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Database\Config as DatabaseConfig;
use Bootgly\ADI\Databases\SQL\Config;


/**
 * `username` and `database` — every SQL driver frames them as NUL-terminated
 * strings (the PostgreSQL startup message, the MySQL handshake response, the
 * SQLite file path), so the SQL config refuses a NUL byte in either one, on
 * the primary or on a replica, instead of letting it end the value on the
 * wire. The paradigm-neutral base config keeps it: KV's RESP is length-prefixed.
 */
$refuse = static function (string $class, array $config): string {
   try {
      new $class($config);
   }
   catch (InvalidArgumentException $Exception) {
      return $Exception->getMessage();
   }

   return '';
};

return new Test(
   description: 'Database: SQL Config refuses a NUL byte in username and database, primary and replicas',
   test: function () use ($refuse) {
      $cases = [
         'primary username' => [
            ['username' => "postgres\0replication\0database"],
            'Database `username` must not contain a NUL byte.',
         ],
         'primary database' => [
            ['database' => "bootgly\0options\0-c search_path=evil"],
            'Database `database` must not contain a NUL byte.',
         ],
         'mysql username' => [
            ['driver' => 'mysql', 'username' => "root\0"],
            'Database `username` must not contain a NUL byte.',
         ],
         'sqlite database' => [
            ['driver' => 'sqlite', 'database' => "app.db\0.txt"],
            'Database `database` must not contain a NUL byte.',
         ],
         'replica username' => [
            ['replicas' => [['host' => 'replica-a.local', 'username' => "reader\0options\0-c x=y"]]],
            'Database `username` must not contain a NUL byte (replica replica-a.local).',
         ],
         'replica database' => [
            ['replicas' => [['host' => 'replica-b.local', 'database' => "\0"]]],
            'Database `database` must not contain a NUL byte (replica replica-b.local).',
         ],
      ];

      // @@
      foreach ($cases as $case => [$config, $expected]) {
         $message = $refuse(Config::class, $config);
         $encoded = json_encode($message);

         yield assert(
            assertion: $message === $expected,
            description: "Refused ({$case}): {$encoded}"
         );
      }

      // # Boundary — the base config, which KV builds, frames nothing itself
      $message = $refuse(DatabaseConfig::class, ['driver' => 'redis', 'username' => "default\0"]);
      $Base = new DatabaseConfig(['driver' => 'redis', 'username' => "default\0"]);

      yield assert(
         assertion: $message === '' && $Base->username === "default\0",
         description: 'The paradigm-neutral base config keeps a NUL byte: RESP sends length-prefixed strings'
      );

      // # Control — values without a NUL are kept as written
      $Accepted = new Config([
         'username' => 'app user',
         'database' => 'app-db',
         'replicas' => [['host' => 'replica-c.local', 'username' => 'reader', 'database' => 'reports']],
      ]);

      yield assert(
         assertion: $Accepted->username === 'app user'
            && $Accepted->database === 'app-db'
            && $Accepted->replicas[0]['username'] === 'reader'
            && $Accepted->replicas[0]['database'] === 'reports',
         description: 'A username or database without a NUL byte is admitted unchanged, primary and replica'
      );
   }
);
