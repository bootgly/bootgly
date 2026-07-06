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
 * A protocol unit attached to `Packages::$decoded` that needs deterministic
 * teardown when the transport connection closes (e.g. a WebSocket Session).
 * `Connection::close()` invokes `disconnect()` on any close path, so cleanup
 * runs exactly once even on an abrupt peer EOF.
 */
interface Disconnecting
{
   public function disconnect (): void;
}
