<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

/**
 * SSE routes — Server-Sent Events (`text/event-stream`) push.
 *
 * Exposes a live clock stream and a browser page consuming it:
 *   - GET /events → SSE stream: one `tick` event per second, `retry: 3000`,
 *                   keep-alive heartbeat, resume via `Last-Event-ID`.
 *   - GET /sse    → Minimal EventSource page rendering the stream.
 *
 * Enable it in router/router.index.php: add 'SSE' to the manifest.
 *
 * Try it raw:
 *   curl -N http://localhost:8082/events
 *   curl -N -H 'Last-Event-ID: 5' http://localhost:8082/events
 */

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\SSE;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


return static function (Request $Request, Response $Response, Router $Router)
{
   // @ Live clock stream — one `tick` event per second, ending after 60
   yield $Router->route('/events', function (Request $Request, Response $Response): Response {
      $SSE = $Response->SSE;
      $SSE->heartbeat = 15;
      $SSE->retry = 3000;

      // ! Resume point — the browser resends the last seen event id on reconnect
      $count = (int) $SSE->last;

      $SSE->open(
         Tick: static function (SSE $SSE) use (&$count): void {
            $count++;

            $SSE->send(
               data: ['count' => $count, 'time' => date('H:i:s')],
               event: 'tick',
               id: (string) $count
            );

            // ? Demo streams end after 60 ticks — a reconnecting client resumes
            if ($count >= 60) {
               $SSE->close();
            }
         },
         interval: 1
      );

      return $Response;
   }, GET);

   // @ Minimal EventSource consumer page
   yield $Router->route('/sse', function (Request $Request, Response $Response): Response {
      return $Response->send(<<<'HTML'
      <!DOCTYPE html>
      <html>
      <head><title>Bootgly SSE demo</title></head>
      <body>
         <h1>Server-Sent Events</h1>
         <pre id="log"></pre>
         <script>
            const log = document.getElementById('log');
            const source = new EventSource('/events');
            source.addEventListener('tick', (event) => {
               const {count, time} = JSON.parse(event.data);
               log.textContent += `tick #${count} at ${time}\n`;
            });
            source.onerror = () => log.textContent += '(reconnecting...)\n';
         </script>
      </body>
      </html>
      HTML);
   }, GET);
};
