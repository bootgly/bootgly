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
 * SQL filter operators.
 */
enum Operators: string
{
   case Between = 'BETWEEN';
   case Equal = '=';
   case Greater = '>';
   case GreaterOrEqual = '>=';
   case In = 'IN';
   case IsFalse = 'IS FALSE';
   case IsNotNull = 'IS NOT NULL';
   case IsNull = 'IS NULL';
   case IsTrue = 'IS TRUE';
   case Less = '<';
   case LessOrEqual = '<=';
   case Unequal = '<>';
}
