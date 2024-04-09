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


trait Extendable
{
   /**
    * Appends the provided data to the body of the response.
    *
    * @param mixed $body The data that should be appended to the response body.
    *
    * @return Response The Response instance, for chaining
    */
   public function append ($body) : self
   {
      $this->initied = true;
      $this->body .= $body . "\n";

      return $this;
   }

   /**
    * Extends variables to the File Response.
    *
    * @param string $name Variable name to be used.
    * @param mixed $var Variable value passed to the File.
    *
    * @return Response The Response instance, for chaining
    */
   public function use (array ...$variables) : self
   {
      foreach ($variables as $var) {
         foreach ($var as $key => $value) {
            $this->uses[$key] = $value;
         }
      }

      return $this;
   }
}
