<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;


enum Handlers : string
{
   case File = File::class;
}
