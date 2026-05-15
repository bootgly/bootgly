<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Auxiliaries;


/**
 * Portable column type intents for schema compilation.
 */
enum Types
{
   case BigInteger;
   case Boolean;
   case Date;
   case Decimal;
   case Float;
   case Integer;
   case Json;
   case JsonB;
   case String;
   case Text;
   case Time;
   case Timestamp;
   case Uuid;
}
