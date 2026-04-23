<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTPS_Client_CLI;


use function implode;
use function is_array;
use function strlen;
use function str_contains;
use function substr;
use function parse_url;

use const Bootgly\CLI;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return new Project(
   // # Project Metadata
   name: 'Demo HTTPS Client CLI',
   description: 'Demonstration project for Bootgly HTTPS Client CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $Output = CLI->Terminal->Output;
      $Output->render('@.;@#cyan:━━━ Bootgly HTTPS Client CLI Demo ━━━@;@..;');

      if (!isset($options['URL'])) {
         $Output->render('@#red:Error:@; The --URL=<url> option is required.@..;');
         return;
      }

      $url = (string) $options['URL'];
      if (!str_contains($url, '://')) {
         $url = 'https://' . $url;
      }

      $parsedUrl = parse_url($url);
      $host = $parsedUrl['host'] ?? '127.0.0.1';
      $port = $parsedUrl['port'] ?? ($parsedUrl['scheme'] === 'http' ? 80 : 443);
      $uri  = $parsedUrl['path'] ?? '/';
      if (isset($parsedUrl['query'])) {
         $uri .= '?' . $parsedUrl['query'];
      }

      $local = ($host === 'localhost' || $host === '127.0.0.1');
      if ($local) {
         // @ Connect to local HTTPS server (self-signed cert)
         $host = '127.0.0.1';
         $secure = [
            'verify_peer' => false,
            'allow_self_signed' => true,
         ];
         $Output->render('@#yellow:Mode:@; Local (self-signed cert, verify_peer=false)@.;');
      }
      else {
         // @ Connect to public HTTPS server
         $secure = [
            'verify_peer' => true,
            'verify_peer_name' => true,
         ];
         $Output->render('@#yellow:Mode:@; Public (verify_peer=true)@.;');
      }
      $Output->render('@#yellow:Target:@; ' . $host . ':' . $port . $uri);

      // @ Create HTTPS Client
      $Client = new HTTP_Client_CLI;
      $Client->configure(
         host: $host,
         port: $port,
         workers: 0,
         secure: $secure
      );

      // @ Register HTTP hooks
      $Client->on(
         // on Worker instance
         workerStarted: function ($Client) use ($uri) {
            // @ Prepare a GET request
            $Client->request('GET', $uri);

            $Socket = $Client->connect();
            if ($Socket) {
               $Client::$Event->loop();
            }
         },
         // on Connection connect
         clientConnect: function ($Socket, $Connection) use ($Output) {
            $Output->render('@#green:✓ TLS connection established@;@.;');
         },
         // on Connection disconnect
         clientDisconnect: function ($Connection) use ($Output) {
            $Output->render('@.;@#yellow:■ Connection closed@;@.;');
         },
         // @ on HTTP Response received
         responseReceive: function (Request $Request, Response $Response) use ($Output, $Client) {
            $Output->render('@.;@#white:--- Response ---@;@.;');
            $Output->render('@#green:Protocol:@; ' . $Response->protocol . '@.;');
            $Output->render('@#green:Status:@;   ' . $Response->code . ' ' . $Response->status . '@.;');

            // @ Display response headers
            $Output->render('@#white:--- Headers ---@;@.;');
            foreach ($Response->headers as $name => $value) {
               if (is_array($value)) {
                  $value = implode(', ', $value);
               }
               $Output->render("@#cyan:{$name}:@; {$value}@.;");
            }

            // @ Display response body (truncated)
            $body = $Response->body;
            if ($body !== '') {
               $Output->render('@#white:--- Body ---@;@.;');
               if (strlen($body) > 500) {
                  $Output->render(substr($body, 0, 500) . '...' . '@.;');
               } else {
                  $Output->render($body . '@.;');
               }
            }

            $Output->render('@.;@#green:✓ HTTPS request completed@;@..;');

            // @ Stop the event loop after first response (demo is one-shot)
            $Client::$Event->loop = false;
         }
      );

      // @ Start the client
      $Client->start();
   }
);
