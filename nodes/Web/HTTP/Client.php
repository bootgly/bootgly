<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP;


use Bootgly\Requestable;

use Bootgly\Web;

use Bootgly\Web\HTTP\Client\Request;
use Bootgly\Web\HTTP\Client\Response;


class Client extends Web\Client implements Requestable
{
   use Web\protocols\HTTP;


   public function request () : Response
   {
      $Request = new Request;

      return $this->Response;
   }
}
