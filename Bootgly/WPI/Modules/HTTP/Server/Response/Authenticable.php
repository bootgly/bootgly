<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Modules\HTTP\Server\Response;


use Bootgly\WPI\Modules\HTTP\Server\Response\Authentication;


trait Authenticable
{
   /**
    * Authenticate the user with the provided authentication method.
    *
    * @param Authentication $Method The authentication method to use.
    *
    * @return self The Response instance, for chaining
    */
   public function authenticate (Authentication $Method) : self
   {
      $this->__set('code', 401);

      switch ($Method) {
         case $Method instanceof Authentication\Basic:
            $this->Raw->Header->set(
               'WWW-Authenticate',
               'Basic realm="' . $Method->realm . '"'
            );
            break;
      }

      return $this;
   }
}
