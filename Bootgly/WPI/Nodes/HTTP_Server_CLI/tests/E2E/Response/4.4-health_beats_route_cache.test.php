<?php

use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI as Server;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// The probe-proof guarantee must also beat the route response cache: an
// application-cached entry on the health path may never shadow the built-in
// responder. Request 1 warms an app route on `/health-vs-cache` (and then points the
// built-in health at that same path); request 2 must get `Encoders\Check`,
// not the cached app bytes; request 3 restores the suite health path.

return new Specification(
   description: 'It should serve the built-in health before a cached application route on the same path',

   requests: [
      function () {
         return "GET /health-vs-cache HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /health-vs-cache HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /health-vs-cache-restore HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router) {
      yield $Router->route('/health-vs-cache', function ($Request, $Response) {
         // ! Point the built-in health at this very path AFTER the app
         //   response is produced (and cached) — the next request races the
         //   stale cache entry against the probe guard
         Server::$health = '/health-vs-cache';

         return $Response(body: 'app-probe');
      }, GET, cache: ['TTL' => 60]);

      yield $Router->route('/health-vs-cache-restore', function ($Request, $Response) {
         // ! Restore the suite-wide health path
         Server::$health = '/health';

         return $Response(body: 'restored');
      }, GET);
   },

   test: function (array $responses) {
      [$app, $probe, $restored] = $responses;

      // @ Assert — cold request is the application route (cache warms up)
      if (str_contains($app, 'app-probe') === false) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($app));
         return 'Application route did not answer the cold request';
      }

      // @ Assert — the built-in probe wins over the warm cache entry
      if (
         str_contains($probe, '{"status":"ok"}') === false
         || str_contains($probe, "Cache-Control: no-store\r\n") === false
         || str_contains($probe, 'app-probe')
      ) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($probe));
         return 'Cached application bytes shadowed the built-in health';
      }

      // @ Assert — suite health path restored
      if (str_contains($restored, 'restored') === false) {
         Vars::$labels = ['Response 3:'];
         dump(json_encode($restored));
         return 'Health path restore request failed';
      }

      return true;
   }
);
