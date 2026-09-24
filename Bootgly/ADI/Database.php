<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use Throwable;

use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Drivers;
use Bootgly\ADI\Database\Operation;
use Bootgly\ADI\Database\Operation\OperationStates;
use Bootgly\ADI\Database\Pool;
use Bootgly\ADI\Database\Pools;


/**
 * Abstract ADI database transport core.
 *
 * Paradigm facades under Databases/* add concrete access verbs such as SQL
 * query(), KV get()/set() or Document find()/insert(). The singular Database
 * class only wires shared config, connection and pool composition.
 */
abstract class Database
{
   // * Config
   public Config $Config;
   /** @var class-string<Drivers> */
   public string $drivers;

   // * Data
   public Connection $Connection;
   public Pool $Pool;
   public Pools $Pools;

   // * Metadata
   // ...


   /**
    * Create a database transport core from ADI-native config data.
    *
    * @param array<string,mixed>|Config $config
    * @param class-string<Drivers> $drivers
    */
   public function __construct (array|Config $config = [], string $drivers = Drivers::class)
   {
      // * Config
      $this->Config = $config instanceof Config
         ? $config
         : new Config($config);
      $this->drivers = $drivers;

      // * Data
      $this->Connection = new Connection($this->Config);
      $this->Pools = new Pools($this->Config, $this->Connection, $drivers);
      $this->Pool = $this->Pools->fetch($this->Config->driver);
   }

   /**
    * Withdraw operations locally because their caller stopped waiting for them —
    * nothing is sent to the server (see `Pool::withdraw()`).
    *
    * Parked operations go first, then the ones that are not reading yet, then
    * the pipelined readers: a slot freed early would promote a parked operation
    * onto the wire, and a reader withdrawn before the writer queued behind it
    * would leave nobody to take its answer. Every operation is attempted; the
    * first failure is rethrown once all of them ran.
    */
   public function withdraw (Operation ...$Operations): void
   {
      // !
      $Ordered = [[], [], []];

      foreach ($Operations as $Operation) {
         $Ordered[match ($Operation->state) {
            OperationStates::Pending => 0,
            OperationStates::Reading => 2,
            default => 1
         }][] = $Operation;
      }

      // @@
      $Failure = null;

      foreach ($Ordered as $Group) {
         foreach ($Group as $Operation) {
            try {
               ($Operation->Pool ?? $this->Pool)->withdraw($Operation);
            }
            catch (Throwable $Throwable) {
               $Failure ??= $Throwable;
            }
         }
      }

      if ($Failure !== null) {
         throw $Failure;
      }
   }
}
