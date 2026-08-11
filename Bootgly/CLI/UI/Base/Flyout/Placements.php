<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UI\Base\Flyout;


/**
 * Where a Flyout block opens, relative to the anchor row — the cursor row the
 * host paints against.
 */
enum Placements
{
   case Above;
   case Below;
}
