<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
