<?php

use function count;
use function str_starts_with;

use ReflectionObject;

use const Bootgly\WPI;

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — `Router::resolve()` (Bootgly/WPI/Nodes/HTTP_Server_CLI/Router.php,
 * line ~605) promotes EVERY unique URL that falls through to the catch-all
 * handler into `staticCache[''][$url]`:
 *
 *   if ($this->catchAllCache !== null) {
 *      $cached = $this->catchAllCache;
 *      $Result = ($cached['handler'])($Request, $ResponseObj);
 *
 *      // @ Promote to static cache for O(1) lookup on repeat hits
 *      if ($this->negativeCacheCount < self::MAX_NEGATIVE_CACHE) {
 *         $this->staticCache[''][$url] = $cached;
 *         $this->negativeCacheCount++;
 *      }
 *      ...
 *
 * The URL is the attacker-controlled request target. An unauthenticated
 * client can drive 10 000 unique miss URLs into `staticCache['']` —
 * entries that never get evicted for the lifetime of the worker process.
 * Memory grows unbounded up to the cap, legitimate caches fight for
 * hash-table space, and the `catchAllCache` handler is re-executed on
 * every unique miss before promotion (amplifying any expensive work the
 * handler performs).
 *
 * The PoC drives the attack in-process to avoid smashing the E2E
 * request/response loop with 10 000 round trips: the handler grabs
 * `WPI->Router`, flips `Request::$_URL` via reflection, and calls
 * `resolve()` 500 times with synthetic unique URLs. Each miss call
 * exercises the same promotion branch a remote attacker would trigger.
 *
 * After the loop, the handler reports how many of the synthetic URLs
 * landed in the shared `staticCache['']` table.
 *
 *   * vulnerable: POLLUTED=500 (or ≥ loop count minus any pre-existing
 *                 static entries), and `negativeCacheCount` reflects the
 *                 growth.
 *   * fixed    : POLLUTED=0 — the catch-all path no longer mutates the
 *                per-URL static cache.
 */

$probeCount  = 500;
$probePrefix = '/bootgly-15.01-probe-';


return new Specification(
   description: 'Router::resolve() catch-all fallback must not populate staticCache with attacker URLs',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /router-pollute HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) use ($probeCount, $probePrefix) {
      // @ Catch-all handles both the PoC trigger URL and the synthetic
      //   probe URLs — the same closure is both the attack driver and the
      //   `catchAllCache` entry that `Router::resolve()` promotes on miss.
      yield $Router->route('/*', function (Request $Request, Response $Response) use ($probeCount, $probePrefix) {
         if ($Request->URL !== '/router-pollute') {
            return $Response(code: 404, body: 'catchall');
         }

         $Router = WPI->Router;

         // @ Reflection access to private cache state and to Request::$_URL
         $rRouter = new ReflectionObject($Router);
         $propStatic = $rRouter->getProperty('staticCache');
         $propStatic->setAccessible(true);

         $rReq = new ReflectionObject($Request);
         $propUrl = $rReq->getProperty('_URL');
         $propUrl->setAccessible(true);

         // @ Drive the catch-all promotion path with unique synthetic URLs.
         //   Each call re-enters Router::resolve(), hits the catch-all
         //   fallback branch (miss), and — on a vulnerable build —
         //   promotes the synthetic URL into `staticCache['']`.
         for ($i = 0; $i < $probeCount; $i++) {
            $propUrl->setValue($Request, $probePrefix . $i);
            $Router->resolve();
         }

         // @ Restore URL so the outer response flow is sane.
         $propUrl->setValue($Request, '/router-pollute');

         /** @var array<string,array<string,mixed>> $cache */
         $cache = $propStatic->getValue($Router);
         $polluted = 0;
         foreach (($cache[''] ?? []) as $key => $_entry) {
            if (str_starts_with($key, $probePrefix)) {
               $polluted++;
            }
         }

         return $Response(
            code: 200,
            body: "POLLUTED={$polluted}"
         );
      });
   },

   test: function ($response) use ($probeCount): bool|string {
      if (! \is_string($response) || $response === '') {
         return 'No response from server.';
      }

      if (! \preg_match('/POLLUTED=(\d+)/', $response, $m)) {
         return 'Unexpected response: ' . \substr($response, 0, 300);
      }

      $polluted = (int) $m[1];

      // @ Fail if the catch-all promotion ran for ANY synthetic URL. A
      //   correct implementation must not mutate the per-URL static
      //   cache from the catch-all fallback path.
      if ($polluted > 0) {
         return 'Router negative-cache pollution: catch-all fallback '
            . 'populated staticCache with ' . $polluted . ' of ' . $probeCount
            . ' attacker-controlled URLs. Fix: remove the promotion in '
            . 'Router::resolve() catch-all branch (entries are already '
            . 'O(1) via `catchAllCache`).';
      }

      return true;
   }
);
