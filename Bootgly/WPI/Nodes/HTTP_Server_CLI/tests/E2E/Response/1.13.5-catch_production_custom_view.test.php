<?php

use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/custom HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // ! Create the project custom page at runtime — 1.13.4 asserts the
      //   built-in clean page, so this file must not exist statically
      $errors = BOOTGLY_PROJECT->path . 'views/errors';
      if (is_dir($errors) === false) {
         mkdir($errors, 0755, true);
      }
      file_put_contents(
         "$errors/500.template.php",
         "<h1>Custom project error page</h1>\n"
      );

      Catcher::$Environment = Environments::Production;

      throw new Exception('custom view probe');
   },

   test: function ($response) {
      // ! Cleanup — keep the Demo project pristine for the next run
      $errors = BOOTGLY_PROJECT->path . 'views/errors';
      @unlink("$errors/500.template.php");
      @rmdir($errors);

      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'Status 500 not found';
      }
      if (str_contains($response, 'Custom project error page') === false) {
         return 'Custom project error page not served';
      }
      if (str_contains($response, 'custom view probe')) {
         return 'Throwable message leaked in production';
      }

      return true;
   }
);
