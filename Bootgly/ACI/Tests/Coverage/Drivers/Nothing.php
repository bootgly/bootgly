<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Coverage\Drivers;


use Bootgly\ACI\Tests\Coverage\Driver;


/**
 * No-op driver — last-resort fallback when no extension is available.
 */
final class Nothing extends Driver
{
   /**
    * Return an empty hit map for disabled coverage sessions.
    *
    * @return array<string, array<int, int>>
    */
   public function collect (): array
   {
      return [];
   }
}
