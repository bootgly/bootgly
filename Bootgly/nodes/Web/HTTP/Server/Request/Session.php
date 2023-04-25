<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2020-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\Web\HTTP\Server\Request;


class Session
{
   public string $name = '';


   public function __construct ()
   {
      // TODO
   }
   public function __get ($index)
   {
      switch ($index) {
         case 'started':
            if ( isset($_SESSION['started']) ) {
               return $_SESSION['started'];
            }

            return false;
         case 'id':
            return @session_id();
         default:
            if ( isset($_SESSION['started']) ) {
               if ( isset($_SESSION[$index]) ) {
                  return $_SESSION[$index];
               }

               return NULL;
            }
      }
   }

   public function __set ($index, $value)
   {
      switch ($index) {
         case 'started':
            $_SESSION['started'] = $value;

            break;
         default:
            if ( isset($_SESSION['started']) ) {
               $_SESSION[$index] = $value;
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

      if (empty($options)) {
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

   public function destroy (string $id = '')
   {
      if (!$this->started) {
         $this->start($id);
      }

      if ($this->started) {
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
