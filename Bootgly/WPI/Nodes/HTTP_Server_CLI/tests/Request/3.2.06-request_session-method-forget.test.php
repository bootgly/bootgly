<?php

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;

return [
   // @ configure
   'describe' => 'It should test Session forget method (bulk)',

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
      $Session->set('key1', 'val1');
      $Session->set('key2', 'val2');
      $Session->forget(['key1', 'key2']);

      return $Response->JSON->send([
         'key1_gone' => $Session->has('key1') === false,
         'key2_gone' => $Session->has('key2') === false
      ]);
   },

   // @ test
   'test' => function ($response) {
      $body = substr($response, strpos($response, "\r\n\r\n") + 4);
      $results = json_decode($body, true);

      return ($results['key1_gone'] ?? false) && ($results['key2_gone'] ?? false);
   }
];
