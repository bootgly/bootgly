<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ADI\Databases\SQL;


/**
 * SQL operation await bridge for platform-safe eager loading.
 */
interface Awaiting
{
   /**
    * Await one SQL operation until it finishes or fails.
    */
   public function await (Operation $Operation): Operation;
}
