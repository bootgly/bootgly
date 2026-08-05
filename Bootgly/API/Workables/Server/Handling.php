<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API\Workables\Server;


use Closure;


interface Handling
{
   public Closure $response { get; }
   /** @var array<Middleware> */
   public array $middlewares { get; }
}