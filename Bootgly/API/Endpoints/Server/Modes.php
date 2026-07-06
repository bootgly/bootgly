<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Endpoints\Server;


enum Modes : int {
   case Daemon = 1;
   case Interactive = 2;
   case Monitor = 3;
   case Test = 4;
   case Foreground = 5;
}
