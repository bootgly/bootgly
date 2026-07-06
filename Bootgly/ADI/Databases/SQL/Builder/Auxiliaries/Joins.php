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
 * SQL join types.
 */
enum Joins: string
{
   case Full = 'FULL JOIN';
   case Inner = 'JOIN';
   case Left = 'LEFT JOIN';
   case Right = 'RIGHT JOIN';
}
