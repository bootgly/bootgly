<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Templates\Template\Exceptions;


use Exception;
use Throwable;


class TemplateRenderException extends Exception
{
   public function __construct (string $filename, Throwable $T)
   {
      // $this->message
      $this->file = $filename;
      // $this->code
      $this->line = $T->getLine();

      parent::__construct('Error when rendering the Bootgly template.');
   }
}
