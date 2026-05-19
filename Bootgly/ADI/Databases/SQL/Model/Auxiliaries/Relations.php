<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL\Model\Auxiliaries;


/**
 * ORM relation kinds.
 */
enum Relations
{
   case BelongsTo;
   case BelongsToMany;
   case HasMany;
   case HasOne;
}
