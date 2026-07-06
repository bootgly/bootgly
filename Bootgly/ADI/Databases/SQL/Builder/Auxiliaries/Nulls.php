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
 * SQL NULL ordering modifiers.
 */
enum Nulls: string
{
   case First = 'NULLS FIRST';
   case Last = 'NULLS LAST';
}
