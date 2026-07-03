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


use const ENT_QUOTES;
use const GET;
use function asort;
use function ctype_digit;
use function getenv;
use function htmlspecialchars;
use function implode;
use function is_array;
use function json_encode;
use function max;
use function min;
use function mt_rand;
use function strlen;
use function strpos;
use function substr;
use Generator;
use RuntimeException;
use Throwable;

use Bootgly\ABI\Resources\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use projects\HTTP_Server_CLI\Profiler;


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
 *   GET /cached-queries → N random CachedWorld rows from an in-memory cache (?count=N, 1..500)
 *
 * Database access goes through the canonical `$Response->Database` response
 * resource (registered in the project bootstrap with `Database::provide()`,
 * configured by `configs/database/database.config.php` + `DB_*` env vars) —
 * the same code API documented for users.
 *
 * Bootgly-specific stress routes (catch-all, nested, middleware, the
 * `/database/native/*` probes, etc.) live in `bootgly-benchmark.SAPI.php` —
 * they are not part of TechEmpower and would skew a feature-to-feature
 * comparison.
 */

$Cached = static function (): Cache {
   static $Cache = null;

   // ?: One in-process Memory cache per worker — primed lazily from CachedWorld
   if ($Cache instanceof Cache) {
      return $Cache;
   }

   $Cache = new Cache(['driver' => 'memory', 'prefix' => 'tfb:']);

   return $Cache;
};

// ---

$Exception = static function (Response $Response, Throwable $Throwable): object {
   return $Response(code: 500, body: $Throwable->getMessage());
};

// ---

$Count = static function (Request $Request, string $name): int {
   // ! Raw query scan first — the benchmark hits the fixed `?<name>=N` form
   $query = $Request->query;
   $prefix = "{$name}=";
   $length = strlen($prefix);

   $start = false;

   if (substr($query, 0, $length) === $prefix) {
      $start = $length;
   }
   else {
      $offset = strpos($query, "&{$prefix}");

      if ($offset !== false) {
         $start = $offset + $length + 1;
      }
   }

   if ($start !== false) {
      $end = strpos($query, '&', $start);
      $value = $end === false
         ? substr($query, $start)
         : substr($query, $start, $end - $start);

      if ($value !== '' && ctype_digit($value)) {
         return max(1, min(500, (int) $value));
      }
   }

   // ? Fallback to the parsed query map (arrays / odd encodings)
   $value = $Request->queries[$name] ?? 1;

   if (is_array($value)) {
      $value = $value[0] ?? 1;
   }

   // : Missing / non-integer / < 1 → 1; > 500 → 500 (TFB clamp)
   return max(1, min(500, (int) $value));
};

$World = static function (array $row): array {
   return [
      'id' => (int) ($row['id'] ?? 0),
      'randomNumber' => (int) ($row['randomNumber'] ?? $row['randomnumber'] ?? 0),
   ];
};

$UpdateWorlds = static function (Database $Database, array $Worlds): void {
   // ! Batch as PostgreSQL array literals — one prepared statement for every
   //   batch size N (the CASE/IN form emitted one statement text per N).
   $ids = [];
   $values = [];

   foreach ($Worlds as $World) {
      $ids[] = $World['id'];
      $values[] = $World['randomNumber'];
   }

   // @ Lock the target rows in ascending id order BEFORE updating: concurrent
   //   batches then acquire their row locks in the same global order, which
   //   removes the lock-order cycles (PostgreSQL deadlocks) the unordered
   //   batched UPDATE hit at high worker counts.
   $Database->fetch(
      'UPDATE World SET randomNumber = data.new'
      . ' FROM (SELECT d.id, d.new FROM unnest($1::integer[], $2::integer[]) AS d(id, new)'
      . ' JOIN World w ON w.id = d.id ORDER BY d.id FOR UPDATE OF w) AS data'
      . ' WHERE World.id = data.id',
      ['{' . implode(',', $ids) . '}', '{' . implode(',', $values) . '}']
   );
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
   // @ Dedicated plain-text resource: sets the default media type (text/plain)
   //   instead of a header field, so build() keeps its empty-fields fast path and
   //   the Raw wire-cache stays valid — no CRLF/RFC-9110 regex, no header array.
   return $Response->Plaintext->send(body: 'Hello, World!');
};

$JsonHello = function (Request $Request, Response $Response) {
   // @ JSON resource: sets the default media type (application/json) via Header->type,
   //   keeping build()'s fast path + the Raw wire-cache — no per-request header array.
   return $Response->JSON->send('{"message":"Hello, World!"}');
};

$TfbDb = function (Request $Request, Response $Response) use ($Exception, $World) {
   return $Response->defer(function (Response $Response) use ($Exception, $World): void {
      try {
         // @ fetch() awaits the operation on the response scheduler and throws on error
         $Result = $Response->Database->fetch(
            'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
            [mt_rand(1, 10000)]
         );

         // : JSON resource keeps build()'s fast path (media type, no header array)
         $Response->JSON->send(json_encode($World($Result->row)) ?: '{}');
      }
      catch (Throwable $Throwable) {
         $Exception($Response, $Throwable);
      }
   });
};

$TfbQuery = function (Request $Request, Response $Response) use ($Count, $Exception, $World) {
   $queries = $Count($Request, 'queries');

   return $Response->defer(function (Response $Response) use ($Exception, $queries, $World): void {
      try {
         $Database = $Response->Database;

         // @ Issue every query first — through the wrapped SQL, so nothing awaits
         //   yet and the driver pipelines them on the pool
         $Operations = [];
         for ($query = 0; $query < $queries; $query++) {
            $Operations[] = $Database->Database->query(
               'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
               [mt_rand(1, 10000)]
            );
         }

         // @ Drain the whole pipeline in one cooperative wait cycle
         $Operations = $Database->drain($Operations);

         $Worlds = [];
         foreach ($Operations as $Operation) {
            if ($Operation->error !== null) {
               throw new RuntimeException($Operation->error);
            }

            $Worlds[] = $World($Operation->Result->row ?? []);
         }

         // : JSON resource keeps build()'s fast path (media type, no header array)
         $Response->JSON->send(json_encode($Worlds) ?: '[]');
      }
      catch (Throwable $Throwable) {
         $Exception($Response, $Throwable);
      }
   });
};

$TfbFortunes = function (Request $Request, Response $Response) use ($Exception, $FortunesHtml) {
   return $Response->defer(function (Response $Response) use ($Exception, $FortunesHtml): void {
      try {
         $Result = $Response->Database->fetch('SELECT id, message FROM Fortune');

         $Response(body: $FortunesHtml($Result->rows), code: 200);
      }
      catch (Throwable $Throwable) {
         $Exception($Response, $Throwable);
      }
   });
};

$TfbUpdates = function (Request $Request, Response $Response) use ($Count, $Exception, $UpdateWorlds, $World) {
   $queries = $Count($Request, 'queries');

   return $Response->defer(function (Response $Response) use ($Exception, $queries, $UpdateWorlds, $World): void {
      try {
         $Database = $Response->Database;

         // @ Issue every read first — through the wrapped SQL, so nothing awaits
         //   yet and the driver pipelines them on the pool
         $Operations = [];
         for ($query = 0; $query < $queries; $query++) {
            $Operations[] = $Database->Database->query(
               'SELECT id, randomNumber AS "randomNumber" FROM World WHERE id = $1',
               [mt_rand(1, 10000)]
            );
         }

         // @ Drain the whole pipeline in one cooperative wait cycle
         $Operations = $Database->drain($Operations);

         $Worlds = [];
         foreach ($Operations as $Operation) {
            if ($Operation->error !== null) {
               throw new RuntimeException($Operation->error);
            }

            $Entry = $World($Operation->Result->row ?? []);
            $Entry['randomNumber'] = mt_rand(1, 10000);
            $Worlds[] = $Entry;
         }

         $UpdateWorlds($Database, $Worlds);

         // : JSON resource keeps build()'s fast path (media type, no header array)
         $Response->JSON->send(json_encode($Worlds) ?: '[]');
      }
      catch (Throwable $Throwable) {
         $Exception($Response, $Throwable);
      }
   });
};

$Pick = static function (Cache $Cache, int $count): string {
   // @ One cache read returns the whole pool (TFB allows a single cache op);
   //   pick N rows at random from it — in-process, no DB round-trip
   $Pool = $Cache->fetch('worlds');
   if (is_array($Pool) === false) {
      $Pool = [];
   }

   $Worlds = [];
   for ($query = 0; $query < $count; $query++) {
      $Worlds[] = $Pool[mt_rand(1, 10000)] ?? null;
   }

   return json_encode($Worlds) ?: '[]';
};

$TfbCached = function (Request $Request, Response $Response) use ($Cached, $Count, $Exception, $Pick, $World) {
   $count = $Count($Request, 'count');
   $Cache = $Cached();
   $primed = $Cache->check('primed');

   // ?: Fast path — cache already primed: respond synchronously, no event-loop defer.
   //   JSON->send keeps build()'s fast path (media type, no per-request header array).
   if ($primed === true) {
      return $Response->JSON->send($Pick($Cache, $count));
   }

   // ? Cold worker — prime once from CachedWorld (async DB), then serve from memory
   return $Response->defer(function (Response $Response) use ($Cache, $Exception, $Pick, $World, $count): void {
      try {
         if ($Cache->check('primed') === false) {
            $Result = $Response->Database->fetch(
               'SELECT id, randomNumber AS "randomNumber" FROM CachedWorld'
            );

            $Pool = [];
            foreach ($Result->rows as $row) {
               $Entry = $World($row);
               $Pool[$Entry['id']] = $Entry;
            }

            $Cache->store('worlds', $Pool);
            $Cache->store('primed', true);
         }

         $Response->JSON->send($Pick($Cache, $count));
      }
      catch (Throwable $Throwable) {
         $Exception($Response, $Throwable);
      }
   });
};


return static function
(Request $Request, Response $Response, Router $Router)
use ($Plaintext, $JsonHello, $TfbDb, $TfbQuery, $TfbFortunes, $TfbUpdates, $TfbCached): Generator
{
   // @ Per-worker profiler bootstrap (env-gated; idempotent via internal PID guard)
   if (getenv('BOOTGLY_PROFILE') === '1') {
      require_once __DIR__ . '/../Profiler.php';
      Profiler::start();
   }

   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'TechEmpower Benchmark');
   }, GET);

   yield $Router->route('/plaintext', $Plaintext, GET);
   yield $Router->route('/json',      $JsonHello, GET);

   yield $Router->route('/db',             $TfbDb,        GET);
   yield $Router->route('/query',          $TfbQuery,     GET);
   yield $Router->route('/fortunes',       $TfbFortunes,  GET);
   yield $Router->route('/updates',        $TfbUpdates,   GET);
   yield $Router->route('/cached-queries', $TfbCached,    GET);

   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
