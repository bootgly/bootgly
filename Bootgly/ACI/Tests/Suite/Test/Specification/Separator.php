<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Suite\Test\Specification;


class Separator
{
   // * Config
   public null|bool|string $line = null;
   public null|string $left = null;
   public null|string $header = null;


   public function __construct (
      null|bool|string $line = null,
      null|string $left = null,
      null|string $header = null
   )
   {
      $this->line = $line;
      $this->left = $left;
      $this->header = $header;
   }
}
