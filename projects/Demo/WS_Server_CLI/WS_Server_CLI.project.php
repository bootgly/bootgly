<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_WS_Server_CLI;


use const BOOTGLY_ROOT_DIR;
use function getenv;
use function getmypid;

use const Bootgly\CLI;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\Authenticating\Guard;
use Bootgly\WPI\Nodes\WS_Server_CLI;
use Bootgly\WPI\Nodes\WS_Server_CLI\Events;


return new Project(
   // # Project Metadata
   name: 'Demo WS Server CLI',
   description: 'Demonstration project for Bootgly WebSocket Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      $WS_Server_CLI = new WS_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      // @ Optional handshake auth (WS_AUTH=1): require `Authorization: Bearer secret`.
      $guards = [];
      if (getenv('WS_AUTH')) {
         $guards[] = new class extends Guard {
            public function authenticate (object $Request): bool
            {
               return $this->extract($Request) === 'secret';
            }
            public function challenge (object $Response): object
            {
               return $Response;
            }
         };
      }

      // @ Optional TLS / wss:// (WS_TLS=1) using the bundled localhost certs.
      $secure = null;
      if (getenv('WS_TLS')) {
         $secure = [
            'local_cert' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.cert.pem',
            'local_pk' => BOOTGLY_ROOT_DIR . '@/certificates/localhost.key.pem',
            'verify_peer' => false,
         ];
      }

      $WS_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8083,
         workers: getenv('WS_WORKERS') ? (int) getenv('WS_WORKERS') : 1,
         secure: $secure,
         heartbeatInterval: getenv('WS_HEARTBEAT') ? (int) getenv('WS_HEARTBEAT') : 30,
         compression: getenv('WS_NOCOMPRESS') ? false : true,
         guards: $guards
      );
      $WS_Server_CLI
         // # Chat — every connection joins the lobby. In multi-worker mode
         //   (WS_WORKERS), announce the worker PID so a client can observe
         //   which worker served it (used to demonstrate cross-worker fan-out).
         ->on(Events::Connected, function ($Session) {
            // # Pure-echo / conformance mode (WS_ECHO): no lobby, no greeting,
            //   so the server echoes each frame unchanged (e.g. for Autobahn).
            if (getenv('WS_ECHO')) {
               return;
            }

            $Session->join('lobby');
            if (getenv('WS_WORKERS')) {
               $Session->send('worker:' . getmypid());
            }
         })
         // # On each message: relay to everyone else in the lobby, echo to sender.
         ->on(Events::MessageReceived, function ($Session, $Message) {
            // # Pure echo for conformance testing (WS_ECHO). Out-of-band send so
            //   empty and binary messages echo faithfully (opcode preserved).
            if (getenv('WS_ECHO')) {
               $Session->send($Message->payload, $Message->binary);

               return '';
            }

            $Session->broadcast('lobby', $Message->payload);

            return "echo: {$Message->payload}";
         })
         ->on(Events::ServerStarted, function ($WS_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $protocol = $WS_Server_CLI->socket ?? 'ws://';
            $host = $WS_Server_CLI->host ?? '0.0.0.0';
            $port = $WS_Server_CLI->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly WebSocket Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@..;');
         })
         ->on(Events::ServerStopped, function ($WS_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly WebSocket Server stopped@;@.;');
         });

      $WS_Server_CLI->start();
   }
);
