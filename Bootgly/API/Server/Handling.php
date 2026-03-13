<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Server;


use Closure;


interface Handling
{
   public Closure $response { get; }
   /** @var array<Middleware> */
   public array $middlewares { get; }
}
