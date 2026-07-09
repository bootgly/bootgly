<?php

use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/debug HTTP/1.1\r\nHost: localhost\r\nAccept: text/html\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      // ! One-shot: consumed by Catcher::respond() in this request only
      Catcher::$Environment = Environments::Development;

      throw new Exception('debug page probe <tag>');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'Status 500 not found';
      }
      if (str_contains($response, 'Content-Type: text/html') === false) {
         return 'HTML Content-Type not found';
      }
      if (str_contains($response, '<!DOCTYPE html>') === false) {
         return 'Debug page document not found';
      }
      if (str_contains($response, 'debug page probe &lt;tag&gt;') === false) {
         return 'Escaped throwable message not found';
      }
      if (str_contains($response, 'debug page probe <tag>')) {
         return 'Raw (unescaped) throwable message leaked';
      }
      if (str_contains($response, 'id="context"') === false) {
         return 'Request context section not found';
      }
      if (str_contains($response, '/errors/debug') === false) {
         return 'Request URI not found in the context';
      }

      return true;
   }
);
