<?php

namespace projects\Bootgly\WPI;


use function count;
use function dirname;
use function json_encode;
use Throwable;

use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Config as ADIConfig;
use Bootgly\ADI\Databases\SQL\Operation;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\DatabaseConfig;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Runner;


return static function
(Request $Request, Response $Response, Router $Router)
{
   $Send = static function (Response $Response, array $payload, int $code): object {
      $body = json_encode($payload) ?: 'JSON encoding failed.';

      return $Response(code: $code, body: $body);
   };

   $Describe = static function (Response $Response) use ($Send): null|ADIConfig {
      $Configs = new Configs(dirname(__DIR__) . '/configs/');
      $Configs->allow('database', [
         'DB_CONNECTION',
         'DB_ENABLED',
         'DB_HOST',
         'DB_NAME',
         'DB_PASS',
         'DB_POOL_MAX',
         'DB_POOL_MIN',
         'DB_PORT',
         'DB_SSLCAFILE',
         'DB_SSLMODE',
         'DB_SSLPEER',
         'DB_SSLVERIFY',
         'DB_STATEMENTS',
         'DB_TIMEOUT',
         'DB_USER',
      ]);
      $Scope = $Configs->get('database');

      // @phpstan-ignore-next-line
      if ($Scope instanceof Config === false || $Scope->Enabled->get() !== true) {
         $Send($Response, [
            'status' => 'not-configured',
            'message' => 'Enable DB_ENABLED=true in the database config scope and set DB_HOST, DB_PORT, DB_NAME, DB_USER and DB_PASS as needed.',
         ], 503);

         return null;
      }

      return (new DatabaseConfig($Scope))->configure();
   };

   $Connect = static function (Response $Response) use ($Describe): null|SQL {
      static $Database = null;

      if ($Database instanceof SQL) {
         return $Database;
      }

      $Config = $Describe($Response);

      if ($Config === null) {
         return null;
      }

      $Database = new SQL($Config);

      return $Database;
   };

   $Execute = static function (SQL $Database, Response $Response, string $sql, array $parameters, int $limit): null|Operation {
      if ($limit <= 0) {
         return null;
      }

      $Runner = new Runner($Database, $Response);

      return $Runner->query($sql, $parameters);
   };

   $Rows = static function (Operation $Operation): array {
      $Result = $Operation->Result;

      return $Result === null ? [] : $Result->rows;
   };

   $Query = static function (Response $Response, string $sql, array $parameters, callable $Render, int $limit) use ($Connect, $Execute, $Send): mixed {
      return $Response->defer(function () use ($Response, $sql, $parameters, $Render, $limit, $Connect, $Execute, $Send): void {
         try {
            $Database = $Connect($Response);

            if ($Database === null) {
               return;
            }

            $Operation = $Execute($Database, $Response, $sql, $parameters, $limit);

            if ($Operation === null) {
               return;
            }

            $Render($Operation);
         }
         catch (Throwable $Throwable) {
            $Send($Response, [
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ], 500);
         }
      });
   };

   $Respond = static function (Response $Response, Operation $Operation, array $rows, null|string $hint = null) use ($Send): void {
      $Send($Response, [
         'status' => $Operation->error === null ? 'ok' : 'error',
         'message' => $Operation->error,
         'hint' => $Operation->error === null ? null : $hint,
         'rows' => $rows,
      ], $Operation->error === null ? 200 : 500);
   };

   // @ Database demo index
   yield $Router->route('/', function (Request $Request, Response $Response) use ($Send) {
      return $Send($Response, [
         'status' => 'ok',
         'routes' => [
            '/deferred/database/setup',
            '/deferred/database/users',
            '/deferred/database/user',
            '/deferred/database/config',
            '/deferred/database/ping',
            '/deferred/database/parameters',
            '/deferred/database/types',
            '/deferred/database/error',
            '/deferred/database/pool',
            '/deferred/database/sleep',
         ],
      ], 200);
   }, GET);

   // @ PostgreSQL effective config
   yield $Router->route('/deferred/database/config', function (Request $Request, Response $Response) use ($Describe, $Send) {
      return $Response->defer(function () use ($Response, $Describe, $Send): void {
         $Config = $Describe($Response);

         if ($Config === null) {
            return;
         }

         $Send($Response, [
            'status' => 'ok',
            'driver' => $Config->driver,
            'host' => $Config->host,
            'port' => $Config->port,
            'database' => $Config->database,
            'username' => $Config->username,
            'password' => $Config->password === '' ? 'empty' : 'set',
            'timeout' => $Config->timeout,
            'statements' => $Config->statements,
            'secure' => $Config->secure,
            'pool' => $Config->pool,
         ], 200);
      });
   }, GET);

   // @ Base PostgreSQL async query
   yield $Router->route('/deferred/database', function (Request $Request, Response $Response) use ($Query, $Send) {
      return $Query($Response, 'SELECT $1::int AS value', [42], function (Operation $Operation) use ($Response, $Send): void {
         if ($Operation->error !== null) {
            $Send($Response, [
               'status' => 'error',
               'message' => $Operation->error,
            ], 500);

            return;
         }

         $Result = $Operation->Result;
         $Send($Response, [
            'status' => $Result === null ? 'empty' : $Result->status,
            'rows' => $Result === null ? [] : $Result->rows,
            'affected' => $Result === null ? 0 : $Result->affected,
         ], 200);
      }, 1000);
   }, GET);

   // @ PostgreSQL connection ping
   yield $Router->route('/deferred/database/ping', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT 1 AS ok', [], function (Operation $Operation) use ($Response, $Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      }, 1000);
   }, GET);

   // @ PostgreSQL parameterized query
   yield $Router->route('/deferred/database/parameters', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT $1::int AS value, $2::text AS label', [42, 'bootgly'], function (Operation $Operation) use ($Response, $Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      }, 1000);
   }, GET);

   // @ PostgreSQL scalar type conversion demo
   yield $Router->route('/deferred/database/types', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT $1::bool AS enabled, $2::int AS count, $3::float8 AS ratio, $4::numeric AS amount', [true, 7, 1.5, '123.45'], function (Operation $Operation) use ($Response, $Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      }, 1000);
   }, GET);

   // @ PostgreSQL demo schema and seed data
   yield $Router->route('/deferred/database/setup', function (Request $Request, Response $Response) use ($Connect, $Execute, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Execute, $Send): void {
         try {
            $Database = $Connect($Response);

            if ($Database === null) {
               return;
            }

            $Statements = [
               'CREATE TABLE IF NOT EXISTS bootgly_demo_users (id integer PRIMARY KEY, name text NOT NULL, email text NOT NULL UNIQUE, active boolean NOT NULL DEFAULT true, score numeric(10,2) NOT NULL DEFAULT 0, created_at timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP)',
               "INSERT INTO bootgly_demo_users (id, name, email, active, score) VALUES (1, 'Ada Lovelace', 'ada@example.test', true, 98.50), (2, 'Grace Hopper', 'grace@example.test', true, 96.75), (3, 'Alan Turing', 'alan@example.test', false, 99.99) ON CONFLICT (id) DO UPDATE SET name = EXCLUDED.name, email = EXCLUDED.email, active = EXCLUDED.active, score = EXCLUDED.score",
            ];
            $results = [];

            foreach ($Statements as $sql) {
               $Operation = $Execute($Database, $Response, $sql, [], 1000);

               if ($Operation === null) {
                  return;
               }

               if ($Operation->error !== null) {
                  $Send($Response, [
                     'status' => 'error',
                     'message' => $Operation->error,
                  ], 500);

                  return;
               }

               $results[] = $Operation->Result === null ? 'ok' : $Operation->Result->status;
            }

            $Send($Response, [
               'status' => 'ok',
               'message' => 'Demo table bootgly_demo_users is ready.',
               'commands' => $results,
            ], 200);
         }
         catch (Throwable $Throwable) {
            $Send($Response, [
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ], 500);
         }
      });
   }, GET);

   // @ PostgreSQL demo users list
   yield $Router->route('/deferred/database/users', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query(
         $Response,
         'SELECT id, name, email, active, score, created_at::text AS created_at FROM bootgly_demo_users ORDER BY id',
         [],
         function (Operation $Operation) use ($Response, $Respond, $Rows): void {
            $Respond($Response, $Operation, $Rows($Operation), 'Call /deferred/database/setup first.');
         },
         1000
      );
   }, GET);

   // @ PostgreSQL demo user by parameter
   yield $Router->route('/deferred/database/user', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query(
         $Response,
         'SELECT id, name, email, active, score, created_at::text AS created_at FROM bootgly_demo_users WHERE id = $1::int',
         [1],
         function (Operation $Operation) use ($Response, $Respond, $Rows): void {
            $Respond($Response, $Operation, $Rows($Operation), 'Call /deferred/database/setup first.');
         },
         1000
      );
   }, GET);

   // @ PostgreSQL recoverable error demo
   yield $Router->route('/deferred/database/error', function (Request $Request, Response $Response) use ($Query, $Send) {
      return $Query($Response, 'SELECT missing_column FROM missing_table', [], function (Operation $Operation) use ($Response, $Send): void {
         $Send($Response, [
            'status' => $Operation->error === null ? 'unexpected-ok' : 'expected-error',
            'message' => $Operation->error,
         ], $Operation->error === null ? 200 : 500);
      }, 1000);
   }, GET);

   // @ PostgreSQL pool demo with multiple operations
   yield $Router->route('/deferred/database/pool', function (Request $Request, Response $Response) use ($Connect, $Send) {
      return $Response->defer(function () use ($Response, $Connect, $Send): void {
         try {
            $Database = $Connect($Response);

            if ($Database === null) {
               return;
            }

            $Operations = [
               $Database->query('SELECT $1::int AS value', [1]),
               $Database->query('SELECT $1::int AS value', [2]),
               $Database->query('SELECT $1::int AS value', [3]),
            ];
            $steps = 0;

            while ($steps < 1000) {
               $steps++;
               $waiting = null;
               $finished = true;

               foreach ($Operations as $Operation) {
                  if ($Operation->finished) {
                     continue;
                  }

                  $finished = false;
                  $Database->advance($Operation);
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

            $Send($Response, [
               'status' => $errors === [] ? 'ok' : 'error',
               'rows' => $rows,
               'errors' => $errors,
               'pool' => [
                  'idle' => count($Database->Pool->idle),
                  'busy' => count($Database->Pool->busy),
                  'pending' => count($Database->Pool->pending),
                  'created' => $Database->Pool->created,
               ],
            ], $errors === [] ? 200 : 500);
         }
         catch (Throwable $Throwable) {
            $Send($Response, [
               'status' => 'exception',
               'message' => $Throwable->getMessage(),
            ], 500);
         }
      });
   }, GET);

   // @ PostgreSQL slow query demo for non-blocking checks
   yield $Router->route('/deferred/database/sleep', function (Request $Request, Response $Response) use ($Query, $Respond, $Rows) {
      return $Query($Response, 'SELECT pg_sleep(2), $1::int AS value', [42], function (Operation $Operation) use ($Response, $Respond, $Rows): void {
         $Respond($Response, $Operation, $Rows($Operation), null);
      }, 5000);
   }, GET);

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
