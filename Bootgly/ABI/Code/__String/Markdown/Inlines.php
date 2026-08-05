<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Code\__String\Markdown;


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
