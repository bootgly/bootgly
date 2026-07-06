<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


interface Encoder
{
  /**
   * Encodes the given package into a string.
   *
   * @param Packages $Package
   * @param int<0, max>|null $length
   * @param-out int<0, max>|null $length
   *
   * @return string 
   */
  public static function encode (Packages $Package, null|int &$length): string;
}
