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
 * Block-level Markdown node types.
 */
enum Blocks
{
   case Heading;
   case Paragraph;
   case Fence;
   case Quote;
   case List;
   case Item;
   case Table;
   case Row;
   case Cell;
   case Rule;
}
