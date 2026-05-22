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


use function asort;
use function count;
use function getenv;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_numeric;
use function json_encode;
use function max;
use function min;
use function mt_rand;
use function strtolower;
use Generator;
use Throwable;

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


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

   // ---

   $Send = static function (Response $Response, Operation $Operation) use ($Body): object {
      return $Response(
         code: $Operation->error === null ? 200 : 500,
         body: $Body($Operation)
      );
   };

   $Json = static function (Response $Response, string $body, int $code = 200): object {
      return $Response(
         code: $code,
         headers: ['Content-Type' => 'application/json'],
         body: $body
      );
   };

   $Html = static function (Response $Response, string $body, int $code = 200): object {
      return $Response(
         code: $code,
         headers: ['Content-Type' => 'text/html; charset=utf-8'],
         body: $body
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

   // ---

   $QueryCount = static function (Request $Request): int {
      $queries = $Request->queries['queries'] ?? 1;

      if (is_array($queries)) {
         $queries = $queries[0] ?? 1;
      }

      return max(1, min(500, (int) $queries));
   };

   $World = static function (array $row): array {
      return [
         'id' => (int) ($row['id'] ?? 0),
         'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
      ];
   };

   $FetchWorld = static function (SQL $Database, Response $Response, int $id) use ($Native, $World): array {
      $Operation = $Native(
         $Database,
         $Response,
         $Database->query('SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1', [$id])
      );

      if ($Operation->error !== null) {
         throw new \RuntimeException($Operation->error);
      }

      return $World($Operation->Result->row ?? []);
   };

   $UpdateWorlds = static function (SQL $Database, Response $Response, array $Worlds) use ($Native): void {
      $cases = [];
      $ids = [];
      $parameters = [];
      $placeholder = 1;

      foreach ($Worlds as $World) {
         $cases[] = 'WHEN $' . $placeholder++ . '::integer THEN $' . $placeholder++ . '::integer';
         $parameters[] = $World['id'];
         $parameters[] = $World['randomNumber'];
      }

      foreach ($Worlds as $World) {
         $ids[] = '$' . $placeholder++ . '::integer';
         $parameters[] = $World['id'];
      }

      $Operation = $Native(
         $Database,
         $Response,
         $Database->query(
            'UPDATE World SET randomNumber = CASE id ' . implode(' ', $cases) . ' END WHERE id IN (' . implode(',', $ids) . ')',
            $parameters
         )
      );

      if ($Operation->error !== null) {
         throw new \RuntimeException($Operation->error);
      }
   };

   $FortunesHtml = static function (array $rows): string {
      $Fortunes = [0 => 'Additional fortune added at request time.'];

      foreach ($rows as $row) {
         $Fortunes[(int) $row['id']] = (string) $row['message'];
      }

      asort($Fortunes);

      $html = '';
      foreach ($Fortunes as $id => $message) {
         $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
         $html .= "<tr><td>{$id}</td><td>{$message}</td></tr>";
      }

      return "<!DOCTYPE html><html><head><title>Fortunes</title></head><body><table><tr><th>id</th><th>message</th></tr>{$html}</table></body></html>";
   };

   // ---

   $TfbDb = function (Request $Request, Response $Response) use ($Connect, $Exception, $FetchWorld, $Json) {
      return $Response->defer(function (Response $Response) use ($Connect, $Exception, $FetchWorld, $Json): void {
         try {
            $Database = $Connect();
            $World = $FetchWorld($Database, $Response, mt_rand(1, 10000));

            $Json($Response, json_encode($World) ?: '{}', 200);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $TfbQuery = function (Request $Request, Response $Response) use ($Connect, $Drain, $Exception, $Json, $QueryCount, $World) {
      $queries = $QueryCount($Request);

      return $Response->defer(function (Response $Response) use ($Connect, $Drain, $Exception, $Json, $queries, $World): void {
         try {
            $Database = $Connect();

            // @ Issue every query first so the driver pipelines them on the pool
            $Operations = [];
            for ($query = 0; $query < $queries; $query++) {
               $Operations[] = $Database->query(
                  'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
                  [mt_rand(1, 10000)]
               );
            }

            // @ Drain the whole pipeline in one cooperative wait cycle
            $Operations = $Drain($Database, $Response, $Operations);

            $Worlds = [];
            foreach ($Operations as $Operation) {
               if ($Operation->error !== null) {
                  throw new \RuntimeException($Operation->error);
               }

               $Worlds[] = $World($Operation->Result->row ?? []);
            }

            $Json($Response, json_encode($Worlds) ?: '[]', 200);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $TfbFortunes = function (Request $Request, Response $Response) use ($Connect, $Exception, $FortunesHtml, $Html, $Native) {
      return $Response->defer(function (Response $Response) use ($Connect, $Exception, $FortunesHtml, $Html, $Native): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT id, message FROM Fortune'));

            if ($Operation->error !== null) {
               throw new \RuntimeException($Operation->error);
            }

            $Html($Response, $FortunesHtml($Operation->Result->rows ?? []), 200);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $TfbUpdates = function (Request $Request, Response $Response) use ($Connect, $Drain, $Exception, $Json, $QueryCount, $UpdateWorlds, $World) {
      $queries = $QueryCount($Request);

      return $Response->defer(function (Response $Response) use ($Connect, $Drain, $Exception, $Json, $queries, $UpdateWorlds, $World): void {
         try {
            $Database = $Connect();

            // @ Issue every read first so the driver pipelines them on the pool
            $Operations = [];
            for ($query = 0; $query < $queries; $query++) {
               $Operations[] = $Database->query(
                  'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
                  [mt_rand(1, 10000)]
               );
            }

            // @ Drain the whole pipeline in one cooperative wait cycle
            $Operations = $Drain($Database, $Response, $Operations);

            $Worlds = [];
            foreach ($Operations as $Operation) {
               if ($Operation->error !== null) {
                  throw new \RuntimeException($Operation->error);
               }

               $Entry = $World($Operation->Result->row ?? []);
               $Entry['randomNumber'] = mt_rand(1, 10000);
               $Worlds[] = $Entry;
            }

            $UpdateWorlds($Database, $Response, $Worlds);

            $Json($Response, json_encode($Worlds) ?: '[]', 200);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $NativePing = function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function (Response $Response) use ($Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT 1 AS ok'));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $ResourcePing = function (Request $Request, Response $Response) use ($Exception, $Send) {
      return $Response->defer(function (Response $Response) use ($Exception, $Send): void {
         try {
            $Database = $Response->Database;
            $Operation = $Database->query('SELECT 1 AS ok');

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $NativeParameters = function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function (Response $Response) use ($Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT $1::int AS value, $2::text AS label', [42, 'bootgly']));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $ResourceParameters = function (Request $Request, Response $Response) use ($Exception, $Send) {
      return $Response->defer(function (Response $Response) use ($Exception, $Send): void {
         try {
            $Database = $Response->Database;
            $Operation = $Database->query('SELECT $1::int AS value, $2::text AS label', [42, 'bootgly']);

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $NativePool = function (Request $Request, Response $Response) use ($Connect, $Drain, $Exception, $Pool) {
      return $Response->defer(function (Response $Response) use ($Connect, $Drain, $Exception, $Pool): void {
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
   };

   $ResourcePool = function (Request $Request, Response $Response) use ($Exception, $Pool) {
      return $Response->defer(function (Response $Response) use ($Exception, $Pool): void {
         try {
            $Database = $Response->Database;
            $Connection = $Database->Database;
            $Operations = [
               $Connection->query('SELECT $1::int AS value', [1]),
               $Connection->query('SELECT $1::int AS value', [2]),
               $Connection->query('SELECT $1::int AS value', [3]),
            ];
            $Operations = $Database->drain($Operations);

            $Pool($Response, $Connection, $Operations);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $NativeSleep = function (Request $Request, Response $Response) use ($Connect, $Exception, $Native, $Send) {
      return $Response->defer(function (Response $Response) use ($Connect, $Exception, $Native, $Send): void {
         try {
            $Database = $Connect();
            $Operation = $Native($Database, $Response, $Database->query('SELECT pg_sleep(0.05), $1::int AS value', [42]));

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   $ResourceSleep = function (Request $Request, Response $Response) use ($Exception, $Send) {
      return $Response->defer(function (Response $Response) use ($Exception, $Send): void {
         try {
            $Database = $Response->Database;
            $Operation = $Database->query('SELECT pg_sleep(0.05), $1::int AS value', [42]);

            $Send($Response, $Operation);
         }
         catch (Throwable $Throwable) {
            $Exception($Response, $Throwable);
         }
      });
   };

   // ---

   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Database Benchmark');
   }, GET);

   // TechEmpower

   yield $Router->route('/db', $TfbDb, GET);
   yield $Router->route('/query', $TfbQuery, GET);
   yield $Router->route('/fortunes', $TfbFortunes, GET);
   yield $Router->route('/updates', $TfbUpdates, GET);

   // Bootgly
   yield $Router->route('/database/native/ping', $NativePing, GET);
   yield $Router->route('/database/resource/ping', $ResourcePing, GET);
   yield $Router->route('/database/runner/ping', $ResourcePing, GET);

   yield $Router->route('/database/native/parameters', $NativeParameters, GET);
   yield $Router->route('/database/resource/parameters', $ResourceParameters, GET);
   yield $Router->route('/database/runner/parameters', $ResourceParameters, GET);

   yield $Router->route('/database/native/pool', $NativePool, GET);
   yield $Router->route('/database/resource/pool', $ResourcePool, GET);
   yield $Router->route('/database/runner/pool', $ResourcePool, GET);

   yield $Router->route('/database/native/sleep', $NativeSleep, GET);
   yield $Router->route('/database/resource/sleep', $ResourceSleep, GET);
   yield $Router->route('/database/runner/sleep', $ResourceSleep, GET);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
