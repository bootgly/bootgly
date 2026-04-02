<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Endpoints\Server;


enum Modes : int {
   case Daemon = 1;
   case Interactive = 2;
   case Monitor = 3;
   case Test = 4;
}
