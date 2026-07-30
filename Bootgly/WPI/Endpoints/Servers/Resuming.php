<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Rodrigo de Araujo Vieira Tecnologia da Informação LTDA and Bootgly contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Endpoints\Servers;


/**
 * A protocol unit whose locally-generated output may outlive one transport
 * write batch. The TCP writer invokes `resume()` after its ordered queue
 * becomes idle, allowing the protocol to materialize the next bounded slice.
 */
interface Resuming
{
   public function resume (): void;
}
