<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
