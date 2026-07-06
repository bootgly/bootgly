<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource;


use Closure;

use Bootgly\ACI\Events\Readiness;


/**
 * Contract for response resources that need the response scheduler.
 */
interface Scheduling
{
   /**
    * Inject the response wait bridge.
    *
    * @param Closure(Readiness|resource|null):object $Wait
    */
   public function schedule (Closure $Wait): static;
}
