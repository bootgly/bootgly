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


enum Status : int {
   case Booting = 1;
   case Configuring = 2;
   case Starting = 3;

   case Running = 4;

   case Pausing = 5;
   case Paused = 6;

   case Stopping = 7;
}
