<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'Render a view composing @extends + @include + @>> (escaped output)',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // views/inherited.template.php extends layouts/main, which includes
      // partials/footer and escapes $unsafe via @>>
      return $Response->View->render('inherited', ['unsafe' => '<b>']);
   },

   test: function ($response) {
      // @ Assert status
      if (str_contains($response, '200 OK') === false) {
         return "Status is not 200 OK: \n" . $response;
      }

      // @ Assert composed body: section + include + escaped output
      if (str_contains($response, '<l>C|F|&lt;b&gt;</l>') === false) {
         return "Composed view body not matched: \n" . $response;
      }

      return true;
   }
);
