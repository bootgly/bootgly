<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Tests\Fixture;


/**
 * Lifecycle states for a Fixture instance.
 */
enum Lifecycles
{
   /** Fixture has not been prepared yet. */
   case Pristine;
   /** Fixture setup is currently running. */
   case Preparing;
   /** Fixture is ready for test execution. */
   case Ready;
   /** Fixture teardown is currently running. */
   case Disposing;
   /** Fixture teardown completed. */
   case Disposed;
}
