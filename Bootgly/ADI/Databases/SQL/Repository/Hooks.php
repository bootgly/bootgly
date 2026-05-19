<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Repository;


/**
 * ORM repository lifecycle hooks.
 */
enum Hooks
{
   case Deleted;
   case Deleting;
   case Hydrated;
   case Hydrating;
   case Saved;
   case Saving;
   case Selected;
   case Selecting;
}
