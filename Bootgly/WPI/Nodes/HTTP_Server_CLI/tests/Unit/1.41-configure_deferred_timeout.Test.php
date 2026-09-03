<?php

use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * BG-20: `configure(new Response\Configs(deferredTimeout:))` is the documented way to size the
 * server-wide deferral budget — the value `defer()` falls back to when a call
 * passes none. The knob must reach `Response::$deferredTimeout`, and a
 * re-configuration that omits it must keep what was already set.
 */
return new Test(
   description: 'Response\Configs(deferredTimeout:) should seed the server-wide deferral budget',
   test: new Assertions(Case: function (): Generator {
      // ! Statics survive the suite: snapshot every one configure() writes
      $oldDeferred = Response::$deferredTimeout;
      $oldIdle = TCP_Server_CLI::$connectionIdleTimeout;
      $oldHTTP2 = HTTP_Server_CLI::$enableHTTP2;
      $OldProtocols = TCP_Server_CLI::$Protocols;

      try {
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);

         // @@ A) The knob reaches the Response static
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new HTTP_Server_CLI\Response\Configs(deferredTimeout: 2.5)
         );

         yield assert(
            assertion: Response::$deferredTimeout === 2.5,
            description: 'the configured budget seeds Response::$deferredTimeout — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ B) Omitting it keeps the configured budget
         $Server->configure(new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1));

         yield assert(
            assertion: Response::$deferredTimeout === 2.5,
            description: 'a re-configuration without the knob keeps the budget — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ C) 0 restores the unbounded default
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1),
            new HTTP_Server_CLI\Response\Configs(deferredTimeout: 0)
         );

         yield assert(
            assertion: Response::$deferredTimeout === 0,
            description: '0 disarms the server-wide budget — '
               . var_export(Response::$deferredTimeout, true)
         );

         // @@ D) The idle knob travels the same way
         $Server->configure(
            new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1, connectionIdleTimeout: 7)
         );

         yield assert(
            assertion: TCP_Server_CLI::$connectionIdleTimeout === 7,
            description: 'connectionIdleTimeout seeds the transport static — '
               . var_export(TCP_Server_CLI::$connectionIdleTimeout, true)
         );
      }
      finally {
         Response::$deferredTimeout = $oldDeferred;
         TCP_Server_CLI::$connectionIdleTimeout = $oldIdle;
         HTTP_Server_CLI::$enableHTTP2 = $oldHTTP2;
         TCP_Server_CLI::$Protocols = $OldProtocols;
      }
   })
);
