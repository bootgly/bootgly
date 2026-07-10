<?php

use Bootgly\ABI\Data\Language;
use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/localized HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\nAccept-Language: pt-BR\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // ! Catalogs registered mid-request — re-run the negotiation the
      //   encoder hook already performed (it saw zero roots at that point)
      Language::load(__DIR__ . '/catalogs');
      Language::$locale = Language::negotiate($Request->languages);

      Catcher::$Environment = Environments::Production;

      throw new Exception('localized secret probe');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'English status line not preserved on the wire';
      }
      if (str_contains($response, 'lang="pt-BR"') === false) {
         return 'Localized lang attribute not found';
      }
      if (str_contains($response, 'Erro interno do servidor</p>') === false) {
         return 'Localized status message not found in the clean page';
      }
      if (str_contains($response, 'Vary: Accept, Accept-Language') === false) {
         return 'Localized error page did not declare Vary: Accept, Accept-Language';
      }
      if (str_contains($response, 'localized secret probe')) {
         return 'Throwable message leaked in production';
      }

      return true;
   }
);
