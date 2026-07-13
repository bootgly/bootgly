<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Demo\HTTP2_HTTP_Server_CLI;


use function getenv;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP/2 - HTTP Server CLI',
   description: 'HTTP/2 (RFC 9113) demonstration + compliance target (h2spec) for Bootgly HTTP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8090,
         workers: getenv('WORKERS') ? (int) getenv('WORKERS') : 1
         // enableHTTP2: true (default) — h2c prior knowledge is served with
         // zero setup; pass `secure:` to also negotiate h2 over TLS-ALPN.
      );
      $HTTP_Server_CLI
         ->on(Events::RequestReceived, function ($Request, Response $Response): Response {
            return $Response->send(<<<BODY
            Bootgly HTTP/2 demo
            protocol: {$Request->protocol}
            stream: {$Request->stream}
            method: {$Request->method}
            uri: {$Request->URI}
            BODY);
         })
         ->on(Events::ServerStarted, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
            $port = $HTTP_Server_CLI->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTP/2 demo started@;@.;');
            $Output->render("  Listening on @#cyan:http://{$host}:{$port}@; (h2c prior knowledge + HTTP/1.1)@.;");
            $Output->render("  Try: @#yellow:curl --http2-prior-knowledge http://127.0.0.1:{$port}/@;@.;");
            $Output->render("  Compliance: @#yellow:h2spec -h 127.0.0.1 -p {$port}@;@..;");
         });

      $HTTP_Server_CLI->start();
   }
);
