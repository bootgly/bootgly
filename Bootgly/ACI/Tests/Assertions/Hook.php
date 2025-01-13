<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Assertions;


use Closure;

use Bootgly\ABI\Configs\Setupables;


/**
 * @method mixed get()
 * @method self set(Closure $Callback)
 */
enum Hook: string {
   use Setupables;


   case BeforeAll = 'BeforeAll';
   case AfterAll = 'AfterAll';

   case BeforeEach = 'BeforeEach';
   case AfterEach = 'AfterEach';
}
