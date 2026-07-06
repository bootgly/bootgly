<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resource\Scheduling;


/**
 * Base response resource definition.
 */
abstract class Resource
{
   // * Config
   // ...

   // * Data
   public private(set) bool $persistent;

   // * Metadata
   public private(set) bool $async;


   public function __construct (bool $persistent = false)
   {
      // * Data
      $this->persistent = $persistent;

      // * Metadata
      $this->async = $this instanceof Scheduling;
   }
}
