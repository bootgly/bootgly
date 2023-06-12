<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

use Web\App;
use Web\API;


#[\AllowDynamicProperties]
class Web extends Bootgly\Web
{
   public App $App;
   public API $API;
}
