<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session set and get methods',

   // @ simulate
   'request' => function ($host) {
      $request = <<<HTTP
      GET / HTTP/1.1\r
      Host: {$host}\r
      \r\n
      HTTP;

      return $request;
   },
   'response' => function (Request $Request, Response $Response) {
      $Session = $Request->Session;
      $Session->set('foo', 'bar');

      return $Response->JSON->send([
         'passed' => $Session->get('foo') === 'bar'
      ]);
   },

   // @ test
   'test' => function ($response) {
      // Extract HTTP body (after headers)
      $parts = explode("\r\n\r\n", $response, 2);
      if (count($parts) < 2) {
         return false;
      }

      $body = $parts[1];
      $results = json_decode($body, true);

      if ($results === null || !isset($results['passed'])) {
         return false;
      }

      return $results['passed'] === true;
   }
];
