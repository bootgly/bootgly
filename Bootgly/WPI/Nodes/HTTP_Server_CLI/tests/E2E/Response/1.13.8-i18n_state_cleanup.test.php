<?php

use Bootgly\ABI\Data\Language;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


// Drops the worker-global catalog roots registered by 1.13.6 so every later
// spec (byte-exact Router/Sequential assertions) runs without i18n state —
// and therefore without the automatic Vary: Accept-Language field.

return new Specification(
   request: function () {
      return "GET /i18n/cleanup HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      Language::reset();

      return $Response(body: 'clean');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'clean') === false) {
         return 'i18n cleanup did not run';
      }
      if (str_contains($response, 'Vary: Accept-Language')) {
         return 'Vary emitted after Language::reset() — roots were not dropped';
      }

      return true;
   }
);
