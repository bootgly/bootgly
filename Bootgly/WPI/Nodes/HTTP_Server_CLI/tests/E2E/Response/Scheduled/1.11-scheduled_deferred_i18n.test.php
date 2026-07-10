<?php

use function str_contains;

use Bootgly\ABI\Data\Language;
use Bootgly\ABI\Debugging\Data\Vars;
use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Deferred work × i18n at the WPI boundary: the locale captured by defer()
// must survive a global-locale overwrite while the Fiber is suspended
// (interleaved request), the deferred Catcher must localize under the bound
// locale, and a pooled Fiber reused by a later request must not inherit the
// previous job's binding. Self-contained: primes catalogs first, resets last.

return new Specification(
   requests: [
      function () {
         return "GET /deferred/i18n/load HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /deferred/i18n HTTP/1.1\r\nHost: localhost\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /deferred/i18n HTTP/1.1\r\nHost: localhost\r\nAccept-Language: en\r\n\r\n";
      },
      function () {
         return "GET /deferred/i18n/throw HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\nAccept-Language: pt-BR\r\n\r\n";
      },
      function () {
         return "GET /deferred/i18n HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
      function () {
         return "GET /deferred/i18n/reset HTTP/1.1\r\nHost: localhost\r\n\r\n";
      },
   ],
   response: function (Request $Request, Response $Response, Router $Router)
   {
      // ! Prime — registers the catalog roots so the encoder hook negotiates
      //   Accept-Language on every later request of this spec
      yield $Router->route('/deferred/i18n/load', function (Request $Request, Response $Response) {
         Language::load(__DIR__ . '/../catalogs');

         return $Response(body: 'loaded');
      }, GET);

      yield $Router->route('/deferred/i18n', function (Request $Request, Response $Response) {
         return $Response->defer(function (Response $Response) {
            // @ Suspend — while parked, another request may (re)negotiate
            $Response->wait();

            // ! Simulate an interleaved request overwriting the process
            //   default while this job was suspended — the Fiber binding
            //   made by defer()/loop() must win over it
            Language::$locale = 'en';

            $Response(body: Language::translate('Hello'));
         });
      }, GET);

      yield $Router->route('/deferred/i18n/throw', function (Request $Request, Response $Response) {
         Catcher::$Environment = Environments::Production;

         return $Response->defer(function (Response $Response) {
            $Response->wait();

            throw new Exception('deferred secret probe');
         });
      }, GET);

      yield $Router->route('/deferred/i18n/reset', function (Request $Request, Response $Response) {
         Language::reset();

         return $Response(body: 'reset');
      }, GET);
   },

   test: function (array $responses) {
      [$loaded, $ptBR, $en, $thrown, $bare, $reset] = $responses;

      // @ Prime ran
      if (! str_contains($loaded, 'loaded')) {
         Vars::$labels = ['Response 1:'];
         dump(json_encode($loaded));
         return 'Catalog prime request did not run';
      }

      // @ Deferred pt-BR — binding beats the overwritten global locale
      if (! str_contains($ptBR, 'Olá')) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($ptBR));
         return 'Deferred pt-BR job lost its locale to the interleaved overwrite';
      }
      if (! str_contains($ptBR, "Vary: Accept-Language\r\n")) {
         Vars::$labels = ['Response 2:'];
         dump(json_encode($ptBR));
         return 'Deferred response did not declare Vary: Accept-Language';
      }

      // @ Deferred en — source keys pass through verbatim
      if (! str_contains($en, 'Hello') || str_contains($en, 'Olá')) {
         Vars::$labels = ['Response 3:'];
         dump(json_encode($en));
         return 'Deferred en job translated under another request\'s locale';
      }

      // @ Deferred exception — the Catcher localizes under the bound locale
      if (! str_contains($thrown, 'HTTP/1.1 500 Internal Server Error')) {
         Vars::$labels = ['Response 4:'];
         dump(json_encode($thrown));
         return 'Deferred exception did not produce a 500';
      }
      if (! str_contains($thrown, 'lang="pt-BR"') || ! str_contains($thrown, 'Erro interno do servidor')) {
         Vars::$labels = ['Response 4:'];
         dump(json_encode($thrown));
         return 'Deferred Catcher did not localize under the Fiber-bound locale';
      }
      if (str_contains($thrown, 'deferred secret probe')) {
         return 'Throwable message leaked in production';
      }

      // @ Pooled Fiber reuse — no binding (nor locale) inherited from job 2/4
      if (! str_contains($bare, 'Hello') || str_contains($bare, 'Olá')) {
         Vars::$labels = ['Response 5:'];
         dump(json_encode($bare));
         return 'Pooled Fiber leaked the previous job\'s locale binding';
      }

      // @ Cleanup — later specs run without i18n state
      if (! str_contains($reset, 'reset') || str_contains($reset, 'Vary: Accept-Language')) {
         Vars::$labels = ['Response 6:'];
         dump(json_encode($reset));
         return 'i18n state cleanup did not run';
      }

      return true;
   }
);
