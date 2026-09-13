<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\AutoTLS;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Configs;


/**
 * A server port equal to the HTTP-01 validation port is refused by throwing.
 *
 * configure() runs before the log sinks are withheld from root, so a record
 * logged there would be written by root; a contradiction found at configure
 * time is therefore thrown to the caller, never logged.
 */
return new Test(
   description: 'configure() throws — never logs — when the server port is the HTTP-01 validation port',
   test: function () {
      $Server = new HTTP_Server_CLI(Modes::Test);
      $thrown = null;

      try {
         $Server->configure(new Configs(
            host: '127.0.0.1',
            port: 80,
            workers: 1,
            AutoTLS: new AutoTLS(['example.com'], 'ops@example.com', staging: true),
         ));
      }
      catch (Throwable $Throwable) {
         $thrown = $Throwable;
      }

      yield assert(
         assertion: $thrown instanceof RuntimeException
            && str_contains($thrown->getMessage(), 'HTTP-01'),
         description: 'a RuntimeException names the HTTP-01 contradiction ('
            . ($thrown === null ? 'nothing thrown' : $thrown::class . ': ' . $thrown->getMessage()) . ')'
      );
   }
);
