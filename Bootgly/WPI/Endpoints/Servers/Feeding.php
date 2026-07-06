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
 * A stateful per-connection Decoder that can absorb raw transport bytes
 * without dispatching them. The TCP read loop hands over the un-dispatched
 * remainder of a read when it must return early (deferred write /
 * backpressure), so no bytes are ever lost between read cycles — required
 * by framed protocols (e.g. HTTP/2) where the byte stream cannot be
 * re-synchronized after a gap.
 */
interface Feeding
{
   public function feed (string $buffer): void;
}
