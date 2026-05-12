<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI;


use Bootgly\ADI\Databases\SQL;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * HTTP_Server_CLI async database operation runner.
 *
 * This helper belongs to WPI because it bridges ADI readiness tokens to the
 * HTTP response Fiber scheduler through Response::wait(). The ADI Database
 * layer stays transport-agnostic.
 */
class Runner
{
   // * Config
   public SQL $Database;
   public Response $Response;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (SQL $Database, Response $Response)
   {
      // * Config
      $this->Database = $Database;
      $this->Response = $Response;
   }

   /**
    * Create and await one async database query.
    *
    * @param array<int|string,mixed> $parameters
    */
   public function query (string $sql, array $parameters = []): Operation
   {
      return $this->await($this->Database->query($sql, $parameters));
   }

   /**
    * Await one async database operation through the HTTP event loop.
    */
   public function await (Operation $Operation): Operation
   {
      while ($Operation->finished === false) {
         $Operation = $this->Database->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         $Readiness = $Operation->Readiness;

         if ($Readiness !== null) {
            $this->Response->wait($Readiness);
         }
         else {
            $this->Response->wait();
         }
      }

      return $Operation;
   }

   /**
    * Await a group of async database operations through the HTTP event loop.
    *
    * @param array<int,Operation> $Operations
    * @return array<int,Operation>
    */
   public function drain (array $Operations): array
   {
      while (true) {
         $waiting = null;
         $finished = true;

         foreach ($Operations as $id => $Operation) {
            if ($Operation->finished) {
               continue;
            }

            $finished = false;
            $Operation = $this->Database->advance($Operation);
            $Operations[$id] = $Operation;
            $waiting ??= $Operation->Readiness;
         }

         if ($finished) {
            break;
         }

         if ($waiting !== null) {
            $this->Response->wait($waiting);
         }
         else {
            $this->Response->wait();
         }
      }

      return $Operations;
   }
}
