<?php

use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   // ! No Accept-Language — the catalogs registered by 1.13.6 are still
   //   loaded in this persistent worker; the per-request encoder hook must
   //   have reset the locale back to the source (no leak from pt-BR)
   request: function () {
      return "GET /errors/reset HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      Catcher::$Environment = Environments::Production;

      throw new Exception('reset secret probe');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'Status 500 not found';
      }
      if (str_contains($response, 'lang="en"') === false) {
         return 'Locale did not reset to the source language';
      }
      if (str_contains($response, 'Internal Server Error</p>') === false) {
         return 'English status message not restored after the pt-BR request';
      }
      if (str_contains($response, 'Erro interno do servidor')) {
         return 'pt-BR locale leaked into a request without Accept-Language';
      }

      return true;
   }
);
