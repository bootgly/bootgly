<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session put method (bulk)',

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
      $Session->put([
         'key1' => 'val1',
         'key2' => 'val2'
      ]);

      return $Response->JSON->send([
         'key1' => $Session->get('key1') === 'val1',
         'key2' => $Session->get('key2') === 'val2'
      ]);
   },

   // @ test
   'test' => function ($response) {
      $body = substr($response, strpos($response, "\r\n\r\n") + 4);
      $results = json_decode($body, true);

      return ($results['key1'] ?? false) && ($results['key2'] ?? false);
   }
];
