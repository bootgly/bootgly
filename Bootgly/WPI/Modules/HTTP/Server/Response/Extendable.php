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
    * @return self The Response instance, for chaining
    */
   public function append ($body): self
   {
      $this->initied = true;
      $this->content .= $body . "\n";

      return $this;
   }

   /**
    * Export variables to the File Response.
    *
    * @param array<string> $variables Variables to be passed to the File Response.
    *
    * @return self The Response instance, for chaining
    */
   public function export (array ...$variables): self
   {
      foreach ($variables as $var) {
         foreach ($var as $key => $value) {
            $this->uses[$key] = $value;
         }
      }

      return $this;
   }
}
