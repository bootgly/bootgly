<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\_\Content;


use Bootgly\Web\HTTP\Server\_\Content;


final class Downloader
{
   public Content $Content;


   public function __construct (Content $Content)
   {
      $this->Content = $Content;
   }
}
