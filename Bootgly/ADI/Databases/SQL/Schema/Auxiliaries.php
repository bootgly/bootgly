<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema;


use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Capabilities;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Directions;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Keys;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\References;
use Bootgly\ADI\Databases\SQL\Schema\Auxiliaries\Types;


/**
 * Registry for schema builder auxiliary enums.
 */
class Auxiliaries
{
   public const array ENUMS = [
      Capabilities::class,
      Directions::class,
      Keys::class,
      References::class,
      Types::class,
   ];
}
