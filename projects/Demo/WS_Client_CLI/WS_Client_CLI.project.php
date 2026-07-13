<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Demo\WS_Client_CLI;


use function getenv;
use function strlen;

use const Bootgly\CLI;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\WS_Client_CLI;
use Bootgly\WPI\Nodes\WS_Client_CLI\Events;


return new Project(
   // # Project Metadata
   name: 'Demo WS Client CLI',
   description: 'Demonstration project for Bootgly WebSocket Client CLI',
   version: '1.0.0',
   author: 'Bootgly',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $WS_Client_CLI = new WS_Client_CLI();

      // @ Optional TLS / wss:// (WS_TLS=1) — verification relaxed for local certs.
      $secure = null;
      if (getenv('WS_TLS')) {
         $secure = [
            'verify_peer' => false,
            'verify_peer_name' => false,
         ];
      }

      $WS_Client_CLI->configure(
         host: getenv('WS_HOST') ?: '127.0.0.1',
         port: getenv('PORT') ? (int) getenv('PORT') : 8083,
         secure: $secure,
         compression: getenv('WS_NOCOMPRESS') ? false : true,
         // @ WS_RECONNECT=1 auto re-dials after an abrupt drop (backoff WS_RECONNECT_DELAY s).
         reconnect: (bool) getenv('WS_RECONNECT'),
         reconnectDelay: getenv('WS_RECONNECT_DELAY') ? (int) getenv('WS_RECONNECT_DELAY') : 1
      );

      // @ Optional upgrade headers.
      //   WS_AUTH=<token>  -> Authorization: Bearer <token>
      //   WS_ORIGIN=<url>  -> Origin: <url>
      $headers = [];
      if ($token = getenv('WS_AUTH')) {
         $headers['Authorization'] = "Bearer {$token}";
      }
      if ($origin = getenv('WS_ORIGIN')) {
         $headers['Origin'] = $origin;
      }

      $WS_Client_CLI
         // # On the verified 101: report the negotiated session and send a message.
         ->on(Events::Connected, function ($Session) {
            $Output = CLI->Terminal->Output;
            $Output->render('@.;@#green:✓ Connected to WebSocket server@;@.;');
            $Output->render('  subprotocol: @#cyan:' . ($Session->subprotocol ?: '(none)') . '@;@..;');
            $Output->render('  permessage-deflate: @#cyan:' . ($Session->Deflator !== null ? 'yes' : 'no') . '@;@..;');

            $Session->send(getenv('WS_MESSAGE') ?: 'Hello from Bootgly WS_Client_CLI!');
         })
         // # On each server message: print it. WS_ONCE=1 closes after the first.
         ->on(Events::MessageReceived, function ($Session, $Message) {
            $Output = CLI->Terminal->Output;
            $kind = $Message->binary ? 'binary' : 'text';
            $Output->render('@#yellow:← ' . $kind . ' (' . strlen($Message->payload) . ' bytes)@;: ' . $Message->payload . '@\;');

            if (getenv('WS_ONCE')) {
               $Session->close();
            }
         })
         ->on(Events::Disconnected, function ($Session) {
            $Output = CLI->Terminal->Output;
            $Output->render('@.;@#yellow:■ Disconnected@;@.;');
         });

      // @ Connect + run the event loop until the connection closes (blocking).
      $WS_Client_CLI->connect(getenv('WS_PATH') ?: '/', $headers);
   }
);
