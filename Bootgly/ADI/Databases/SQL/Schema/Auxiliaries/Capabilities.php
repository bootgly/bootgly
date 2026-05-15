<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Schema\Auxiliaries;


/**
 * Schema dialect feature capabilities.
 */
enum Capabilities
{
   case AddConstraint;
   case AlterColumnDefault;
   case AlterColumnNullability;
   case AlterColumnType;
   case AlterColumnUsing;
   case DropColumn;
   case DropConstraint;
   case MultiActionAlter;
   case RenameColumn;
}
