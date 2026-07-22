<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


use Closure;
use Fiber;


/**
 * Scheduler capability for scoped state around Fiber execution segments.
 */
interface Contextualizing
{
   /**
    * Bind callbacks around every scheduled execution segment of a Fiber.
    *
    * @param Fiber<mixed,mixed,mixed,mixed> $Fiber
    */
   public function bind (Fiber $Fiber, Closure $Enter, Closure $Leave): void;
}
