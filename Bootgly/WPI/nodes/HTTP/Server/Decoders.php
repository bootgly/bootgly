<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\nodes\HTTP\Server;


use Bootgly\ACI\Logs\Logger;
use Bootgly\ACI\Tests\Tester;
use Bootgly\API\Project;
use Bootgly\API\Server as SAPI;

use Bootgly\WPI\interfaces\TCP;
use Bootgly\WPI\interfaces\TCP\Server\Packages;
use Bootgly\WPI\interfaces\TCP\Client;
use Bootgly\WPI\modules\HTTP;
use Bootgly\WPI\nodes\HTTP\Server\Request;
use Bootgly\WPI\nodes\HTTP\Server\Response;
use Bootgly\WPI\modules\HTTP\Server\Router;


abstract class Decoders
{
   abstract public static function decode(Packages $Package, string $buffer, int $size);
}