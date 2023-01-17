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


use Closure;
use Bootgly\{
  SAPI\Environment
};


class SAPI
{
  // * Config
  public static string $sapi = HOME_DIR . 'projects/sapi.constructor.php';
  // * Data
  // * Meta
  public static Closure $Handler;

  public object $Environment;


  public function __construct ()
  {
    // TODO
    #$this->Environment = new Environment($this);

    #$Environment  = &$this->Environment;
  }

  public static function boot ($reset = false)
  {
    if ($reset) {
      if ( function_exists('opcache_invalidate') )
        opcache_invalidate(self::$sapi, true);

      self::$Handler = require(self::$sapi);
    }

    return self::$Handler;
  }
}
