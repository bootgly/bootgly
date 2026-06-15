<?php

use Bootgly\ACI\Tests\Suite\Test\Specification\Separator;
use Bootgly\WPI\Interfaces\TCP_Server_CLI as Server;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Connections;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


/**
 * PoC — Audit F-2: connection-exhaustion DoS.
 *
 * `Connections::connect()` historically accepted every TCP connection with no
 * upper bound: there was no global concurrent-connection ceiling and the
 * per-IP limiter was commented out at registration. A single client could
 * therefore open connections up to the OS FD limit, exhausting per-worker FDs
 * and the per-connection Request/buffer memory of a single-threaded event-loop
 * worker — taking the process down for every client it serves.
 *
 * Fix: a global concurrency ceiling (`Server::$maxConnections`, default 10000,
 * 0 = unlimited) plus an opt-in per-IP ceiling (`Server::$maxConnectionsPerIP`,
 * default 0 = unlimited; off by default because reverse proxies collapse every
 * client onto one source IP). Both live in one pure predicate
 * `Connections::check($ip)` (true = admit, mirroring `Connection::check()`),
 * consulted once per accept by `connect()` — never on the per-request hot path
 * — so throughput on established connections is unchanged.
 *
 * This PoC drives that real predicate (the exact code `connect()` calls) with
 * controlled state — deterministic, no socket race. The live high-concurrency
 * non-regression check is the benchmark (514 concurrent connections from one IP
 * are served because the default ceiling, 10000, is well above them; a ceiling
 * below 514 would shed them).
 *
 * Before the fix, `Server::$maxConnections` and `Connections::check()` do not
 * exist, so this spec errors out — exactly the "no ceiling" state.
 */
return new Specification(
   description: 'Connections::check() must enforce a global (and opt-in per-IP) concurrency ceiling',
   Separator: new Separator(line: true),

   request: function (): string {
      return "GET /f2-probe HTTP/1.1\r\nHost: localhost\r\nConnection: close\r\n\r\n";
   },

   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/f2-probe', function (Request $Request, Response $Response) {
         return $Response(code: 200, body: 'F2-PROBE-OK');
      });
      yield $Router->route('/*', function (Request $Request, Response $Response) {
         return $Response(code: 404, body: 'Not Found');
      });
   },

   test: function ($response): bool|string {
      // @ Snapshot the shipped defaults (both have class defaults → safe read).
      $savedMax   = Server::$maxConnections;
      $savedPerIP = Server::$maxConnectionsPerIP;

      $fail = null;
      try {
         // # Global ceiling (per-IP knob off so check() reflects only it).
         //   `$Connections` is set before every read, so an uninitialized typed
         //   static in this driver process is never touched.
         Server::$maxConnectionsPerIP = 0;
         Connections::$ipConnections  = [];

         Server::$maxConnections = 3;

         Connections::$Connections = [1 => true, 2 => true, 3 => true]; // 3 live
         if (Connections::check('1.2.3.4') !== false) {
            $fail = 'Global ceiling: check() must shed (false) at count == max (3 >= 3).';
         }

         Connections::$Connections = [1 => true, 2 => true];            // 2 live
         if ($fail === null && Connections::check('1.2.3.4') !== true) {
            $fail = 'Global ceiling: check() must admit (true) below max (2 < 3).';
         }

         Server::$maxConnections = 0;                                   // unlimited
         Connections::$Connections = [1 => true, 2 => true, 3 => true, 4 => true];
         if ($fail === null && Connections::check('1.2.3.4') !== true) {
            $fail = 'Global ceiling: check() must admit when max == 0 (unlimited).';
         }

         // # Per-IP ceiling (opt-in; global off so check() reflects only it).
         Server::$maxConnections = 0;
         Connections::$Connections = [];

         Server::$maxConnectionsPerIP = 2;
         Connections::$ipConnections  = ['1.2.3.4' => 2];
         if ($fail === null && Connections::check('1.2.3.4') !== false) {
            $fail = 'Per-IP ceiling: check() must shed (false) at this peer\'s count == max.';
         }
         if ($fail === null && Connections::check('9.9.9.9') !== true) {
            $fail = 'Per-IP ceiling: check() must admit a peer with no connections.';
         }

         Server::$maxConnectionsPerIP = 0;                              // proxy-safe default
         if ($fail === null && Connections::check('1.2.3.4') !== true) {
            $fail = 'Per-IP ceiling: check() must admit when the knob is 0 (off by default).';
         }

         // # Shipped global default must be a real, finite ceiling.
         if ($fail === null && $savedMax <= 0) {
            $fail = 'Default Server::$maxConnections must be a finite protective ceiling (> 0); got '
               . $savedMax . '. A connection flood would otherwise be unbounded by default.';
         }
      }
      finally {
         // @ Restore config; reset the connection tables to a clean empty state
         //   (the driver process does not serve real connections).
         Server::$maxConnections      = $savedMax;
         Server::$maxConnectionsPerIP = $savedPerIP;
         Connections::$Connections    = [];
         Connections::$ipConnections  = [];
      }

      return $fail ?? true;
   }
);
