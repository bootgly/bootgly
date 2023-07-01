<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\nodes\HTTP\Server;


use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Tester;
use Bootgly\API\Project;
use Bootgly\API\Server as SAPI;

use Bootgly\Web\interfaces\TCP;
use Bootgly\Web\interfaces\TCP\Server\Packages;
use Bootgly\Web\interfaces\TCP\Client;
use Bootgly\Web\modules\HTTP;
use Bootgly\Web\nodes\HTTP\Server\Request;
use Bootgly\Web\nodes\HTTP\Server\Response;
use Bootgly\Web\modules\HTTP\Server\Router;


abstract class Decoders
{
   abstract public static function decode(Packages $Package, string $buffer, int $size);
}