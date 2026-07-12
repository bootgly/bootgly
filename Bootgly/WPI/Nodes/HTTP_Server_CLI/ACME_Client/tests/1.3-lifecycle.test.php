<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\API\Endpoints\Server\Modes;
use Bootgly\API\Endpoints\Server\Status;
use Bootgly\WPI\Interfaces\TCP_Server_CLI\Commands;
use Bootgly\WPI\Nodes\HTTP_Server_CLI;

return new Specification(
   description: 'AutoTLS lifecycle: server configuration is strictly pre-start',
   test: function () {
      $Server = new HTTP_Server_CLI(Modes::Test);
      $Server->configure(host: '127.0.0.1', port: 19080, workers: 1);
      $Server->configure(host: '127.0.0.1', port: 19081, workers: 1);

      yield assert(
         assertion: $Server->port === 19081,
         description: 'Booting and Configuring states allow configuration and reconfiguration'
      );

      $Property = new ReflectionProperty($Server, 'Status');
      $States = [
         Status::Starting,
         Status::Running,
         Status::Pausing,
         Status::Paused,
         Status::Stopping
      ];
      foreach ($States as $Status) {
         $Property->setValue($Server, $Status);
         $Server->configure(host: '127.0.0.1', port: 19999, workers: 2);

         yield assert(
            assertion: $Server->port === 19081,
            description: "configure() is rejected after the pre-start boundary: {$Status->name}"
         );
      }

      // A command sequence is consumed once per process-local Commands
      // instance. SIGUSR1 remains the generic command channel; Auto-TLS uses
      // a separate SIGURG wake-up and cannot consume or replay these records.
      $Server->Process->State->qualify('command-test-' . getmypid());
      $Writer = $Server->Commands;
      $Reader = new Commands($Server);
      try {
         $initialized = $Writer->erase();
         $saved = $Writer->save('test', 'context');
         yield assert(
            assertion: $initialized && $saved
               && $Writer->read() === 'test:context'
               && $Writer->read() === null
               && $Reader->read() === 'test:context'
               && $Reader->read() === null,
            description: 'a sequenced command is delivered once to each process-local reader'
         );
      }
      finally {
         @unlink($Server->Process->State->commandFile);
      }
   }
);
