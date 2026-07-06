<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
