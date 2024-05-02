<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_\Request;


class Session
{
   // * Config
   public string $name;

   // * Data
   // ...

   // * Metadata
   // ...


   public function __construct (string $name = '')
   {
      // * Config
      $this->name = $name;

      // * Data
      // ...

      // * Metadata
      // ...
   }
   public function __get ($name)
   {
      switch ($name) {
         // * Metadata
         case 'started':
            return $_SESSION['started'] ?? false;
         case 'id':
            return @session_id();

         default:
            if ($_SESSION['started'] ?? false) {
               return null;
            }

            return $_SESSION[$name] ?? null;
      }
   }

   public function __set (string $name, $value)
   {
      switch ($name) {
         // * Metadata
         case 'started':
            $_SESSION['started'] = $value;

            break;

         default:
            if ($_SESSION['started'] ?? false) {
               $_SESSION[$name] = $value;
            }
      }
   }


   public function start (string $id = '', array $options = []) : bool
   {
      if ($this->name) {
         session_name($this->name);
      }

      session_write_close();

      if ($id) {
         @session_id($id);
      }

      if ($options === []) {
         $options = [
            'cookie_path' => '/',
            'cookie_lifetime' => 43200,
            'cookie_secure' => true,
            'cookie_httponly' => true
         ];
      }

      $started = @session_start($options);

      if ($started) {
         $_SESSION['started'] = true;
      }

      return $started;
   }

   public function destroy (string $id = '') : bool
   {
      $started = $this->__get('started');
      if ($started === false) {
         $started = $this->start($id);
      }

      if ($started === true) {
         $_SESSION = [];

         if ( ini_get("session.use_cookies") ) {
            $params = session_get_cookie_params();
            setcookie(
               session_name(),
               '',
               time() - 42000,
               $params["path"],
               $params["domain"],
               $params["secure"],
               $params["httponly"]
            );
         }
      }

      return session_destroy();
   }
}
