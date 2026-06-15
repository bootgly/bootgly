<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Queues\tests;


use RuntimeException;

use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;


/**
 * Handler fixture that always throws — drives the retry / bury paths.
 *
 * Required (not autoloaded) by each test file so the class is declared once.
 */
final class Thrower implements Handler
{
   public function handle (Job $Job): void
   {
      throw new RuntimeException('boom');
   }
}
