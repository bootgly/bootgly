<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Model;


use Attribute;
use InvalidArgumentException;

use Bootgly\ADI\Databases\SQL\Model\Auxiliaries\Relations;


/**
 * Entity relationship mapping metadata.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Relation
{
   // * Config
   public private(set) Relations $Type;
   /** @var class-string */
   public private(set) string $target;
   public private(set) string $local;
   public private(set) string $foreign;
   public private(set) null|string $table;
   public private(set) null|string $pivotLocal;
   public private(set) null|string $pivotForeign;
   public private(set) null|string $name;
   public private(set) bool $lazy;

   // * Data
   // ...

   // * Metadata
   // ...


   /**
    * @param class-string $target
    */
   public function __construct (
      Relations $Type,
      string $target,
      string $local,
      string $foreign,
      null|string $table = null,
      null|string $pivotLocal = null,
      null|string $pivotForeign = null,
      null|string $name = null,
      bool $lazy = false
   )
   {
      if ($local === '' || $foreign === '') {
         throw new InvalidArgumentException('ORM relation keys cannot be empty.');
      }

      if ($name === '') {
         throw new InvalidArgumentException('ORM relation name cannot be empty.');
      }

      // * Config
      $this->Type = $Type;
      $this->target = $target;
      $this->local = $local;
      $this->foreign = $foreign;
      $this->table = $table;
      $this->pivotLocal = $pivotLocal;
      $this->pivotForeign = $pivotForeign;
      $this->name = $name;
      $this->lazy = $lazy;
   }
}
