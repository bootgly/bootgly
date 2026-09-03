<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI;


use Bootgly\ACI\VCS\Git;
use Bootgly\ACI\VCS\Remotes;
use Bootgly\ACI\VCS\Submodules;
use Bootgly\ACI\VCS\Tags;


/**
 * Version control of one working tree — git, through its own binary.
 *
 * The engine (`Git`) runs the commands; `Remotes`, `Submodules` and `Tags`
 * read and act on the three things a checkout is made of beyond its files.
 * Nothing here knows what the tree is for: the kit, a project, a fixture —
 * the caller does.
 */
class VCS
{
   // * Data
   public private(set) Git $Git;
   public private(set) Remotes $Remotes;
   public private(set) Submodules $Submodules;
   public private(set) Tags $Tags;


   /**
    * Bind a working tree.
    *
    * @param string $path The working tree.
    * @param null|string $binary The `git` binary; looked up on `PATH` when omitted.
    */
   public function __construct (string $path, null|string $binary = null)
   {
      // * Data
      $this->Git = new Git($path, $binary);
      $this->Remotes = new Remotes($this->Git);
      $this->Submodules = new Submodules($this->Git);
      $this->Tags = new Tags($this->Git);
   }
}
