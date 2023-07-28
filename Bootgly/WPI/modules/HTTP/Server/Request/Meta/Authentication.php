<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\modules\HTTP\Server\Request\Meta;


use Bootgly\WPI\modules\HTTP\Server\Request\Meta;


final class Authentication
{
   public Meta $Meta;


   public function __construct (Meta $Meta)
   {
      $this->Meta = $Meta;
   }
}
