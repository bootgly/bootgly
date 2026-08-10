<?php


use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test;


// Automatic Vary: Accept-Language must be TOKEN-aware (RFC 9110 §12.5.5):
// a superstring field (X-Accept-Language-Experiment) must not suppress the
// real token, a lowercase existing token must not duplicate, and a `*`
// wildcard already covers every request field.

return new Test(
   requests: [
      function () {
         return "GET /vary/custom HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/lower HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/star HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/prepared HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/queued HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/preset HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /vary/preset-clear HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! Idempotent — catalogs registered by 1.13.6 are normally still
      //   loaded; guarantee them anyway so this spec is self-sufficient
      Language::load(__DIR__ . '/catalogs');

      yield $Router->route('/vary/custom', function (Request $Request, Response $Response) {
         $Response->Header->set('Vary', 'X-Accept-Language-Experiment');

         return $Response(body: 'custom');
      }, GET);

      yield $Router->route('/vary/lower', function (Request $Request, Response $Response) {
         $Response->Header->set('Vary', 'accept-language');

         return $Response(body: 'lower');
      }, GET);

      yield $Router->route('/vary/star', function (Request $Request, Response $Response) {
         $Response->Header->set('Vary', '*');

         return $Response(body: 'star');
      }, GET);

      // ! RES-8 — a Vary declared through prepare()/queue()/preset() must
      //   MERGE with the automatic Accept-Language token, never collide with
      //   it (the collision silently discarded one of the two declarations)
      yield $Router->route('/vary/prepared', function (Request $Request, Response $Response) {
         return $Response(200, ['Vary' => 'Accept-Encoding'], 'prepared');
      }, GET);

      yield $Router->route('/vary/queued', function (Request $Request, Response $Response) {
         $Response->Header->queue('Vary', 'X-Tenant');

         return $Response(body: 'queued');
      }, GET);

      yield $Router->route('/vary/preset', function (Request $Request, Response $Response) {
         $Response->Header->preset('Vary', 'X-Region');

         return $Response(body: 'preset');
      }, GET);

      yield $Router->route('/vary/preset-clear', function (Request $Request, Response $Response) {
         // ! Presets are worker-persistent — remove the one the previous
         //   request planted, and assert the automatic token recovers
         $Response->Header->preset('Vary', null);

         return $Response(body: 'cleared');
      }, GET);
   },

   test: function (array $responses) {
      [$custom, $lower, $star, $prepared, $queued, $preset, $cleared] = $responses;

      // @ Superstring token must not satisfy the Accept-Language check
      if (! str_contains($custom, "Vary: X-Accept-Language-Experiment, Accept-Language\r\n")) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($custom));
         return 'A superstring Vary token suppressed the real Accept-Language token';
      }

      // @ Case-insensitive existing token must not duplicate
      if (! str_contains($lower, "Vary: accept-language\r\n")) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($lower));
         return 'A lowercase existing token was duplicated (or dropped)';
      }

      // @ Wildcard already covers every request field
      if (! str_contains($star, "Vary: *\r\n") || str_contains($star, 'Accept-Language')) {
         Vars::$labels = ['Response 3:'];
         dump(json_encode($star));
         return 'Vary: * received a redundant Accept-Language token';
      }

      // @ RES-8 — a prepared Vary must keep the app dimension AND the token
      if (
         ! str_contains($prepared, "Vary: Accept-Encoding, Accept-Language\r\n")
         || substr_count($prepared, 'Vary:') !== 1
      ) {
         Vars::$labels = ['Response 4:'];
         dump(json_encode($prepared));
         return 'A prepared Vary did not merge with the automatic Accept-Language token';
      }

      // @ RES-8 — a queued Vary must not erase the automatic token
      if (
         ! str_contains($queued, "Vary: X-Tenant, Accept-Language\r\n")
         || substr_count($queued, 'Vary:') !== 1
      ) {
         Vars::$labels = ['Response 5:'];
         dump(json_encode($queued));
         return 'A queued Vary erased (or split from) the automatic Accept-Language token';
      }

      // @ RES-8 — a preset Vary must not erase the automatic token
      if (
         ! str_contains($preset, "Vary: X-Region, Accept-Language\r\n")
         || substr_count($preset, 'Vary:') !== 1
      ) {
         Vars::$labels = ['Response 6:'];
         dump(json_encode($preset));
         return 'A preset Vary erased (or split from) the automatic Accept-Language token';
      }

      // @ Removing the preset restores the plain automatic declaration
      if (
         ! str_contains($cleared, "Vary: Accept-Language\r\n")
         || substr_count($cleared, 'Vary:') !== 1
      ) {
         Vars::$labels = ['Response 7:'];
         dump(json_encode($cleared));
         return 'Removing the preset Vary did not restore the plain automatic declaration';
      }

      return true;
   }
);
