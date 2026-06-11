<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\tests;


use Bootgly\ABI\Event;


/**
 * Shared event-identity fixture for the Emitter suite.
 *
 * Required (not autoloaded) by each test file so the enum is declared once
 * across the whole suite run.
 */
enum Events implements Event
{
   case Alpha;
   case Beta;
}
