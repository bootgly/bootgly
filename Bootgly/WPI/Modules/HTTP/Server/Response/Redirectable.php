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


trait Redirectable
{
   // \
   private static $Server;


   /**
    * Redirects to a new URI. Default return is 307 for GET (Temporary Redirect) and 303 (See Other) for POST.
    *
    * @param string $URI The new URI to redirect to.
    * @param ? int $code The HTTP status code to use for the redirection.
    *
    * @return self Returns Response.
    */
   public function redirect (string $URI, ? int $code = null) : self
   {
      // !?
      switch ($code) {
         case 300: // Multiple Choices
         case 301: // Moved Permanently
         case 302: // Found (or Moved Temporarily)
         case 303: // See Other
         case 307: // Temporary Redirect
         case 308: // Permanent Redirect

            break;
         default:
            $code = null;
      }

      // ? Set default code
      if ($code === null) {
         $code = match (self::$Server::$Request->method) {
            'POST' => 303, // See Other
            default => 307 // Temporary Redirect
         };
      }

      // @
      $this->__set('code', $code);
      $this->Header->set('Location', $URI);
      $this->end();

      return $this;
   }
}
