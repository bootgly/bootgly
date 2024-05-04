<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


enum Status : int {
   case Booting = 1;
   case Configuring = 2;
   case Starting = 3;

   case Running = 4;

   case Pausing = 5;
   case Paused = 6;

   case Stopping = 7;
}
