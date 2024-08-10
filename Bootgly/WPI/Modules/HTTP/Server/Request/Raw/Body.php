<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Request\Raw;


abstract class Body
{
   // * Config
   // ...

   // * Data
   public string $raw;
   public ?string $input;

   // * Metadata
   public ?int $length;
   public null|int|false $position;
   public ?int $downloaded;
}
