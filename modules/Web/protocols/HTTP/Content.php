<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\protocols\HTTP;


abstract class Content
{
   public $input;


   public function __construct (? string $input = null)
   {
      $this->input = $input !== null ? $input : file_get_contents('php://input');
   }
}
