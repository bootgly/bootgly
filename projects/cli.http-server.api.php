<?php
use Bootgly\Bootgly;

use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;


Bootgly::$Project->vendor = '@bootgly/';
Bootgly::$Project->container = 'web/';
Bootgly::$Project->package = 'examples/';
Bootgly::$Project->version = 'app/';
Bootgly::$Project->setPath();


return static function
(Request $Request, Response $Response) : Response
{
   // ! Request examples
   // ? Meta (first line of HTTP Request Header)
   #$Request->method;    // GET
   #$Request->uri;       // /path/to?query1=value2...
   #$Request->protocol;  // HTTP/1.1
   // ? Header
   #$host = $Request->Header->get('Host');
   // ? Content
   // @ download
   // Form-data ($_POST, $_FILES)
   #$files = $Request->download('file1'); // $_FILES and $Request->files available too
   // @ receive
   // Raw - JSON, URL Encoded, Text, etc.
   #$Request->receive();

   #debug($_POST, $_FILES, $Request->input); // $Request->input ↔ file_get_contents('php://input')


   // ! Response examples
   // ? Meta (first line of HTTP Response Header)
   #return $Response->send(302); // 302 Not Found

   // ? Header
   #$Response->Header->set('Content-Type', 'text/plain');

   // Cookies
   #$Response->Header->Cookie->append('Test', 'value1');
   #$Response->Header->Cookie->append('Test2', 'value2');

   // ? Content
   // @ output
   // raw ?
   /*
   return $Response(raw: <<<HTTP_RAW
   HTTP/1.1 200 OK
   Server: Bootgly
   Content-Type: text/plain; charset=UTF-8
   Content-Length: 12

   Hello World!
   HTTP_RAW);
   */
   // content
   return $Response(content: 'Hello World!'); // text

   // @ send
   #return $Response->Json->send(['Hello' => 'World!']); // JSON

   // @ upload
   // Small files
   #return $Response('statics/image1.jpg')->upload();
   #return $Response('statics/alphanumeric.txt')->upload(offset: 0, length: 2);
   // Medium files
   #return $Response('statics/screenshot.gif')->upload();

   // @ authenticate
   #return $Response->authenticate(realm: 'Protected area');

   // @ redirect
   #return $Response->redirect(uri: 'https://docs.bootgly.com/', code: 302);
};
