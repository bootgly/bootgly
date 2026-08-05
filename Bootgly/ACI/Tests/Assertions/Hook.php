<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
