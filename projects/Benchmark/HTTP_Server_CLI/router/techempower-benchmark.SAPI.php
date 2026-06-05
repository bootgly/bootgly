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
use function ctype_digit;
use function getenv;
use function htmlspecialchars;
use function implode;
use function is_array;
use function is_numeric;
use function json_encode;
use function max;
use function min;
use function mt_rand;
use function strpos;
use function strtolower;
use function substr;
use Generator;
use Throwable;

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


/*
 * TechEmpower benchmark SAPI — fair, cross-framework comparison surface.
 *
 * Serves the six canonical TechEmpower routes:
 *   GET /plaintext  →  text/plain "Hello, World!"
 *   GET /json       →  application/json {"message":"Hello, World!"}
 *   GET /db         →  one random World row as JSON
 *   GET /query      →  N random World rows as JSON (?queries=N, 1..500)
 *   GET /fortunes   →  Fortune list rendered as HTML
 *   GET /updates    →  N World rows fetched, updated, and returned (?queries=N)
 *
 * Bootgly-specific stress routes (catch-all, nested, middleware, the
 * `/database/native/*` probes, etc.) live in `bootgly-benchmark.SAPI.php` —
 * they are not part of TechEmpower and would skew a feature-to-feature
 * comparison.
 */

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

   // ---

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

   $Exception = static function (Response $Response, Throwable $Throwable): object {
      return $Response(code: 500, body: $Throwable->getMessage());
   };

   // ---

   $QueryCount = static function (Request $Request): int {
      $query = $Request->query;

      if ($query === 'queries=20') {
         return 20;
      }

      $start = false;

      if (substr($query, 0, 8) === 'queries=') {
         $start = 8;
      }
      else {
         $offset = strpos($query, '&queries=');

         if ($offset !== false) {
            $start = $offset + 9;
         }
      }

      if ($start !== false) {
         $end = strpos($query, '&', $start);
         $queries = $end === false
            ? substr($query, $start)
            : substr($query, $start, $end - $start);

         if ($queries !== '' && ctype_digit($queries)) {
            return max(1, min(500, (int) $queries));
         }
      }

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

   // # TechEmpower handlers

   $Plaintext = function (Request $Request, Response $Response) {
      return $Response(
         headers: ['Content-Type' => 'text/plain'],
         body: 'Hello, World!'
      );
   };

   $JsonHello = function (Request $Request, Response $Response) {
      return $Response(
         headers: ['Content-Type' => 'application/json'],
         body: '{"message":"Hello, World!"}'
      );
   };

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

   // ---

   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'TechEmpower Benchmark');
   }, GET);

   yield $Router->route('/plaintext', $Plaintext, GET);
   yield $Router->route('/json',      $JsonHello, GET);

   yield $Router->route('/db',        $TfbDb,        GET);
   yield $Router->route('/query',     $TfbQuery,     GET);
   yield $Router->route('/fortunes',  $TfbFortunes,  GET);
   yield $Router->route('/updates',   $TfbUpdates,   GET);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
