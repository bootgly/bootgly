<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework — HTTP Client CLI Demo
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 *
 * This demo connects to a running HTTP Server and sends HTTP requests,
 * displaying the response data. Start an HTTP Server first:
 *
 *   bootgly project Demo start --HTTP_Server_CLI
 *
 * Then in another terminal:
 *
 *   bootgly project Demo start --HTTP_Client_CLI
 *
 * --------------------------------------------------------------------------
 */

namespace projects\Demo\HTTP_Client_CLI;


use function getenv;
use function is_array;

use const Bootgly\CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Client_CLI\Request\Response;


return static function (array $options = []): void
{
   $Output = CLI->Terminal->Output;
   $Output->render('@.;@#cyan:━━━ Bootgly HTTP Client CLI Demo ━━━@;@..;');

   // @ Create HTTP Client
   $Client = new HTTP_Client_CLI;
   $Client->configure(
      host: '127.0.0.1',
      port: getenv('PORT') ? (int) getenv('PORT') : 8082,
      workers: 0
   );

   // @ Register HTTP hooks
   $Client->on(
      // on Worker instance
      instance: function ($Client) {
         // @ Prepare a GET request
         $Client->request('GET', '/');

         $Socket = $Client->connect();
         if ($Socket) {
            $Client::$Event->loop();
         }
      },
      // on Connection connect
      connect: function ($Socket, $Connection) use ($Output) {
         $Output->render('@#green:✓ Connected to server@;@.;');
      },
      // on Connection disconnect
      disconnect: function ($Connection) use ($Output) {
         $Output->render('@.;@#yellow:■ Connection closed@;@.;');
      },
      // @ on HTTP Response received
      response: function (Request $Request, Response $Response) use ($Output) {
         $Output->render('@.;@#white:--- Response ---@;');
         $Output->render('@#green:Protocol:@; ' . $Response->protocol);
         $Output->render('@#green:Status:@;   ' . $Response->code . ' ' . $Response->status);

         // @ Display response headers
         $Output->render('@.;@#white:--- Headers ---@;');
         foreach ($Response->headers as $name => $value) {
            if (is_array($value)) {
               $value = implode(', ', $value);
            }
            $Output->render("@#cyan:{$name}:@; {$value}");
         }

         // @ Display response body
         $body = $Response->body;
         if ($body !== '') {
            $Output->render('@.;@#white:--- Body ---@;');
            $Output->render($body);
         }

         $Output->render('@.;@#green:✓ Request completed@;@.;');
      }
   );

   // @ Start the client
   $Client->start();
};
