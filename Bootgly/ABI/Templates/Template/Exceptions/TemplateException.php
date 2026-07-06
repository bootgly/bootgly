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

use Bootgly\ABI\Templates\Template\Exceptioning;


final class TemplateException extends Exception implements Exceptioning
{
   // * Data
   /**
    * Source template file (null = inline template).
    */
   public protected(set) null|string $template;

   // * Metadata
   /**
    * Whether file/line already point at a template source line —
    * Template::render() maps unlocated instances through the trace.
    */
   public protected(set) bool $located;


   public function __construct (
      string $message,
      null|string $template = null,
      null|int $line = null,
      null|Throwable $previous = null
   ) {
      parent::__construct($message, 0, $previous);

      // * Data
      $this->template = $template;

      // @ Point file/line at the template source only when BOTH are known —
      //   never mix a template file with an engine-internal line
      if ($template !== null && $line !== null) {
         // * Metadata
         $this->located = true;

         $this->file = $template;
         $this->line = $line;
      }
      else {
         // * Metadata
         $this->located = false;
      }
   }
}
