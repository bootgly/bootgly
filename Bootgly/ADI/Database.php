<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI;


use Bootgly\ADI\Database\Config;
use Bootgly\ADI\Database\Connection;
use Bootgly\ADI\Database\Drivers;
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
}
