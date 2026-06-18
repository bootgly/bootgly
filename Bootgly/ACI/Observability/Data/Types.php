<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Observability\Data;


enum Types: string
{
   // Metric instrument kinds — the backing value is used verbatim in exported output.
   case Counter   = 'counter';
   case Gauge     = 'gauge';
   case Histogram = 'histogram';
}
