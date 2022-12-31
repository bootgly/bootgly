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


class Bootgly
{
   // ***

   public Project $Project;
   public Template $Template;


   public function __construct ()
   {
      $this->Project = new Project($this);
      $this->Template = new Template($this);
   }
}
