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


use function array_diff_key;
use function assert;
use function json_encode;
use function str_repeat;
use Error;
use InvalidArgumentException;

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;


/**
 * `parameters` — session parameters sent at connection start. Validated once,
 * at config time: every entry the PostgreSQL startup message could not carry
 * as written (a NUL, a reserved or protocol name, a value without one text
 * form) is refused naming its key, and a replica inherits the map name by name.
 */
$refuse = static function (mixed $parameters, array $replica = []): string {
   $config = $replica === []
      ? ['parameters' => $parameters]
      : ['replicas' => [$replica + ['host' => 'replica.local', 'parameters' => $parameters]]];

   try {
      new Config($config);
   }
   catch (InvalidArgumentException $Exception) {
      return $Exception->getMessage();
   }

   return '';
};

return new Test(
   description: 'Database: Config admits session parameters at config time and replicas inherit them name by name',
   test: function () use ($refuse) {
      // # Default and accepted values
      $Default = new Config;

      yield assert(
         assertion: $Default->parameters === [],
         description: 'No `parameters` key means no session parameters (the driver adds its own defaults)'
      );

      $Config = new Config([
         'parameters' => [
            'search_path' => 'app, public',
            'statement_timeout' => 5000,
            'TimeZone' => 'UTC',
            'myapp.tenant' => '',
         ],
      ]);

      $admitted = json_encode($Config->parameters);

      yield assert(
         assertion: $Config->parameters === [
            'search_path' => 'app, public',
            'statement_timeout' => '5000',
            'TimeZone' => 'UTC',
            'myapp.tenant' => '',
         ],
         description: "Strings are kept as written, an integer becomes its decimal string, order and spelling are kept — {$admitted}"
      );

      // ? Only names a stock PgBouncer tracks are pinned; the formats the
      //   decoder reads either way (intervals as text, both bytea outputs)
      //   stay the user's to set.
      $Formats = new Config([
         'parameters' => [
            'IntervalStyle' => 'iso_8601',
            'BYTEA_OUTPUT' => 'escape',
         ],
      ]);
      $formats = json_encode($Formats->parameters);

      yield assert(
         assertion: $Formats->parameters === ['IntervalStyle' => 'iso_8601', 'BYTEA_OUTPUT' => 'escape'],
         description: "IntervalStyle and bytea_output are admitted, in any letter case — {$formats}"
      );

      // ? 1 to 3 extra float digits keep floats exact: the way back when a
      //   role or database lowers them
      $Floats = new Config(['parameters' => ['extra_float_digits' => 3]]);
      $Low = new Config(['parameters' => ['extra_float_digits' => '1']]);

      yield assert(
         assertion: $Floats->parameters['extra_float_digits'] === '3' && $Low->parameters === ['extra_float_digits' => '1'],
         description: 'extra_float_digits 1 to 3 is admitted'
      );

      $pinned = json_encode(Config::PINNED_PARAMETERS);

      yield assert(
         assertion: Config::PINNED_PARAMETERS === [
               'client_encoding' => 'UTF8',
               'DateStyle' => 'ISO',
               'standard_conforming_strings' => 'on',
            ]
            && array_diff_key(Config::PINNED_PARAMETERS, Config::FIXED_PARAMETERS) === [],
         description: "Pinned: only PgBouncer-tracked names, each with a refusal reason — {$pinned}"
      );

      $written = '';
      try {
         // @phpstan-ignore-next-line
         $Config->parameters = ['options' => '-c x=y'];
      }
      catch (Error $Error) {
         $written = $Error->getMessage();
      }

      yield assert(
         assertion: $written !== '' && $Config->parameters['search_path'] === 'app, public',
         description: "The admitted map is read-only from outside, so nothing bypasses the validation — {$written}"
      );

      // # Refusals — each names the key
      $cases = [
         'not an array' => ['search_path=app', 'Database `parameters` must be an array of session parameter names to values.'],
         'list entry' => [['search_path'], 'Database parameter `0` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`.'],
         'empty name' => [['' => 'x'], 'Database parameter `` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`.'],
         'NUL in name' => [["search_path\0options" => 'x'], "Database parameter `search_path\0options` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`."],
         'space in name' => [['search path' => 'x'], 'Database parameter `search path` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`.'],
         'trailing newline' => [["search_path\n" => 'x'], "Database parameter `search_path\n` is not a valid name: use letters, digits, `_` and `.`, starting with a letter or `_`."],
         'client_encoding' => [['client_encoding' => 'LATIN1'], 'Database parameter `client_encoding` cannot be set: the driver fixes client_encoding to UTF8, the encoding every value is read and written in.'],
         'Client_Encoding' => [['Client_Encoding' => 'UTF8'], 'Database parameter `Client_Encoding` cannot be set: the driver fixes client_encoding to UTF8, the encoding every value is read and written in.'],
         'DateStyle' => [['DateStyle' => 'SQL, DMY'], 'Database parameter `DateStyle` cannot be set: the driver fixes DateStyle to ISO, the date and time text its decoder reads.'],
         'datestyle' => [['datestyle' => 'ISO'], 'Database parameter `datestyle` cannot be set: the driver fixes DateStyle to ISO, the date and time text its decoder reads.'],
         'extra_float_digits 0' => [['extra_float_digits' => 0], "Database parameter `extra_float_digits` must be 1, 2 or 3: floats decode from the server's text, and 0 or less rounds them."],
         'extra_float_digits -15' => [['Extra_Float_Digits' => '-15'], "Database parameter `Extra_Float_Digits` must be 1, 2 or 3: floats decode from the server's text, and 0 or less rounds them."],
         'extra_float_digits 4' => [['extra_float_digits' => 4], "Database parameter `extra_float_digits` must be 1, 2 or 3: floats decode from the server's text, and 0 or less rounds them."],
         'extra_float_digits text' => [['extra_float_digits' => '3 '], "Database parameter `extra_float_digits` must be 1, 2 or 3: floats decode from the server's text, and 0 or less rounds them."],
         'standard_conforming_strings' => [['Standard_Conforming_Strings' => 'off'], 'Database parameter `Standard_Conforming_Strings` cannot be set: the driver fixes standard_conforming_strings to on, the literal quoting its Schema writes.'],
         'user' => [['user' => 'admin'], 'Database parameter `user` is reserved: the startup message sends it from the connection config or refuses it.'],
         'DATABASE' => [['DATABASE' => 'other'], 'Database parameter `DATABASE` is reserved: the startup message sends it from the connection config or refuses it.'],
         'replication' => [['replication' => 'database'], 'Database parameter `replication` is reserved: the startup message sends it from the connection config or refuses it.'],
         'options' => [['options' => '-c search_path=x'], 'Database parameter `options` is reserved: the startup message sends it from the connection config or refuses it.'],
         'protocol extension' => [['_pq_.test' => '1'], 'Database parameter `_pq_.test` is reserved for protocol extensions.'],
         'case duplicate' => [['search_path' => 'a', 'SEARCH_PATH' => 'b'], 'Database parameter `SEARCH_PATH` is given twice (as `search_path`): parameter names are case-insensitive.'],
         'float' => [['statement_timeout' => 1.5], 'Database parameter `statement_timeout` must be a string or an integer.'],
         'bool' => [['jit' => false], 'Database parameter `jit` must be a string or an integer.'],
         'null' => [['search_path' => null], 'Database parameter `search_path` must be a string or an integer.'],
         'array' => [['search_path' => ['app']], 'Database parameter `search_path` must be a string or an integer.'],
         'NUL in value' => [['search_path' => "app\0replication\0database"], 'Database parameter `search_path` must not contain a NUL byte.'],
      ];

      foreach ($cases as $case => [$parameters, $expected]) {
         $message = $refuse($parameters);
         $encoded = json_encode($message);

         yield assert(
            assertion: $message === $expected,
            description: "Refused ({$case}): {$encoded}"
         );
      }

      // # Replicas — inherit, override by name in any case, refuse naming the host
      $Inherited = new Config([
         'parameters' => [
            'search_path' => 'app, public',
            'statement_timeout' => 5000,
         ],
         'replicas' => [
            ['host' => 'replica-a.local'],
            [
               'host' => 'replica-b.local',
               'parameters' => [
                  'SEARCH_PATH' => 'reports',
                  'default_transaction_read_only' => 'on',
               ],
            ],
            ['host' => 'replica-c.local', 'parameters' => []],
         ],
      ]);
      $replicas = [
         $Inherited->replicas[0]['parameters'] ?? null,
         $Inherited->replicas[1]['parameters'] ?? null,
         $Inherited->replicas[2]['parameters'] ?? null,
      ];
      $inherited = json_encode($replicas[0]);
      $overridden = json_encode($replicas[1]);

      yield assert(
         assertion: $replicas[0] === ['search_path' => 'app, public', 'statement_timeout' => '5000']
            && $replicas[2] === $replicas[0],
         description: "A replica without its own parameters, or with an empty map, inherits the primary's — {$inherited}"
      );
      yield assert(
         assertion: $replicas[1] === [
            'statement_timeout' => '5000',
            'SEARCH_PATH' => 'reports',
            'default_transaction_read_only' => 'on',
         ],
         description: "A replica entry replaces the primary's under the same name in any letter case and adds its own — {$overridden}"
      );

      $Database = new SQL([
         'parameters' => ['search_path' => 'app'],
         'replicas' => [
            ['host' => 'replica-a.local', 'parameters' => ['Search_Path' => 'reports']],
         ],
      ]);
      $ReplicaConfig = $Database->ReplicaPools[0]->Config ?? null;

      yield assert(
         assertion: $ReplicaConfig instanceof Config && $ReplicaConfig->parameters === ['Search_Path' => 'reports'],
         description: 'The replica pool\'s own Config carries the merged map through the SQL facade'
      );

      $message = $refuse(['replication' => 'true'], ['host' => 'replica-x.local']);

      yield assert(
         assertion: $message === 'Database parameter `replication` is reserved: the startup message sends it from the connection config or refuses it (replica replica-x.local).',
         description: "A replica's own refused entry names the replica — {$message}"
      );

      $message = $refuse('search_path=app', ['host' => 'replica-x.local']);

      yield assert(
         assertion: $message === 'Database `parameters` must be an array of session parameter names to values (replica replica-x.local).',
         description: "A replica's non-array map is refused, not dropped — {$message}"
      );

      // # Driver — only PostgreSQL sends session parameters
      $build = static function (array $config): string {
         try {
            new Config($config);
         }
         catch (InvalidArgumentException $Exception) {
            return $Exception->getMessage();
         }

         return '';
      };
      $drivers = [
         'mysql primary' => [
            ['driver' => 'mysql', 'parameters' => ['sql_mode' => 'ANSI']],
            'Database `parameters` are PostgreSQL only: the mysql driver has no startup session parameters, so set the session after connecting instead.',
         ],
         'sqlite primary' => [
            ['driver' => 'sqlite', 'database' => ':memory:', 'parameters' => ['search_path' => 'app']],
            'Database `parameters` are PostgreSQL only: the sqlite driver has no startup session parameters, so set the session after connecting instead.',
         ],
         'mysql replica inheriting' => [
            ['parameters' => ['search_path' => 'app'], 'replicas' => [['host' => 'replica-m.local', 'driver' => 'mysql']]],
            'Database `parameters` are PostgreSQL only: the mysql driver has no startup session parameters, so set the session after connecting instead (replica replica-m.local).',
         ],
         'sqlite replica of its own' => [
            ['driver' => 'sqlite', 'database' => 'a.db', 'replicas' => [['host' => 'replica-s.local', 'parameters' => ['search_path' => 'app']]]],
            'Database `parameters` are PostgreSQL only: the sqlite driver has no startup session parameters, so set the session after connecting instead (replica replica-s.local).',
         ],
         'mysql with an empty map' => [
            ['driver' => 'mysql', 'parameters' => [], 'replicas' => [['host' => 'replica-e.local', 'parameters' => []]]],
            '',
         ],
         'postgres alias replica inheriting' => [
            ['parameters' => ['search_path' => 'app'], 'replicas' => [['host' => 'replica-a.local', 'driver' => 'postgres']]],
            'Database `parameters` need the pgsql driver: `postgres` is not a built-in driver name (pgsql, mysql or sqlite): session parameters reach only the built-in pgsql driver (replica replica-a.local).',
         ],
         'unknown primary driver' => [
            ['driver' => 'postgresql', 'parameters' => ['search_path' => 'app']],
            'Database `parameters` need the pgsql driver: `postgresql` is not a built-in driver name (pgsql, mysql or sqlite): session parameters reach only the built-in pgsql driver.',
         ],
         'postgres alias replica without parameters' => [
            ['replicas' => [['host' => 'replica-n.local', 'driver' => 'postgres']]],
            '',
         ],
         'pgsql replica of a mysql primary' => [
            ['driver' => 'mysql', 'replicas' => [['host' => 'replica-p.local', 'driver' => 'pgsql', 'parameters' => ['search_path' => 'app']]]],
            '',
         ],
      ];

      foreach ($drivers as $case => [$config, $expected]) {
         $message = $build($config);
         $encoded = json_encode($message);
         $verdict = $expected === '' ? 'admitted' : "refused at config time, before any dial — {$encoded}";

         yield assert(
            assertion: $message === $expected,
            description: "Driver ({$case}): {$verdict}"
         );
      }

      // # Size — the whole startup message fits the server's 10000 bytes
      //   With the default `postgres`/`bootgly` endpoint, the pinned settings,
      //   the default application_name and `myapp.pad`, the message after its
      //   length word is 138 bytes plus the pad: 9862 bytes reach the limit.
      $Fitting = new Config(['parameters' => ['myapp.pad' => str_repeat('a', 9862)]]);
      $message = $build(['parameters' => ['myapp.pad' => str_repeat('a', 9863)]]);

      yield assert(
         assertion: $Fitting->parameters['myapp.pad'] === str_repeat('a', 9862)
            && $message === "Database startup message is 10001 bytes, over PostgreSQL's 10000-byte limit: shorten `parameters`, `username` or `database`.",
         description: "A message of exactly 10000 bytes is admitted and one byte more is refused naming its size — {$message}"
      );

      $message = $build(['parameters' => ['search_path' => 'app'], 'replicas' => [['host' => 'replica-l.local', 'username' => str_repeat('u', 10000)]]]);

      yield assert(
         assertion: $message === "Database startup message is 10135 bytes, over PostgreSQL's 10000-byte limit: shorten `parameters`, `username` or `database` (replica replica-l.local).",
         description: "A replica's message is measured with its own username and inherited map, naming the replica — {$message}"
      );

      $message = $build(['driver' => 'sqlite', 'database' => str_repeat('d', 10000)]);

      yield assert(
         assertion: $message === '',
         description: 'Only a PostgreSQL endpoint is measured: a long SQLite path is not a startup message'
      );
   }
);
