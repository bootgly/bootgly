<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;


use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Sealing pass for deferred response work.
 *
 * The route onion is synchronous: the code a middleware runs AFTER `$next()`
 * — a policy header, a validator, a body encoding — mutates the live
 * Response, while a deferred generation answers through a private clone taken
 * inside `defer()`, before the onion unwound. Those mutations never reach the
 * deferred wire, so a post-`$next()` decorator is silently lost on a deferred
 * route (a PRE-`$next()` mutation survives: the clone inherits it).
 *
 * A middleware that also implements this contract is offered the deferred
 * Response at settlement, immediately before serialization — after the work
 * completed, or after an error boundary or the Catcher chose the answer —
 * with the REAL outcome in place: final status, final headers, final body.
 * The walk order mirrors the synchronous unwind: the route's middleware
 * snapshot innermost first, then the global `SAPI::$Middlewares` stack.
 *
 * - Mutate the Response in place — `seal()` returns nothing. Set headers,
 *   rewrite the body, or answer a conditional (`$Response(code: 304, ...)`):
 *   `__invoke` mutates the same object.
 * - On the success path a throw from `seal()` skips the remaining seals and
 *   flows to the `Recovering` boundaries, exactly as a throw after `$next()`
 *   skips the outer post-code and reaches the enclosing middleware; the
 *   chosen error answer is then offered to the sealing pass anew — so seal
 *   idempotently, the way `Header->set()` naturally is.
 * - On the errored path (a boundary's or the Catcher's answer) a throwing
 *   seal is contained and reported: the answer already chosen is never
 *   forfeited for a decorator.
 * - Seal synchronously. `wait()` is not refused: on the success path the
 *   generation's budget deadline is still armed, and a parked seal is
 *   interrupted with `Response\Timeout` exactly like the work itself.
 * - Session writes made by a seal persist with the generation — the pass
 *   runs before the deferred save point.
 * - The chain is the one the route was dispatched with, captured while the
 *   onion ran; of the global pipeline only `SAPI::$Middlewares->stack` is
 *   read — the same visibility `Recovering` documents.
 *
 * A capability, not a second door: `Sealing` deliberately does NOT extend
 * `Middleware` — the pipeline's one way in stays `implements Middleware`
 * (One-Way policy), and a sealing middleware declares both:
 * `implements Middleware, Sealing`. Parity stays the author's job:
 * `process()` keeps its synchronous role, and sharing the logic makes one
 * class serve both cycles — through a private method both call when
 * `process()` must also take test doubles (the shipped `ETag`/`Compression`
 * do), or by calling `seal()` right after `$next()`.
 */
interface Sealing
{
   /**
    * Decorate a deferred Response about to be serialized.
    *
    * @param Request $Request The generation's captured Request snapshot.
    * @param Response $Response The Response chosen for the wire — the work's, a boundary's or the Catcher's.
    */
   public function seal (Request $Request, Response $Response): void;
}
