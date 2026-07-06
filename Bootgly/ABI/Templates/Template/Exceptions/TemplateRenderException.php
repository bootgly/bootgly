<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
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
