<?php

use function str_contains;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Automatic Vary: Accept-Language must be TOKEN-aware (RFC 9110 §12.5.5):
// a superstring field (X-Accept-Language-Experiment) must not suppress the
// real token, a lowercase existing token must not duplicate, and a `*`
// wildcard already covers every request field.

return new Specification(
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
   },

   test: function (array $responses) {
      [$custom, $lower, $star] = $responses;

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

      return true;
   }
);
