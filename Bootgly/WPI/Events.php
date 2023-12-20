<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI;


interface Events
{
   // @ Client/Server
   public const EVENT_CONNECT = 1;
   // @ Package
   public const EVENT_READ = 2;
   public const EVENT_WRITE = 3;
   public const EVENT_EXCEPT = 4;


   // ...Used to define and indentify subclasses (instance of).
}
