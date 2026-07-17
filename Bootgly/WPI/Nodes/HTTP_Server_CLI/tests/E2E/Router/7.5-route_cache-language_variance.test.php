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
      function () {
         return "GET /cached/i18n HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /cached/i18n-reset HTTP/1.1\r\nHost: localhost\r\n\r\n";
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

      // ! Cleanup route — drops the worker-global catalog roots so later
      //   specs run without i18n state (and without the automatic Vary)
      yield $Router->route('/cached/i18n-reset', function ($Request, $Response) {
         Language::reset();

         return $Response(body: 'reset');
      }, GET);
   },

   test: function (array $responses) {
      [$r1, $r2, $r3, $r4, $r5, $r6] = $responses;

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
      // ! `Vary: Accept-Language` keys the exact request field value, not
      //   merely the negotiated locale. An absent field therefore has its own
      //   representation even though both absent and `en` select the source
      //   catalog. The second absent-field request must hit that exact entry.
      if ($r4 === $r2 || $r5 !== $r4) {
         Vars::$labels = ['Response 2:', 'Response 4:', 'Response 5:'];
         dump(json_encode($r2), json_encode($r4), json_encode($r5));
         return 'Accept-Language request-field variants were not isolated and replayed exactly';
      }
      if (! str_contains($r4, 'Hello at=') || str_contains($r4, 'Olá')) {
         Vars::$labels = ['Response 4:'];
         dump(json_encode($r4));
         return 'No-header source-locale variant did not contain the English representation';
      }

      // @ Assert external cache variance — localized responses (cold AND
      //   cached wire) must declare the exact Vary token (substring matches
      //   like X-Accept-Language-Experiment must not satisfy this)
      foreach ([$r1, $r2, $r3, $r4, $r5] as $index => $response) {
         if (! str_contains($response, "Vary: Accept-Language\r\n")) {
            Vars::$labels = ['Response ' . ($index + 1) . ':'];
            dump(json_encode($response));
            return 'Localized response did not emit Vary: Accept-Language';
         }
      }

      // @ Cleanup ran — later specs see zero catalog roots
      if (! str_contains($r6, 'reset')) {
         Vars::$labels = ['Response 6:'];
         dump(json_encode($r6));
         return 'i18n cleanup route did not run';
      }

      return true;
   }
);
