<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use Closure;
use RuntimeException;

use Bootgly\ADI\Databases\KV as KVDatabase;
use Bootgly\ADI\Databases\KV\Operation;
use Bootgly\API\Environment\Configs;
use Bootgly\API\Environment\Configs\Config;
use Bootgly\API\Environment\Configs\KVConfig;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * HTTP response resource for awaiting async key-value (Redis) operations.
 *
 * `command()` creates **and advances** the operation — the encoded write is
 * flushed to the socket immediately, so several pending commands pipeline on
 * the same connection while their replies are in flight (advance-then-await).
 * `await()`/`drain()` park the response Fiber on the connection readiness
 * instead of blocking the worker event loop.
 */
class KV extends Resource implements Scheduling
{
   // * Config
   public KVDatabase $KV;

   // * Data
   private null|Closure $Wait = null;

   // * Metadata
   // ...


   public function __construct (KVDatabase $KV)
   {
      parent::__construct();

      // * Config
      $this->KV = $KV;
   }

   /**
    * Provide a lazy factory that builds this resource from a `kv` scope.
    *
    * Encapsulates the per-worker connection singleton, the response context
    * guard and the canonical config path (`Configs` → `KVConfig` → `KV`) so
    * projects register the resource in a single line.
    *
    * @return Closure(object):self
    */
   public static function provide (string $configs): Closure
   {
      return static function (object $Context) use ($configs): self {
         // ! Single connection per worker: pending commands pipeline on it
         static $KV = null;

         // ?
         if ($Context instanceof Response === false) {
            throw new RuntimeException('KV response resource expects a Response context.');
         }

         // @ Build once per worker
         if ($KV instanceof KVDatabase === false) {
            $Configs = new Configs($configs);
            $Configs->allow('kv', [
               'KV_DRIVER',
               'KV_ENABLED',
               'KV_HOST',
               'KV_POOL_MAX',
               'KV_POOL_MIN',
               'KV_PORT',
               'KV_TIMEOUT',
            ]);
            $Scope = $Configs->get('kv');

            // @phpstan-ignore-next-line
            if ($Scope instanceof Config === false || $Scope->Enabled->get() !== true) {
               throw new RuntimeException('Enable KV_ENABLED=true in the kv config scope and set KV_HOST and KV_PORT as needed.');
            }

            $KV = new KVDatabase(new KVConfig($Scope)->configure());
         }

         // :
         return new self($KV);
      };
   }

   /**
    * Bind the response wait bridge.
    */
   public function schedule (Closure $Wait): static
   {
      $this->Wait = $Wait;

      return $this;
   }

   /**
    * Create and advance one pending key-value command.
    *
    * The returned operation is **not** awaited — issue several commands and
    * pass them to `drain()` (or `await()` each) to overlap their round-trips
    * on the pipelined connection.
    *
    * @param array<int,mixed> $arguments
    */
   public function command (string $command, array $arguments = []): Operation
   {
      // @ Advance immediately: flushing the write lets later commands
      //   pipeline on the connection while this reply is in flight
      return $this->KV->advance($this->KV->command($command, $arguments));
   }

   /**
    * Create, await and unwrap one key-value command, throwing when it fails.
    *
    * @param array<int,mixed> $arguments
    */
   public function fetch (string $command, array $arguments = []): mixed
   {
      $Operation = $this->await($this->command($command, $arguments));
      $this->check($Operation);

      // :
      return $Operation->response;
   }

   /**
    * Await one key-value operation through the bound response scheduler.
    */
   public function await (Operation $Operation): Operation
   {
      while ($Operation->finished === false) {
         $Operation = $this->KV->advance($Operation);

         if ($Operation->finished) {
            break;
         }

         $Wait = $this->Wait;

         if ($Wait === null) {
            throw new RuntimeException('KV response resource is not bound.');
         }

         $Wait($Operation->Readiness);
      }

      return $Operation;
   }

   /**
    * Await a group of key-value operations through the bound scheduler.
    *
    * @param array<int,Operation> $Operations
    * @return array<int,Operation>
    */
   public function drain (array $Operations): array
   {
      while (true) {
         foreach ($Operations as $id => $Operation) {
            if ($Operation->finished) {
               continue;
            }

            $Operations[$id] = $this->KV->advance($Operation);
         }

         // ! Re-scan AFTER all advances: pipelined replies resolve FIFO, so
         //   advancing a later sibling may have finished operations already
         //   counted as pending — parking on a stale snapshot would suspend
         //   the Fiber with nothing left in flight (nothing ever wakes it).
         $waiting = null;
         $pending = false;

         foreach ($Operations as $Operation) {
            if ($Operation->finished === false) {
               $pending = true;
               $waiting ??= $Operation->Readiness;
            }
         }

         if ($pending === false) {
            break;
         }

         $Wait = $this->Wait;

         if ($Wait === null) {
            throw new RuntimeException('KV response resource is not bound.');
         }

         $Wait($waiting);
      }

      return $Operations;
   }

   /**
    * Check one awaited operation for failure.
    */
   private function check (Operation $Operation): void
   {
      if ($Operation->error !== null) {
         throw new RuntimeException($Operation->error);
      }
   }
}
