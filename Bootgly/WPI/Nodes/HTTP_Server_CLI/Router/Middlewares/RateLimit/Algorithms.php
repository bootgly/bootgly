<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RateLimit;


/**
 * Rate-limiting counting algorithms (audit F-4).
 */
enum Algorithms
{
   /**
    * Fixed window: one counter per key with a TTL of `window` seconds. Cheap,
    * but allows a `2 × limit` burst straddling a window boundary.
    */
   case Fixed;
   /**
    * Weighted sliding window: blends the current and previous fixed windows by
    * the fraction of the previous window still in view, smoothing the boundary
    * burst while keeping O(1) storage (two counters per key).
    */
   case Sliding;
}
