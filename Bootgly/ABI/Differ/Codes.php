<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Differ;


// @ Diff entry codes used in the internal diff array.
//   Each diff entry is `[string $content, int $code]`.
enum Codes: int
{
   case OLD                  = 0;
   case ADDED                = 1;
   case REMOVED              = 2;
   case LINE_END_WARNING     = 3;
   case LINE_END_EOF_WARNING = 4;
}
