<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Assertions;
use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Interfaces\TCP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;


/**
 * H-HSC-3 — the HTTP server configures its worker memory budget.
 *
 * Request bodies, the route cache and pending output share one budget,
 * `TCP_Server_CLI::$maxWorkerPendingBytes`; `HTTP_Server_CLI\Configs` sets it
 * the same way `WS_Server_CLI\Configs` does, and `null` keeps what is there.
 */
return new Test(
   description: 'H-HSC-3: HTTP_Server_CLI\Configs sets the worker memory budget',
   test: new Assertions(Case: function (): Generator {
      // ! configure() writes worker statics: snapshot what this case changes
      $budget = TCP_Server_CLI::$maxWorkerPendingBytes;
      $OldProtocols = TCP_Server_CLI::$Protocols;

      try {
         // @@ A) The named key sets the budget and reads back from the Configs
         TCP_Server_CLI::$maxWorkerPendingBytes = 67_108_864;
         $Configs = new HTTP_Server_CLI\Configs(
            host: '127.0.0.1',
            port: 0,
            workers: 1,
            maxWorkerPendingBytes: 33_554_432
         );
         $Server = new HTTP_Server_CLI(Mode: Modes::Test);
         $Server->configure($Configs);

         yield assert(
            assertion: $Configs->maxWorkerPendingBytes === 33_554_432
               && TCP_Server_CLI::$maxWorkerPendingBytes === 33_554_432,
            description: 'maxWorkerPendingBytes is applied to the worker budget — '
               . var_export(TCP_Server_CLI::$maxWorkerPendingBytes, true)
         );

         // @@ B) Leaving it out keeps the budget that is already configured —
         //       a non-default one, so a reset to the default cannot pass
         TCP_Server_CLI::$maxWorkerPendingBytes = 50_331_648;
         $Default = new HTTP_Server_CLI\Configs(host: '127.0.0.1', port: 0, workers: 1);
         $Other = new HTTP_Server_CLI(Mode: Modes::Test);
         $Other->configure($Default);

         yield assert(
            assertion: $Default->maxWorkerPendingBytes === null
               && TCP_Server_CLI::$maxWorkerPendingBytes === 50_331_648,
            description: 'null keeps the configured budget — '
               . var_export(TCP_Server_CLI::$maxWorkerPendingBytes, true)
         );
      }
      finally {
         TCP_Server_CLI::$maxWorkerPendingBytes = $budget;
         TCP_Server_CLI::$Protocols = $OldProtocols;
      }
   }),
);
