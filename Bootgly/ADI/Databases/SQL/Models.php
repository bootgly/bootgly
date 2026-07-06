<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


use Bootgly\ADI\Databases\SQL\Model;


/**
 * ORM model metadata registry.
 */
class Models
{
   // * Config
   // ...

   // * Data
   /** @var array<class-string,Model> */
   private array $models = [];

   // * Metadata
   // ...


   /**
    * Fetch cached mapping metadata for one entity class.
    *
    * @param class-string $class
    */
   public function fetch (string $class): Model
   {
      $this->models[$class] ??= Model::reflect($class);

      return $this->models[$class];
   }
}
