<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\API;


/**
 * Remote procedure call protocols, transport-independent: a dispatcher takes a
 * raw payload and returns the raw answer, so the same methods serve HTTP, a
 * WebSocket or stdio.
 *
 * - `RPC\JSON` — JSON-RPC 2.0;
 * - `RPC\MCP` — the Model Context Protocol server core, over `RPC\JSON`.
 */
interface RPC
{
}
