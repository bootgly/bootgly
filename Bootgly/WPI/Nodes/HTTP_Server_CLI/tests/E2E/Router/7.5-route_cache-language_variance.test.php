<?php

use function hrtime;
use function str_contains;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Route response cache × i18n: cached wire bytes must vary by the negotiated
// locale — a pt-BR client priming the cache must never leak Portuguese bytes
// to a later en/no-header client on the same URI (and vice versa). hrtime()
// in the body proves which responses came from the cache.

return new Specification(
   description: 'It should vary route-cache entries by the negotiated locale',

   requests: [
      function () {
         return "GET /cached/i18n HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /cached/i18n HTTP/1.1\r\nHost: localhost\r\nAccept-Language: en\r\n\r\n";
      },
      function () {
         return "GET /cached/i18n HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /cached/i18n HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      yield $Router->route('/cached/i18n', function ($Request, $Response) {
         // ! Catalogs registered mid-request on the first hit — re-run the
         //   negotiation the encoder hook already performed (it saw zero
         //   roots at that point); idempotent on later misses
         Language::load(__DIR__ . '/catalogs');
         Language::$locale = Language::negotiate($Request->languages);

         $greeting = Language::translate('Hello');

         return $Response(body: "{$greeting} at=" . hrtime(true));
      }, GET, cache: ['TTL' => 60]);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3, $r4] = $responses;

      // @ Assert localized bodies per client language
      if (! str_contains($r1, 'Olá at=')) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($r1));
         return 'pt-BR request did not receive the localized body';
      }
      if (! str_contains($r2, 'Hello at=') || str_contains($r2, 'Olá')) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($r2));
         return 'en request received the pt-BR cached bytes — cache key does not vary by locale';
      }

      // @ Assert per-locale cache hits (byte-identical to the priming response)
      if ($r3 !== $r1) {
         Vars::$labels = ['Response 1:', 'Response 3:'];
         dump(json_encode($r1), json_encode($r3));
         return 'Second pt-BR request missed the pt-BR cache entry';
      }
      if ($r4 !== $r2) {
         Vars::$labels = ['Response 2:', 'Response 4:'];
         dump(json_encode($r2), json_encode($r4));
         return 'No-header request did not hit the source-locale cache entry';
      }

      return true;
   }
);
