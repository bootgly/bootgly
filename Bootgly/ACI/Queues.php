<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use Bootgly\ACI\Queues\Config;
use Bootgly\ACI\Queues\Drivers;
use Bootgly\ACI\Queues\Queue;


/**
 * Queue manager — registry of named queues over a single active driver.
 *
 * Layer-shared (ABI-only deps) so CLI workers and the WPI dispatch adapter share
 * one contract. fetch() builds and caches a named Queue on demand.
 */
class Queues
{
   // * Config
   public Config $Config;

   // * Data
   public Drivers $Drivers;

   // * Metadata
   /** @var array<string,Queue> */
   protected array $Queues = [];


   /**
    * @param array<string,mixed>|Config $config
    */
   public function __construct (array|Config $config = [])
   {
      // * Config
      $this->Config = $config instanceof Config
         ? $config
         : new Config($config);

      // * Data
      $this->Drivers = new Drivers($this->Config);
   }

   /**
    * Fetch a named queue, building and caching it on first use.
    *
    * @param string $name Queue name.
    */
   public function fetch (string $name = 'default'): Queue
   {
      // :
      return $this->Queues[$name] ??= new Queue($name, $this->Drivers->fetch());
   }
}
