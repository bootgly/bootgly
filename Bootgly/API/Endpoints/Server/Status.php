<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Endpoints\Server;


enum Status : int {
   case Booting = 1;
   case Configuring = 2;
   case Starting = 3;

   case Running = 4;

   // ! `5` was `Pausing`, removed as never-assigned — pause() jumps straight
   //   from Running to Paused. The remaining values stay put: out-of-tree
   //   consumers persisting the int backing must never be silently remapped.
   case Paused = 6;

   case Stopping = 7;
}
