<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — HTTPS Client CLI Demo
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 *
 * This demo connects to an HTTPS server and sends a GET request over TLS.
 *
 * Usage (against public HTTPS server):
 *
 *   bootgly project Demo-HTTPS_Client_CLI start
 *
 * Usage (against local HTTPS server — start it first):
 *
 *   bootgly project Demo-HTTPS_Server_CLI start
 *   bootgly project Demo-HTTPS_Client_CLI start --local
 *
 * --------------------------------------------------------------------------
 */

namespace projects\Demo\HTTPS_Client_CLI;


use function getenv;
use function in_array;
use function implode;
use function is_array;
use function strlen;
use function substr;

use const Bootgly\CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return static function (array $options = []): void
{
   $Output = CLI->Terminal->Output;
   $Output->render('@.;@#cyan:━━━ Bootgly HTTPS Client CLI Demo ━━━@;@..;');

   $local = in_array('local', $options, true) || isset($options['local']);

   if ($local) {
      // @ Connect to local HTTPS server (self-signed cert)
      $host = '127.0.0.1';
      $port = getenv('PORT') ? (int) getenv('PORT') : 443;
      $ssl = [
         'verify_peer'       => false,
         'allow_self_signed' => true,
      ];
      $uri = '/';
      $Output->render('@#yellow:Mode:@; Local (self-signed cert, verify_peer=false)@.;');
   }
   else {
      // @ Connect to public HTTPS server
      $host = 'httpbin.org';
      $port = 443;
      $ssl = [
         'verify_peer'      => true,
         'verify_peer_name' => true,
      ];
      $uri = '/get';
      $Output->render('@#yellow:Mode:@; Public (httpbin.org, verify_peer=true)@.;');
   }

   $Output->render('@#yellow:Target:@; ' . $host . ':' . $port . $uri . '@..;');

   // @ Create HTTPS Client
   $Client = new HTTP_Client_CLI;
   $Client->configure(
      host: $host,
      port: $port,
      workers: 0,
      ssl: $ssl
   );

   // @ Register HTTP hooks
   $Client->on(
      // on Worker instance
      instance: function ($Client) use ($uri) {
         // @ Prepare a GET request
         $Client->request('GET', $uri);

         $Socket = $Client->connect();
         if ($Socket) {
            $Client::$Event->loop();
         }
      },
      // on Connection connect
      connect: function ($Socket, $Connection) use ($Output) {
         $Output->render('@#green:✓ TLS connection established@;@.;');
      },
      // on Connection disconnect
      disconnect: function ($Connection) use ($Output) {
         $Output->render('@.;@#yellow:■ Connection closed@;@.;');
      },
      // @ on HTTP Response received
      response: function (Request $Request, Response $Response) use ($Output, $Client) {
         $Output->render('@.;@#white:--- Response ---@;@.;');
         $Output->render('@#green:Protocol:@; ' . $Response->protocol . '@.;');
         $Output->render('@#green:Status:@;   ' . $Response->code . ' ' . $Response->status . '@.;');

         // @ Display response headers
         $Output->render('@.;@#white:--- Headers ---@;@.;');
         foreach ($Response->headers as $name => $value) {
            if (is_array($value)) {
               $value = implode(', ', $value);
            }
            $Output->render("@#cyan:{$name}:@; {$value}@.;");
         }

         // @ Display response body (truncated)
         $body = $Response->body;
         if ($body !== '') {
            $Output->render('@.;@#white:--- Body ---@;@.;');
            if (strlen($body) > 500) {
               $Output->render(substr($body, 0, 500) . '...' . '@.;');
            }
            else {
               $Output->render($body . '@.;');
            }
         }

         $Output->render('@.;@#green:✓ HTTPS request completed@;@.;');

         // @ Stop the event loop after first response (demo is one-shot)
         $Client::$Event->loop = false;
      }
   );

   // @ Start the client
   $Client->start();
};
