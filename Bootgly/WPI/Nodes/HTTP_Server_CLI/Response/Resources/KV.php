<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources;


use function array_column;
use function array_values;
use function count;
use function max;
use function spl_object_id;
use Closure;
use Fiber;
use RuntimeException;
use Throwable;
use WeakReference;

use Bootgly\ADI\Database\Operation\OperationStates;
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
   /**
    * Unfinished commands this resource issued, each with the Fiber that issued
    * it (null outside one). Held strongly: a command still dialing is
    * referenced by nothing else, and must not vanish before its slot is taken
    * back. Finished ones are dropped as new commands arrive.
    *
    * @var array<int,array{0:Operation,1:null|WeakReference<Fiber<mixed,mixed,mixed,mixed>>}>
    */
   private array $Operations = [];
   /** Ledger size at which the next command scans it for finished entries (amortized pruning). */
   private int $watermark = 16;


   public function __construct (KVDatabase $KV)
   {
      parent::__construct();

      // * Config
      $this->KV = $KV;
   }

   /**
    * Hand back the commands this request left unfinished — the response calls
    * it when a deferred job ends, and the resource when it goes away. A handler
    * that never awaited them, or unwound past them (an uncaught deferral
    * timeout, a Fiber destroyed while parked on another wait), leaves nobody to
    * finish them: a command whose reply already arrived is finished by one
    * non-blocking read (its connection stays up), the rest are withdrawn.
    */
   public function clean (): void
   {
      // !
      $Unfinished = $this->settle(array_column($this->Operations, 0));

      $this->Operations = [];
      $this->watermark = 16;

      if ($Unfinished === []) {
         return;
      }

      // @
      try {
         $this->KV->withdraw(...$Unfinished);
      }
      catch (Throwable) {
         // ? The request is over: a withdrawal that failed has nobody to report to.
      }
   }

   /**
    * Hand back the unfinished commands when the resource goes away with its request.
    */
   public function __destruct ()
   {
      $this->clean();
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
               'KV_DATABASE',
               'KV_ENABLED',
               'KV_HOST',
               'KV_PASS',
               'KV_POOL_MAX',
               'KV_POOL_MIN',
               'KV_PORT',
               'KV_SSLCAFILE',
               'KV_SSLMODE',
               'KV_SSLPEER',
               'KV_SSLVERIFY',
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
      $Operation = $this->KV->advance($this->KV->command($command, $arguments));

      // @ Remember it, and who issued it, until it finishes: a Fiber destroyed
      //   while awaiting another command, or a request that ends with it still
      //   in flight, must not leave it holding its connection
      // ? Scanned only past a watermark that doubles with what survives, so a
      //   long pipeline costs amortized constant time per command
      if (count($this->Operations) >= $this->watermark) {
         foreach ($this->Operations as $id => [$Recorded]) {
            if ($Recorded->finished) {
               unset($this->Operations[$id]);
            }
         }

         $this->watermark = max(16, 2 * count($this->Operations));
      }

      if ($Operation->finished === false) {
         /** @var null|Fiber<mixed,mixed,mixed,mixed> $Fiber */
         $Fiber = Fiber::getCurrent();

         $this->Operations[spl_object_id($Operation)] = [
            $Operation,
            $Fiber === null ? null : WeakReference::create($Fiber),
         ];
      }

      return $Operation;
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
      $returned = false;
      $thrown = false;

      try {
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

         $returned = true;

         return $Operation;
      }
      catch (Throwable $Throwable) {
         $thrown = true;

         throw $Throwable;
      }
      finally {
         // ? The wait never came back: refused, interrupted, or the Fiber is
         //   being destroyed (only this block runs then) — nobody will advance
         //   the command again, so its connection is taken back here.
         if ($returned === false) {
            $this->withdraw([$Operation], $thrown);
         }
      }
   }

   /**
    * Await a group of key-value operations through the bound scheduler.
    *
    * @param array<int,Operation> $Operations
    * @return array<int,Operation>
    */
   public function drain (array $Operations): array
   {
      $returned = false;
      $thrown = false;

      try {
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

         $returned = true;

         return $Operations;
      }
      catch (Throwable $Throwable) {
         $thrown = true;

         throw $Throwable;
      }
      finally {
         // ? Same as await(): whatever the group still has in flight is withdrawn.
         if ($returned === false) {
            $this->withdraw($Operations, $thrown);
         }
      }
   }

   /**
    * Withdraw what a wait that never came back leaves in flight.
    *
    * On an exception only the awaited operations go: the handler may catch it
    * and keep using the others. A destroyed Fiber (no exception in flight) will
    * never run again, so every command it issued through this resource goes too.
    *
    * @param array<int,Operation> $Operations
    */
   private function withdraw (array $Operations, bool $thrown): void
   {
      if ($thrown === false) {
         $Fiber = Fiber::getCurrent();

         foreach ($this->Operations as [$Operation, $Owner]) {
            if ($Owner !== null && $Owner->get() === $Fiber) {
               $Operations[] = $Operation;
            }
         }
      }

      $Unfinished = $this->settle($Operations);

      if ($Unfinished === []) {
         return;
      }

      // ? A destroyed Fiber has no exception in flight and nobody to report to:
      //   a failed withdrawal must not turn its unwinding into an error that
      //   runs handler code inside a Fiber that can no longer suspend. Under
      //   an exception it propagates, chained to the one in flight.
      if ($thrown === false) {
         try {
            $this->KV->withdraw(...$Unfinished);
         }
         catch (Throwable) {
            // ? Dropped with the Fiber (see above)
         }

         return;
      }

      $this->KV->withdraw(...$Unfinished);
   }

   /**
    * Keep the unfinished commands of a group, each once — after one
    * non-blocking read for those whose reply may already be buffered, which
    * finishes them honestly instead of tearing their session down.
    *
    * @param array<int,Operation> $Operations
    * @return array<int,Operation>
    */
   private function settle (array $Operations): array
   {
      $Unfinished = [];

      foreach ($Operations as $Operation) {
         if ($Operation->finished === false && $Operation->state === OperationStates::Reading) {
            try {
               $this->KV->advance($Operation);
            }
            catch (Throwable) {
               // ? Withdrawn below all the same
            }
         }

         if ($Operation->finished === false) {
            $Unfinished[spl_object_id($Operation)] = $Operation;
         }
      }

      return array_values($Unfinished);
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
