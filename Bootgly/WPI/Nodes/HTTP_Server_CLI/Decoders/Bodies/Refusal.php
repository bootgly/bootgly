<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Decoders\Bodies;


use RuntimeException;


/**
 * Thrown when the worker memory budget refuses what a deferred request keeps:
 * its snapshot (`Request::capture()`, through `Response::defer()`) or what
 * its deferred work parses later — form or JSON fields, cookies, query vars,
 * languages, credentials.
 *
 * It is the server's own capacity policy, not an application error: the
 * application may catch it and answer whatever it wants; left unhandled, the
 * server answers `503 Service Unavailable` and reports nothing.
 */
final class Refusal extends RuntimeException
{
}
