<?php
use Bootgly\WPI\Nodes\HTTP_Server_\Request;
use Bootgly\WPI\Nodes\HTTP_Server_\Response;


$Router->route('/request_metadata', function (Request $Request, Response $Response) {
  $Request_Data = [
    'address' => $Request->address,
    'port' => $Request->port,
    'scheme' => $Request->scheme
  ];

  return $Response->JSON->send($Request_Data);
}, GET);

$Router->route('/request_raw', function (Request $Request, Response $Response) {
  return $Response->send($Request->raw);
}, GET);

$Router->route('/request_meta', function (Request $Request, Response $Response) {
    $Request_Meta = [
    'method' => $Request->method,
    'protocol' => $Request->protocol,
    'URI' => $Request->URI
  ];

  return $Response->JSON->send($Request_Meta);
}, GET);

$Router->route('/requesting', function (Request $Request, Response $Response) {
  // * Data
  /*
  $Response->append($Request->ip);                  // @ 123.10.20.30
  $Response->append($Request->port);                // @ 57123

  $Response->append($Request->scheme);              // @ https
  $Response->append($Request->host);                // @ bootgly.slayer.tech
  $Response->append($Request->domain);              // @ slayer.tech
  $Response->append($Request->subdomain);           // @ bootgly
  return $Response->Pre->send();
  */


  // ! Resource
  #return $Response->send($Request->URI);           // @ /test/foo/?query1=123&query2=xyz
  #return $Response->send($Request->URL);           // @ /test/foo
  // ? URI/Query
  #return $Response->send($Request->query);         // @ query1=abc&query2=xyz
  #return $Response->Json->send($Request->queries); // @ {"query1":"abc", "query2":"xyz"}


  // ! HTTP
  #return $Response->send($Request->protocol);      // @ HTTP/1.1
  #return $Response->send($Request->method);        // @ GET
  #return $Response->send($Request->language);        // @ pt-BR
  #return $Response->send($Request->user);          // @ boot
  #return $Response->send($Request->password);      // @ singly
  #return $Response->pre->send($Request->raw);      // @ "..."
  // ? Header
  #return $Response->Json->send($Request->headers, JSON_PRETTY_PRINT);
  #return $Response->send($Request->Raw->Header->get('accept'));
  #return $Response->send->pre->send($Request->Raw->Header->raw);
  // ? Header/Cookie
  #return $Response->Json->send($Request->cookies); // @ {...}
  #return $Response->send($Request->Cookie->test);  // @ {...}
  // ? Body
  #return $Response->send($Request->inputs);        // @ {...}
  #return $Response->send($Request->post);          // @ {...}
  #return $Response->Json->send($Request->files);   // @ [...]

  // * Metadata
  #return $Response->send($Request->on);            // @ 2022-10-17
  #return $Response->send($Request->at);            // @ 12:00:00
  #return $Response->send($Request->time);           // @ 1666011216
  return $Response->Json->send($Request->range(1000, 'items=0-5'));
}, [GET, POST]);
