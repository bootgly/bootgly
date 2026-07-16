<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Data\__String\Markdown;


/**
 * Inline-level Markdown node types.
 */
enum Inlines
{
   case Text;
   case Bold;
   case Italic;
   case Strike;
   case Code;
   case Link;
   case Image;
   case Break;
}
