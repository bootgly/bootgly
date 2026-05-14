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
 * SQL join types.
 */
enum Joins: string
{
   case Full = 'FULL JOIN';
   case Inner = 'JOIN';
   case Left = 'LEFT JOIN';
   case Right = 'RIGHT JOIN';
}
