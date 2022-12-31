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
   Project,
};


class Web
{
   public Bootgly $Bootgly;
   public Project $Project;


   public function __construct (Bootgly $Bootgly)
   {
      if (@$_SERVER['REDIRECT_URL'] === NULL) {
         return;
      }

      // Instance parents
      // @ core
      $this->Bootgly = &$Bootgly;
      $this->Project = &$Bootgly->Project;

      // Export variables
      $Bootgly  = &$this->Bootgly;
      $Project  = &$this->Bootgly->Project;

      // Load Web constructor
      @include $Project::PROJECT_DIR . 'web.constructor.php';
   }
}
