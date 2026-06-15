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


use Bootgly\ACI\Queues\Handler;
use Bootgly\ACI\Queues\Job;


/**
 * Shared handler fixture for the Queues suite.
 *
 * Required (not autoloaded) by each test file so the class is declared once
 * across the whole suite run. Records every handled payload for assertions.
 */
final class Recorder implements Handler
{
   /** @var array<int,array<string,mixed>> */
   public static array $handled = [];

   public function handle (Job $Job): void
   {
      self::$handled[] = $Job->payload;
   }
}
