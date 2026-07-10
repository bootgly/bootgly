<?php

use function str_contains;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// q=0 exclusions must survive parsing and be enforced by the automatic
// per-request negotiation (RFC 9110 §12.4.2: q=0 means "not acceptable"):
// a wildcard must route around a refused source, an all-refusing header is
// disregarded (source served), and a refused region wins over its parent.

return new Specification(
   requests: [
      function () {
         return "GET /i18n/negotiated/load HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /i18n/negotiated HTTP/1.1\r\nHost: localhost\r\nAccept-Language: *;q=0.5, en;q=0\r\n\r\n";
      },
      function () {
         return "GET /i18n/negotiated HTTP/1.1\r\nHost: localhost\r\nAccept-Language: en;q=0\r\n\r\n";
      },
      function () {
         return "GET /i18n/negotiated HTTP/1.1\r\nHost: localhost\r\nAccept-Language: *;q=0\r\n\r\n";
      },
      function () {
         return "GET /i18n/negotiated HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR;q=0, pt;q=0.8\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! Prime — the encoder hook negotiates BEFORE routing, so the roots
      //   must exist before the exclusion requests arrive
      yield $Router->route('/i18n/negotiated/load', function (Request $Request, Response $Response) {
         Language::load(__DIR__ . '/catalogs');

         return $Response(body: 'loaded');
      }, GET);

      // ! Echoes the automatically negotiated locale (offers: en + pt-BR)
      yield $Router->route('/i18n/negotiated', function (Request $Request, Response $Response) {
         return $Response(body: "locale={" . Language::$locale . "}");
      }, GET);
   },

   test: function (array $responses) {
      [$loaded, $wildcardRefusedSource, $refusedSource, $refusedAll, $refusedRegion] = $responses;

      // @ Prime ran
      if (! str_contains($loaded, 'loaded')) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($loaded));
         return 'Catalog prime request did not run';
      }

      // @ `*;q=0.5, en;q=0` — the wildcard must route around the refused source
      if (! str_contains($wildcardRefusedSource, 'locale={pt-BR}')) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($wildcardRefusedSource));
         return 'Wildcard selection did not honor the explicit source exclusion';
      }

      // @ `en;q=0` — nothing acceptable remains: header disregarded, source served
      if (! str_contains($refusedSource, 'locale={en}')) {
         Vars::$labels = ['Response 3:'];
         dump(json_encode($refusedSource));
         return 'All-refused negotiation did not fall back to the source';
      }

      // @ `*;q=0` — every offer refused: header disregarded, source served
      if (! str_contains($refusedAll, 'locale={en}')) {
         Vars::$labels = ['Response 4:'];
         dump(json_encode($refusedAll));
         return 'Wildcard refusal did not fall back to the source';
      }

      // @ `pt-BR;q=0, pt;q=0.8` — the refused region must not be selected
      //   through its accepted parent (specificity: pt-BR > pt)
      if (! str_contains($refusedRegion, 'locale={en}')) {
         Vars::$labels = ['Response 5:'];
         dump(json_encode($refusedRegion));
         return 'A refused region was selected through its accepted parent range';
      }

      return true;
   }
);
