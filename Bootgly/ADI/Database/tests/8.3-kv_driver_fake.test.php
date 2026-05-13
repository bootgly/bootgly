<?php

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Operation as DatabaseOperation;
use Bootgly\ADI\Database\Operation\Result;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Databases\KV\Driver;
use Bootgly\ADI\Databases\KV\Operation;


return new Specification(
   description: 'Database: KV fake driver proves non-SQL operation shape',
   test: function () {
      $Config = new Config([
         'driver' => 'fake',
      ]);
      $Connection = new Connection($Config);
      $Driver = new class($Config, $Connection) extends Driver {
         /**
          * @param array<int,mixed> $arguments
          */
         public function command (string $command, array $arguments = []): Operation
         {
            return $this->prepare(new Operation($this->Connection, $command, $arguments, $this->Config->timeout));
         }

         public function prepare (DatabaseOperation $Operation): DatabaseOperation
         {
            if ($Operation instanceof Operation === false) {
               return $Operation->fail('Fake KV driver requires a KV operation.');
            }

            /** @var Operation $Operation */

            $Operation->Protocol = $this;
            $Operation->state = OperationStates::Queued;

            return $Operation;
         }

         public function advance (DatabaseOperation $Operation): DatabaseOperation
         {
            if ($Operation instanceof Operation === false) {
               return $Operation->fail('Fake KV driver requires a KV operation.');
            }

            /** @var Operation $Operation */

            $Operation->response = 'bootgly';

            return $Operation->resolve(new Result('GET'));
         }
      };

      $Operation = $Driver->command('GET', ['framework']);
   $Driver->advance($Operation);

      yield assert(
         assertion: $Operation->command === 'GET' && $Operation->arguments === ['framework'],
         description: 'KV operation carries command and argument semantics instead of SQL text'
      );

      yield assert(
         assertion: $Operation->finished && $Operation->response === 'bootgly',
         description: 'KV driver advances a non-SQL operation through the generic Operation lifecycle'
      );
   }
);
