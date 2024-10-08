<?php
use Bootgly\WPI\Modules\HTTP\Server\Router;

use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;

/** @var Router $Router */

$Router->route('/Request/test.a', function (Request $Request, Response $Response) {
   return $Response->JSON->send([
      'address' => $Request->address,
      'port' => $Request->port,
      'scheme' => $Request->scheme
   ]);
}, GET);

$Router->route('/Request/test.b', function (Request $Request, Response $Response) {
   return $Response->send($Request->raw);
}, GET);

$Router->route('/Request/test.c', function (Request $Request, Response $Response) {
   return $Response->JSON->send([
      'method' => $Request->method,
      'URI' => $Request->URI,
      'protocol' => $Request->protocol
   ]);
}, GET);

$Router->route('/Request/test.d', function (Request $Request, Response $Response) {
   return $Response->JSON->send([
      'URI' => $Request->URI,
      'URL' => $Request->URL,
      'URN' => $Request->URN
   ]);
}, GET);

$Router->route('/Request/test.e', function (Request $Request, Response $Response) {
   return $Response->send($Request->query);
}, GET);

$Router->route('/Request/test.f', function (Request $Request, Response $Response) {
   return $Response->JSON->send($Request->queries);
}, GET);

$Router->route('/Request/test.g', function (Request $Request, Response $Response) {
   return $Response->JSON->send($Request->headers);
}, GET);

$Router->route('/Request/test.h', function (Request $Request, Response $Response) {
   return $Response->JSON->send([
      $Request->host,

      $Request->domain,
      $Request->subdomain,
      $Request->subdomains
   ]);
}, GET);

$Router->route('/Request/test.i', function (Request $Request, Response $Response) {
   return $Response->JSON->send(
      $Request->cookies
   );
}, GET);

$Router->route('/Request/test.j', function (Request $Request, Response $Response) {
   return $Response->send(
      $Request->input
   );
});
$Router->route('/Request/test.k', function (Request $Request, Response $Response) {
   return $Response->JSON->send(
      $Request->inputs
   );
});

$Router->route('/Request/test.l', function (Request $Request, Response $Response) {
   return $Response->JSON->send(
      $Request->post
   );
});

$Router->route('/Request/test.m', function (Request $Request, Response $Response) {
   return $Response->JSON->send(
      $Request->files
   );
});

$Router->route('/Request/test.x', function (Request $Request, Response $Response) {
   return $Response->JSON->send([
      $Request->username,
      $Request->password,
   ]);
}, GET);

$Router->route('/Request/test.y', function (Request $Request, Response $Response) {
   return $Response->JSON->send(
      [
         'types' => $Request->negotiate(with: $Request::ACCEPTS_TYPES),
         'languages' => $Request->negotiate(with: $Request::ACCEPTS_LANGUAGES),
         'charsets' => $Request->negotiate(with: $Request::ACCEPTS_CHARSETS),
         'encodings' => $Request->negotiate(with: $Request::ACCEPTS_ENCODINGS)
      ]
   );
}, GET);

$Router->route('/Request/test.z', function (Request $Request, Response $Response) {
   $Response->Header->set('Last-Modified', 'Fri, 14 Jul 2023 08:00:00 GMT');

   if ($Request->fresh) {
      return $Response(code: 304); // First onward is here
   } else {
      return $Response->send('test'); // First Response here
   }
}, GET);

$Router->route('/requesting', function (Request $Request, Response $Response) {
   // * Data
   /*
  $Response->append($Request->address);             // @ 123.10.20.30
  $Response->append($Request->port);                // @ 57123

  $Response->append($Request->scheme);              // @ https
  $Response->append($Request->host);                // @ docs.bootgly.com
  $Response->append($Request->domain);              // @ bootgly.com
  $Response->append($Request->subdomain);           // @ docs
  return $Response->Pre->send();
  */

   return $Response->JSON->send(
      [
         'input'  => $Request->input,
         'inputs' => $Request->inputs,
         'post'   => $_POST
      ]
   );

   // ! Resource
   #return $Response->send($Request->URI);           // @ /test/foo/?query1=123&query2=xyz
   #return $Response->send($Request->URL);           // @ /test/foo
   // ? URI/Query
   #return $Response->send($Request->query);         // @ query1=abc&query2=xyz
   #return $Response->JSON->send($Request->queries); // @ {"query1":"abc", "query2":"xyz"}


   // ! HTTP
   #return $Response->send($Request->protocol);      // @ HTTP/1.1
   #return $Response->send($Request->method);        // @ GET
   #return $Response->send($Request->language);        // @ pt-BR
   #return $Response->send($Request->user);          // @ boot
   #return $Response->send($Request->password);      // @ singly
   #return $Response->Pre->send($Request->raw);      // @ "..."
   // ? Header
   #return $Response->JSON->send($Request->headers, JSON_PRETTY_PRINT);
   #return $Response->send($Request->Header->get('accept'));
   #return $Response->send->Pre->send($Request->Header->raw);
   // ? Header/Cookie
   #return $Response->JSON->send($Request->cookies); // @ {...}
   #return $Response->send($Request->Cookies->test);  // @ {...}
   // ? Body
   #return $Response->send($Request->inputs);        // @ {...}
   #return $Response->send($Request->post);          // @ {...}
   #return $Response->JSON->send($Request->files);   // @ [...]

   // * Metadata
   #return $Response->send($Request->on);            // @ 2022-10-17
   #return $Response->send($Request->at);            // @ 12:00:00
   #return $Response->send($Request->time);           // @ 1666011216
   #return $Response->JSON->send($Request->range(1000, 'items=0-5'));

   // return $Response;
}, [GET, POST]);
