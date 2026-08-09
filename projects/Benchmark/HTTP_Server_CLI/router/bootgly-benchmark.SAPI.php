<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Benchmark\HTTP_Server_CLI\router;


use const GET;
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
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Benchmark\HTTP_Server_CLI\Profiler;


/*
 * Bootgly internal benchmark SAPI — used for self-comparison during
 * Bootgly development. Not part of the TechEmpower cross-framework set; the
 * scenarios that exercise these routes are tagged `@opponents: Bootgly` so
 * other opponents are skipped automatically.
 *
 * Surface:
 *   - 100 static routes (`/`, `/about`, …, `/static/{11..100}`)
 *   - 100 dynamic routes (`/d{1..100}/:param`)
 *   - 6 nested group routes (`/admin/*`, `/account/*`)
 *   - 3 middleware-protected routes (`/protected/*`)
 *   - Catch-all 404
 *   - Bootgly-specific PostgreSQL probes:
 *       `/database/{native,resource,runner}/{ping,parameters,pool,sleep}`
 *
 * The six canonical TechEmpower routes (`/plaintext`, `/json`, `/db`,
 * `/query`, `/fortunes`, `/updates`) live in `techempower-benchmark.SAPI.php`.
 */

return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // @ Per-worker profiler bootstrap (env-gated; idempotent via internal PID guard)
   if (getenv('BOOTGLY_PROFILE') === '1') {
      require_once __DIR__ . '/../Profiler.php';
      Profiler::start();
   }

   // # Database helpers (used by the Bootgly-specific probes registered at the bottom).

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

   // # Bootgly database probes — Native / Resource / Runner variants.

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

   // @ Static routes (10)
   yield $Router->serve('/', 'Home', GET);
   yield $Router->serve('/about', 'About', GET);
   yield $Router->serve('/contact', 'Contact', GET);
   yield $Router->serve('/blog', 'Blog', GET);
   yield $Router->serve('/pricing', 'Pricing', GET);
   yield $Router->serve('/docs', 'Docs', GET);
   yield $Router->serve('/faq', 'FAQ', GET);
   yield $Router->serve('/terms', 'Terms', GET);
   yield $Router->serve('/privacy', 'Privacy', GET);
   yield $Router->serve('/status', 'Status', GET);
   // @ Extra static routes (11..100)
   yield $Router->serve('/static/11', 'Static 11', GET);
   yield $Router->serve('/static/12', 'Static 12', GET);
   yield $Router->serve('/static/13', 'Static 13', GET);
   yield $Router->serve('/static/14', 'Static 14', GET);
   yield $Router->serve('/static/15', 'Static 15', GET);
   yield $Router->serve('/static/16', 'Static 16', GET);
   yield $Router->serve('/static/17', 'Static 17', GET);
   yield $Router->serve('/static/18', 'Static 18', GET);
   yield $Router->serve('/static/19', 'Static 19', GET);
   yield $Router->serve('/static/20', 'Static 20', GET);
   yield $Router->serve('/static/21', 'Static 21', GET);
   yield $Router->serve('/static/22', 'Static 22', GET);
   yield $Router->serve('/static/23', 'Static 23', GET);
   yield $Router->serve('/static/24', 'Static 24', GET);
   yield $Router->serve('/static/25', 'Static 25', GET);
   yield $Router->serve('/static/26', 'Static 26', GET);
   yield $Router->serve('/static/27', 'Static 27', GET);
   yield $Router->serve('/static/28', 'Static 28', GET);
   yield $Router->serve('/static/29', 'Static 29', GET);
   yield $Router->serve('/static/30', 'Static 30', GET);
   yield $Router->serve('/static/31', 'Static 31', GET);
   yield $Router->serve('/static/32', 'Static 32', GET);
   yield $Router->serve('/static/33', 'Static 33', GET);
   yield $Router->serve('/static/34', 'Static 34', GET);
   yield $Router->serve('/static/35', 'Static 35', GET);
   yield $Router->serve('/static/36', 'Static 36', GET);
   yield $Router->serve('/static/37', 'Static 37', GET);
   yield $Router->serve('/static/38', 'Static 38', GET);
   yield $Router->serve('/static/39', 'Static 39', GET);
   yield $Router->serve('/static/40', 'Static 40', GET);
   yield $Router->serve('/static/41', 'Static 41', GET);
   yield $Router->serve('/static/42', 'Static 42', GET);
   yield $Router->serve('/static/43', 'Static 43', GET);
   yield $Router->serve('/static/44', 'Static 44', GET);
   yield $Router->serve('/static/45', 'Static 45', GET);
   yield $Router->serve('/static/46', 'Static 46', GET);
   yield $Router->serve('/static/47', 'Static 47', GET);
   yield $Router->serve('/static/48', 'Static 48', GET);
   yield $Router->serve('/static/49', 'Static 49', GET);
   yield $Router->serve('/static/50', 'Static 50', GET);
   yield $Router->serve('/static/51', 'Static 51', GET);
   yield $Router->serve('/static/52', 'Static 52', GET);
   yield $Router->serve('/static/53', 'Static 53', GET);
   yield $Router->serve('/static/54', 'Static 54', GET);
   yield $Router->serve('/static/55', 'Static 55', GET);
   yield $Router->serve('/static/56', 'Static 56', GET);
   yield $Router->serve('/static/57', 'Static 57', GET);
   yield $Router->serve('/static/58', 'Static 58', GET);
   yield $Router->serve('/static/59', 'Static 59', GET);
   yield $Router->serve('/static/60', 'Static 60', GET);
   yield $Router->serve('/static/61', 'Static 61', GET);
   yield $Router->serve('/static/62', 'Static 62', GET);
   yield $Router->serve('/static/63', 'Static 63', GET);
   yield $Router->serve('/static/64', 'Static 64', GET);
   yield $Router->serve('/static/65', 'Static 65', GET);
   yield $Router->serve('/static/66', 'Static 66', GET);
   yield $Router->serve('/static/67', 'Static 67', GET);
   yield $Router->serve('/static/68', 'Static 68', GET);
   yield $Router->serve('/static/69', 'Static 69', GET);
   yield $Router->serve('/static/70', 'Static 70', GET);
   yield $Router->serve('/static/71', 'Static 71', GET);
   yield $Router->serve('/static/72', 'Static 72', GET);
   yield $Router->serve('/static/73', 'Static 73', GET);
   yield $Router->serve('/static/74', 'Static 74', GET);
   yield $Router->serve('/static/75', 'Static 75', GET);
   yield $Router->serve('/static/76', 'Static 76', GET);
   yield $Router->serve('/static/77', 'Static 77', GET);
   yield $Router->serve('/static/78', 'Static 78', GET);
   yield $Router->serve('/static/79', 'Static 79', GET);
   yield $Router->serve('/static/80', 'Static 80', GET);
   yield $Router->serve('/static/81', 'Static 81', GET);
   yield $Router->serve('/static/82', 'Static 82', GET);
   yield $Router->serve('/static/83', 'Static 83', GET);
   yield $Router->serve('/static/84', 'Static 84', GET);
   yield $Router->serve('/static/85', 'Static 85', GET);
   yield $Router->serve('/static/86', 'Static 86', GET);
   yield $Router->serve('/static/87', 'Static 87', GET);
   yield $Router->serve('/static/88', 'Static 88', GET);
   yield $Router->serve('/static/89', 'Static 89', GET);
   yield $Router->serve('/static/90', 'Static 90', GET);
   yield $Router->serve('/static/91', 'Static 91', GET);
   yield $Router->serve('/static/92', 'Static 92', GET);
   yield $Router->serve('/static/93', 'Static 93', GET);
   yield $Router->serve('/static/94', 'Static 94', GET);
   yield $Router->serve('/static/95', 'Static 95', GET);
   yield $Router->serve('/static/96', 'Static 96', GET);
   yield $Router->serve('/static/97', 'Static 97', GET);
   yield $Router->serve('/static/98', 'Static 98', GET);
   yield $Router->serve('/static/99', 'Static 99', GET);
   yield $Router->serve('/static/100', 'Static 100', GET);

   // @ Dynamic routes (10)
   yield $Router->route('/user/:id', function (Request $Request, Response $Response) {
      return $Response(body: 'User: ' . $this->Params->id);
   }, GET);
   yield $Router->route('/post/:slug', function (Request $Request, Response $Response) {
      return $Response(body: 'Post: ' . $this->Params->slug);
   }, GET);
   yield $Router->route('/api/v1/:resource', function (Request $Request, Response $Response) {
      return $Response(body: 'API: ' . $this->Params->resource);
   }, GET);
   yield $Router->route('/category/:name', function (Request $Request, Response $Response) {
      return $Response(body: 'Category: ' . $this->Params->name);
   }, GET);
   yield $Router->route('/tag/:label', function (Request $Request, Response $Response) {
      return $Response(body: 'Tag: ' . $this->Params->label);
   }, GET);
   yield $Router->route('/product/:sku', function (Request $Request, Response $Response) {
      return $Response(body: 'Product: ' . $this->Params->sku);
   }, GET);
   yield $Router->route('/order/:code', function (Request $Request, Response $Response) {
      return $Response(body: 'Order: ' . $this->Params->code);
   }, GET);
   yield $Router->route('/invoice/:number', function (Request $Request, Response $Response) {
      return $Response(body: 'Invoice: ' . $this->Params->number);
   }, GET);
   yield $Router->route('/review/:rid', function (Request $Request, Response $Response) {
      return $Response(body: 'Review: ' . $this->Params->rid);
   }, GET);
   yield $Router->route('/comment/:cid', function (Request $Request, Response $Response) {
      return $Response(body: 'Comment: ' . $this->Params->cid);
   }, GET);
   // @ Extra dynamic routes (11..100)
   yield $Router->route('/d11/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d12/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d13/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d14/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d15/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d16/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d17/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d18/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d19/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d20/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d21/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d22/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d23/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d24/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d25/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d26/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d27/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d28/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d29/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d30/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d31/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d32/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d33/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d34/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d35/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d36/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d37/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d38/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d39/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d40/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d41/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d42/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d43/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d44/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d45/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d46/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d47/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d48/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d49/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d50/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d51/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d52/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d53/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d54/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d55/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d56/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d57/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d58/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d59/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d60/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d61/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d62/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d63/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d64/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d65/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d66/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d67/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d68/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d69/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d70/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d71/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d72/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d73/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d74/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d75/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d76/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d77/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d78/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d79/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d80/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d81/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d82/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d83/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d84/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d85/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d86/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d87/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d88/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d89/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d90/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d91/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d92/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d93/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d94/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d95/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d96/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d97/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d98/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d99/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d100/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);

   // @ Nested routes (route groups)
   yield $Router->route('/admin/:*', function () use ($Router) {
      yield $Router->route('dashboard', function ($Request, $Response) {
         return $Response(body: 'Admin Dashboard');
      }, GET);
      yield $Router->route('settings', function ($Request, $Response) {
         return $Response(body: 'Admin Settings');
      }, GET);
      yield $Router->route('users', function ($Request, $Response) {
         return $Response(body: 'Admin Users');
      }, GET);
   }, GET);

   yield $Router->route('/account/:*', function () use ($Router) {
      yield $Router->route('profile', function ($Request, $Response) {
         return $Response(body: 'Account Profile');
      }, GET);
      yield $Router->route('billing', function ($Request, $Response) {
         return $Response(body: 'Account Billing');
      }, GET);
      yield $Router->route('security', function ($Request, $Response) {
         return $Response(body: 'Account Security');
      }, GET);
   }, GET);

   // @ Middleware routes (routes with per-route middleware)
   $requestId = new RequestId;
   yield $Router->route('/protected/dashboard', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Dashboard');
   }, GET, middlewares: [$requestId]);
   yield $Router->route('/protected/settings', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Settings');
   }, GET, middlewares: [$requestId]);
   yield $Router->route('/protected/profile', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Profile');
   }, GET, middlewares: [$requestId]);

   // @ Bootgly database probes
   yield $Router->route('/database/native/ping', $NativePing, GET);
   yield $Router->route('/database/resource/ping', $ResourcePing, GET);
   yield $Router->route('/database/runner/ping', $ResourcePing, GET);

   // @ Route response cache probe — same deferred DB handler, but the route
   //   opts in with a 1s TTL: the DB round-trip runs at most once per second
   //   per worker; every other request is served from stored wire bytes.
   yield $Router->route('/database/resource/cached', $ResourcePing, GET, cache: ['TTL' => 1]);

   yield $Router->route('/database/native/parameters', $NativeParameters, GET);
   yield $Router->route('/database/resource/parameters', $ResourceParameters, GET);
   yield $Router->route('/database/runner/parameters', $ResourceParameters, GET);

   yield $Router->route('/database/native/pool', $NativePool, GET);
   yield $Router->route('/database/resource/pool', $ResourcePool, GET);
   yield $Router->route('/database/runner/pool', $ResourcePool, GET);

   yield $Router->route('/database/native/sleep', $NativeSleep, GET);
   yield $Router->route('/database/resource/sleep', $ResourceSleep, GET);
   yield $Router->route('/database/runner/sleep', $ResourceSleep, GET);

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
