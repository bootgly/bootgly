<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace projects\Demo_HTTP_Server_CLI;


use const BOOTGLY_STORAGE_DIR;
use function defined;
use function getenv;

use const Bootgly\CLI;
use Bootgly\ACI\Events\Timer;
use Bootgly\ACI\Logs\Handlers;
use Bootgly\ACI\Logs\Handlers\File;
use Bootgly\ACI\Logs\Logger;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Projects\Project;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Events;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Database as DatabaseResource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\KV as KVResource;


return new Project(
   // # Project Metadata
   name: 'Demo HTTP Server CLI',
   description: 'Demonstration project for Bootgly HTTP Server CLI',
   version: '1.0.0',
   author: 'Bootgly',

   // # Project Boot Function
   boot: function (array $arguments = [], array $options = []): void
   {
      // @ Per-module logs — opted-in (global) loggers persist to storage/logs/<channel>.log in
      //   every mode. Registered before fork so workers inherit it; JSON lines, daily + size rotation.
      Logger::$Sinks ??= new Handlers;
      Logger::$Sinks->push(new File(BOOTGLY_STORAGE_DIR . 'logs/{channel}.log'));

      $HTTP_Server_CLI = new HTTP_Server_CLI(Mode: match (true) {
         isset($options['f']) => Modes::Foreground,
         isset($options['i']) => Modes::Interactive,
         isset($options['m']) => Modes::Monitor,
         default => Modes::Daemon
      });
      $HTTP_Server_CLI->configure(
         host: '0.0.0.0',
         port: getenv('PORT') ? (int) getenv('PORT') : 8082,
         workers: 1,
         responseResources: [
            'Database' => DatabaseResource::provide(__DIR__ . '/configs/'),
            'KV' => KVResource::provide(__DIR__ . '/configs/'),
         ],
         // requestMaxFileSize: 500 * 1024 * 1024,        // 500 MB (default) — max size per uploaded file part
         // requestMaxBodySize: 10 * 1024 * 1024,         // 10 MB (default) — max total non-multipart body
         // requestMaxMultipartFieldSize: 1 * 1024 * 1024, // 1 MB (default) — max size per text field value
         // requestMaxMultipartHeaderSize: 8 * 1024,        // 8 KB (default) — max size of a single part's headers
         // requestMaxMultipartFields: 1024,                // 1024 (default) — max number of text fields per request
         // requestMaxMultipartFiles: 1024,                 // 1024 (default) — max number of file parts per request
      );
      $HTTP_Server_CLI
         // # Routes — the active set is selected in router/router.index.php
         //   (swap the require there to switch demos)
         ->on(Events::RequestReceived, HTTP_Server_CLI::$Router->load(__DIR__ . '/router'))
         ->on(Events::ServerStarted, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $protocol = $HTTP_Server_CLI->socket ?? 'http://';
            $host = $HTTP_Server_CLI->host ?? '0.0.0.0';
            $port = $HTTP_Server_CLI->port ?? 0;

            $Output->render('@.;@#green:✓ Bootgly HTTP Server started@;@.;');
            $Output->render('  Listening on @#cyan:' . $protocol . $host . ':' . $port . '@;@.;');
            $Output->render('  @#green:● Ready for connections@;@..;');

            $projectName = defined('BOOTGLY_PROJECT') ? BOOTGLY_PROJECT->folder : 'Demo/HTTP_Server_CLI';
            $Output->render('@#Green:Tip:@; Use @#Black:`bootgly project stop` ' . $projectName . '@; to stop the server.@..;');

            // @ Demo log/heartbeat — rotating-level logs from a few channels.
            //   `global: true` persists them to the unified app log (Logger::$Sinks) in every mode;
            //   in Monitor they also stream live to the viewer (Logger::$Tap).
            $App = new Logger(channel: 'Demo.App', global: true);
            $Auth = new Logger(channel: 'Demo.Auth', global: true);
            $Database = new Logger(channel: 'Demo.Database', global: true);

            $tick = 0;
            Timer::add(1, function () use ($App, $Auth, $Database, &$tick): void {
               $tick++;

               $App->log(info: "Heartbeat #{$tick} — server healthy.");
               if ($tick % 3 === 0) {
                  $Auth->log(notice: "Session refreshed for user #{$tick}.");
               }
               if ($tick % 4 === 0) {
                  $Database->log(warning: "Slow query: SELECT took 320ms (tick {$tick}).");
               }
               if ($tick % 7 === 0) {
                  $App->log(error: "Simulated error at tick {$tick}.");
               }
               if ($tick % 6 === 0) {
                  // @ Multiline message — collapsed to one line in the pane (with a ⏎N marker);
                  //   select it (↑/↓) and press Enter to expand the full trace.
                  $Database->log(critical:
                     "RuntimeException: Payment gateway timeout (tick {$tick})\n"
                     . "    at Gateway->charge() in /app/Payment/Gateway.php:88\n"
                     . "    at Service->pay() in /app/Checkout/Service.php:42\n"
                     . "    at CheckoutController->process() in /app/Http/Controllers/CheckoutController.php:19\n"
                     . "    #0 {main}"
                  );
               }
               if ($tick % 9 === 0) {
                  $Database->log(debug: "Pool: 3 idle / 1 active (tick {$tick}).");
               }
            });
         })
         ->on(Events::ServerStopped, function ($HTTP_Server_CLI) {
            $Output = CLI->Terminal->Output;

            $Output->render('@.;@#yellow:■ Bootgly HTTP Server stopped@;@.;');
         });

      $HTTP_Server_CLI->start();
   }
);
