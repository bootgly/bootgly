<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


use function count;

use Bootgly\ADI\Database\Operation\Result as DatabaseResult;
use Bootgly\ADI\Databases\SQL\Operation;


/**
 * ORM mapped result view.
 */
class Result
{
   // * Config
   public private(set) DatabaseResult $Result;

   // * Data
   /** @var array<int,object> */
   public private(set) array $entities;

   /**
    * Deferred single-level relation operations keyed by relation name.
    *
    * Empty when the repository eagerly awaited and attached requested loads.
    *
    * @var array<string,Operation>
    */
   public private(set) array $loads;

   // # Views
   public null|object $entity {
      get => $this->entities[0] ?? null;
   }

   public int $count {
      get => count($this->entities);
   }

   public bool $empty {
      get => $this->entities === [];
   }

   // * Metadata
   // ...


   /**
    * @param array<int,object> $entities
    * @param array<string,Operation> $loads
    */
   public function __construct (DatabaseResult $Result, array $entities, array $loads = [])
   {
      // * Config
      $this->Result = $Result;

      // * Data
      $this->entities = $entities;
      $this->loads = $loads;
   }
}
