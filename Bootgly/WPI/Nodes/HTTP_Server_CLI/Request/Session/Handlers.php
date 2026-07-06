<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\Cache;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Session\Handlers\File;


enum Handlers : string
{
   case Cache = Cache::class;
   case File = File::class;
}
