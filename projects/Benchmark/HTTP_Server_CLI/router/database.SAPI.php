<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\HTTP_Server_CLI\router;


use function count;
use function getenv;
use function is_numeric;
use function json_encode;
use function strtolower;
use Generator;
use Throwable;

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Runner;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   $Env = static function (string $name, string $default): string {
      $value = getenv($name);

      return $value === false || $value === '' ? $default : $value;
   };

   $Bool = static function (string $name, bool $default) use ($Env): bool {
      $value = strtolower($Env($name, $default ? 'true' : 'false'));

      return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
   };

   $Connect = static function () use ($Env, $Bool): SQL {
      static $Database = null;

      if ($Database instanceof SQL) {
         return $Database;
      }

      $port = $Env('DB_PORT', (string) Config::DEFAULT_PORT);
      $timeout = $Env('DB_TIMEOUT', (string) Config::DEFAULT_TIMEOUT);
      $poolMin = $Env('DB_POOL_MIN', (string) Config::DEFAULT_POOL_MIN);
      $poolMax = $Env('DB_POOL_MAX', (string) Config::DEFAULT_POOL_MAX);
      $statements = $Env('DB_STATEMENTS', (string) Config::DEFAULT_STATEMENTS);

      $Database = new SQL([
         'driver' => $Env('DB_CONNECTION', Config::DEFAULT_DRIVER),
         'host' => $Env('DB_HOST', Config::DEFAULT_HOST),
         'port' => is_numeric($port) ? (int) $port : Config::DEFAULT_PORT,
         'database' => $Env('DB_NAME', Config::DEFAULT_DATABASE),
         'username' => $Env('DB_USER', Config::DEFAULT_USERNAME),
         'password' => $Env('DB_PASS', Config::DEFAULT_PASSWORD),
         'timeout' => is_numeric($timeout) ? (float) $timeout : Config::DEFAULT_TIMEOUT,
         'statements' => is_numeric($statements) ? (int) $statements : Config::DEFAULT_STATEMENTS,
         'pool' => [
            'min' => is_numeric($poolMin) ? (int) $poolMin : Config::DEFAULT_POOL_MIN,
            'max' => is_numeric($poolMax) ? (int) $poolMax : Config::DEFAULT_POOL_MAX,
         ],
         'secure' => [
            'mode' => $Env('DB_SSLMODE', Config::SECURE_DISABLE),
            'verify' => $Bool('DB_SSLVERIFY', false),
            'peer' => $Env('DB_SSLPEER', ''),
            'cafile' => $Env('DB_SSLCAFILE', ''),
         ],
      ]);

      return $Database;
   };

   $Native = static function (SQL $Database, Response $Response, Operation $Operation): Operation {
      while ($Operation->finished === false) {
         $Operation = $Database->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         $Readiness = $Operation->Readiness;

         if ($Readiness !== null) {
            $Response->wait($Readiness);
         }
         else {
            $Response->wait();
         }
      }

      return $Operation;
   };

   $Drain = static function (SQL $Database, Response $Response, array $Operations): array {
      while (true) {
         $waiting = null;
         $finished = true;

         foreach ($Operations as $id => $Operation) {
            if ($Operation->finished) {
               continue;
            }

            $finished = false;
            $Operation = $Database->advance($Operation);
            $Operations[$id] = $Operation;
            $waiting ??= $Operation->Readiness;
         }

         if ($finished) {
            break;
         }

         if ($waiting !== null) {
            $Response->wait($waiting);
         }
         else {
            $Response->wait();
         }
      }

      return $Operations;
   };

   $Body = static function (Operation $Operation): string {
      if ($Operation->error !== null) {
         return $Operation->error;
      }

      $Result = $Operation->Result;

      if ($Result === null) {
         return '[]';
      }

      return json_encode($Result->rows) ?: '[]';
   };

   $Send = static function (Response $Response, Operation $Operation) use ($Body): object {
      return $Response(
         code: $Operation->error === null ? 200 : 500,
         body: $Body($Operation)
      );
   };

   $Pool = static function (Response $Response, SQL $Database, array $Operations): object {
      $rows = [];
      $errors = [];

      foreach ($Operations as $Operation) {
         if ($Operation->error !== null) {
            $errors[] = $Operation->error;
         }

         if ($Operation->Result !== null) {
            $rows[] = $Operation->Result->row;
         }
      }

      $body = json_encode([
         'rows' => $rows,
         'errors' => $errors,
         'pool' => [
            'idle' => count($Database->Pool->idle),
            'busy' => count($Database->Pool->busy),
            'pending' => count($Database->Pool->pending),
            'created' => $Database->Pool->created,
         ],
      ]) ?: '{}';

      return $Response(code: $errors === [] ? 200 : 500, body: $body);
   };

   $Exception = static function (Response $Response, Throwable $Throwable): object {
      return $Response(code: 500, body: $Throwable->getMessage());
   };

   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Database Benchmark');
   }, GET);

   yield $Router->route('/database/native/ping', function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT 1 AS ok'));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/runner/ping', function (Request $Request, Response $Response) use ($Connect, $Exception, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Send): void {
         try {
            $Database = $Connect();
            $Runner = new Runner($Database, $Response);
            $Operation = $Runner->query('SELECT 1 AS ok');

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/native/parameters', function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT $1::int AS value, $2::text AS label', [42, 'bootgly']));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/runner/parameters', function (Request $Request, Response $Response) use ($Connect, $Exception, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Send): void {
         try {
            $Database = $Connect();
            $Runner = new Runner($Database, $Response);
            $Operation = $Runner->query('SELECT $1::int AS value, $2::text AS label', [42, 'bootgly']);

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/native/pool', function (Request $Request, Response $Response) use ($Connect, $Drain, $Exception, $Pool) {
      return $Response->defer(function () use ($Response, $Connect, $Drain, $Exception, $Pool): void {
         try {
            $Database = $Connect();
            $Operations = [
               $Database->query('SELECT $1::int AS value', [1]),
               $Database->query('SELECT $1::int AS value', [2]),
               $Database->query('SELECT $1::int AS value', [3]),
            ];
            $Operations = $Drain($Database, $Response, $Operations);

            $Pool($Response, $Database, $Operations);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/runner/pool', function (Request $Request, Response $Response) use ($Connect, $Exception, $Pool) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Pool): void {
         try {
            $Database = $Connect();
            $Runner = new Runner($Database, $Response);
            $Operations = [
               $Database->query('SELECT $1::int AS value', [1]),
               $Database->query('SELECT $1::int AS value', [2]),
               $Database->query('SELECT $1::int AS value', [3]),
            ];
            $Operations = $Runner->drain($Operations);

            $Pool($Response, $Database, $Operations);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/native/sleep', function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT pg_sleep(0.05), $1::int AS value', [42]));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/database/runner/sleep', function (Request $Request, Response $Response) use ($Connect, $Exception, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Exception, $Send): void {
         try {
            $Database = $Connect();
            $Runner = new Runner($Database, $Response);
            $Operation = $Runner->query('SELECT pg_sleep(0.05), $1::int AS value', [42]);

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   }, GET);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
