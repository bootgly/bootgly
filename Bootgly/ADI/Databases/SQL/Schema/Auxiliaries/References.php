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
 * Foreign key referential actions.
 */
enum References
{
   case Cascade;
   case NoAction;
   case Restrict;
   case SetDefault;
   case SetNull;
}