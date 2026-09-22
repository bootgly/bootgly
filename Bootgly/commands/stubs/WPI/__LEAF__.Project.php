<?php

use const Bootgly\CLI;
// use const BOOTGLY_STORAGE_DIR;
// use Bootgly\ACI\Logs\Handlers;
// use Bootgly\ACI\Logs\Handlers\File;
// use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Configs;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;


return new Project(
   // # Project Metadata
   name: '__NAME__',
   description: '__DESCRIPTION__',
   version: '__VERSION__',
   author: '__AUTHOR__',
   exportable: true,

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      // ? Persistent logs — global (server) channels write JSON lines here in every mode.
      //   Without this, Daemon mode auto-persists to storage/logs/{channel}.log.
      // Logger::$Sinks ??= new Handlers;
      // Logger::$Sinks->push(new File(BOOTGLY_STORAGE_DIR . 'logs/{project}/{channel}.log'));

      $Server = new HTTP_Server_CLI(Mode: match (true) {
         isSet($options['f']) => Modes::Foreground,
         isSet($options['i']) => Modes::Interactive,
         isSet($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      // ! Server configuration — configure() takes one Configs per concern:
      //   HTTP_Server_CLI\Configs (here), Request\Configs and Response\Configs.
      $Server->configure(
         new Configs(
            host: '0.0.0.0',
            port: getenv('PORT') ? (int) getenv('PORT') : (int) '__PORT__',
            workers: 2,
            // ? Auto-TLS (automatic HTTPS via Let's Encrypt) — set your domain and uncomment:
            // AutoTLS: new AutoTLS(
            //    domains: ['example.com'],
            //    email: 'admin@example.com',
            //    staging: true, // validate with the staging CA first — flip to false for the real certificate
            // ),
            // ! Workers demote from root to this account (root is needed to bind
            //   port 80 for HTTP-01). The kit image ships it; on a host, create it
            //   (`useradd -r bootgly`) or set both to null to keep the launcher's user
            user: 'bootgly',
            group: 'bootgly',
            // health: '/health', // built-in K8s probe endpoint (answers before middlewares)
         )
      );
      $Server
         ->on(Events::RequestReceived, HTTP_Server_CLI::$Router->load(__DIR__ . '/router'))
         ->on(Events::ServerAdvertised, function ($Server) {
            // ? Launch banner — fired on the process that owns the terminal
            //   (on Daemon mode, the launcher); advertise() prints the addresses
            CLI->Terminal->Output->render('@.;@#green:✓ __NAME__ started@;@.;');
            $Server->advertise();
         })
         ->on(Events::ServerStopped, function ($Server) {
            CLI->Terminal->Output->render('@.;@#yellow:■ __NAME__ stopped@;@.;');
         });

      $Server->start();
   }
);
