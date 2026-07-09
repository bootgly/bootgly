<?php

use Bootgly\API\Environments;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Encoders\Catcher;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Tests\Suite\Test\Specification;


return new Specification(
   request: function () {
      return "GET /errors/json HTTP/1.1\r\nHost: localhost\r\nAccept: application/json\r\n\r\n";
   },
   response: function (Request $Request, Response $Response): Response {
      Catcher::$Environment = Environments::Development;

      throw new RuntimeException('json error probe');
   },

   test: function ($response) {
      // @ Assert
      if (str_contains($response, 'HTTP/1.1 500 Internal Server Error') === false) {
         return 'Status 500 not found';
      }
      if (str_contains($response, 'Content-Type: application/json') === false) {
         return 'JSON Content-Type not found';
      }

      [, $body] = explode("\r\n\r\n", $response, 2);
      $decoded = json_decode($body, true);
      if (is_array($decoded) === false) {
         return 'Body is not valid JSON';
      }
      if (($decoded['error'] ?? '') !== 'RuntimeException') {
         return 'JSON `error` key not matched';
      }
      if (($decoded['message'] ?? '') !== 'json error probe') {
         return 'JSON `message` key not matched';
      }
      if (isset($decoded['file'], $decoded['line'], $decoded['trace']) === false) {
         return 'JSON file/line/trace keys missing';
      }

      return true;
   }
);
