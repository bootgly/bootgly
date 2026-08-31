<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\tests;


use Bootgly\ABI\Events\Emitter\Observing;


/**
 * Shared Observing-event fixture for the Emitter suite — the isolated,
 * observers-only listener contract.
 *
 * Required (not autoloaded) by each test file so the enum is declared once
 * across the whole suite run.
 */
enum Observations implements Observing
{
   case Gamma;
}
