<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
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
