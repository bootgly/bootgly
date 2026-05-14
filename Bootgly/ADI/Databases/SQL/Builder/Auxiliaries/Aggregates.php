<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Builder\Auxiliaries;


/**
 * SQL aggregate functions.
 */
enum Aggregates: string
{
   case Average = 'AVG';
   case Maximum = 'MAX';
   case Minimum = 'MIN';
   case Sum = 'SUM';
}
