<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly;


use Bootgly\{
  SAPI\Environment
};


class SAPI
{
  // * Data
  // * Meta

  public object $Environment;


  public function __construct ()
  {
    $this->Environment = new Environment($this);

    $Environment  = &$this->Environment;
  }

  public function __get($name)
  {
    switch ($name) {
      case 'name':
        return $this->name = php_sapi_name();
        break;
      default:
        return $this->name;
        break;
    }
  }
}
