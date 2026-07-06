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


/**
 * Primary key entity property mapping.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
class Key extends Column
{
   public function __construct (null|string $name = null, bool $generated = true)
   {
      parent::__construct(
         name: $name,
         insert: $generated === false,
         update: false,
         generated: $generated,
      );
   }
}
