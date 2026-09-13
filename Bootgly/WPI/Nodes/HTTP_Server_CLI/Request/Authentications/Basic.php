<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Request\Authentications;


final class Basic
{
   // * Config
   /** The user identifier decoded from the `Authorization: Basic` credentials. */
   public string $username;
   /** The password decoded from the `Authorization: Basic` credentials. */
   public string $password;


   public function __construct (string $username, string $password)
   {
      // * Config
      $this->username = $username;
      $this->password = $password;
   }
}
