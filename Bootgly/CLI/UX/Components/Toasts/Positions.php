<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\CLI\UX\Components\Toasts;


/**
 * Screen position anchoring a toast stack — top positions grow downward,
 * bottom positions grow upward and Center centers the whole block vertically
 * (growing downward). Left/Center/Right set the horizontal alignment of each
 * box.
 */
enum Positions
{
   case TopLeft;
   case TopCenter;
   case TopRight;
   case Center;
   case BottomLeft;
   case BottomCenter;
   case BottomRight;
}
