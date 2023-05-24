<?php
$Router('/send-file', function ($Response) {
  return $Response->send(<<<HTML
  <!DOCTYPE html>
  <html lang="pt-BR">
    <head>
      <meta charset="UTF-8">
      <title>Bootgly File Upload</title>
    </head>
    <body>
      <form action="/upload/" method="post" enctype="multipart/form-data">
        <input type="file" name="test">
        <button type="submit">Enviar</button>
      </form>
    </body>
  </html>
  HTML);
}, GET);

$Router('/requesting', function ($Response, $Request) {
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
  #return $Response->send($Request->URN);           // @ foo
  // ? URL/Path
  #return $Response->send($Request->path);          // @ /test/foo
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
  #return $Response->send($Request->Header->get('accept'));
  #return $Response->send->pre->send($Request->Header->raw);
  // ? Header/Cookie
  #return $Response->Json->send($Request->cookies); // @ {...}
  #return $Response->send($Request->Cookie->test);  // @ {...}
  // ? Content
  #return $Response->send($Request->inputs);        // @ {...}
  #return $Response->send($Request->post);          // @ {...}
  #return $Response->Json->send($Request->files);   // @ [...]

  // * Meta
  #return $Response->send($Request->on);            // @ 2022-10-17
  #return $Response->send($Request->at);            // @ 12:00:00
  #return $Response->send($Request->time);           // @ 1666011216
  return $Response->Json->send($Request->range(1000, 'items=0-5'));
}, [GET, POST]);
