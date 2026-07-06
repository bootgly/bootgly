<?php

use function str_contains;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   description: 'A default layout wraps a view that declares no @extends',

   request: function () {
      return "GET / HTTP/1.1\r\nHost: localhost\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // `plain` has no @extends — the configured default layout wraps it, its
      //   loose output becoming the layout's `content` section. Reset the
      //   persistent View layout afterwards so later specs stay unaffected.
      $Response->View->layout = 'layouts/main';
      try {
         return $Response->View->render('plain', ['unsafe' => '<b>']);
      }
      finally {
         $Response->View->layout = '';
      }
   },

   test: function ($response) {
      if (str_contains($response, '200 OK') === false) {
         return "Status is not 200 OK: \n" . $response;
      }
      // content = loose "Plain body", footer include = F, $unsafe escaped by @>>
      if (str_contains($response, '<l>Plain body|F|&lt;b&gt;</l>') === false) {
         return "Default-layout-wrapped body not matched: \n" . $response;
      }

      return true;
   }
);
