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


use Throwable;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


/**
 * Error boundary for deferred response work.
 *
 * The route onion is synchronous: `process()` returns as soon as the handler
 * returns, and a handler that calls `$Response->defer()` returns at once — the
 * work runs later, on a pooled Fiber, after every middleware frame has
 * unwound. A boundary written as `try { $next() } catch` can therefore never
 * see a Throwable raised inside deferred work: `$next` already returned, and
 * the server answered with the built-in Catcher (500, or 503 for a
 * `Response\Timeout`).
 *
 * A middleware that also implements this contract is offered those
 * Throwables. When deferred work fails, the route's middleware chain is walked
 * innermost first, then the global `SAPI::$Middlewares` stack, and the first
 * Response returned is serialized. The walk runs inside the deferred Fiber
 * with the request context bound: `$Request` is the generation's captured
 * snapshot (`$Response->Request`) and `$Response` is the private deferred
 * clone, carrying whatever the work wrote before it threw — as a synchronous
 * boundary's `$Response` carries what the handler wrote — so answering in
 * place means owning what is already there (headers, a first-touch session
 * cookie, a queued file); a boundary that wants a representation of its own
 * returns a fresh Response instead. Work that already handed the generation
 * off — an open SSE stream, a nested `defer()` — is offered to no boundary
 * at all: a Throwable raised after that handoff settles nothing further.
 *
 * - Return `null` to decline: the next boundary outward is offered the same
 *   Throwable and, past the outermost one, the Catcher answers.
 * - Return `$Response` after answering in place, or a new Response; a fresh
 *   one is bound to this request's transport, request, exchange and
 *   cancellation generation before serialization.
 * - Throwing hands the NEW Throwable to the next boundary outward, exactly as
 *   a rethrow inside `process()` reaches the enclosing middleware.
 * - `Response\Timeout` is offered as well. It is a server budget, not an
 *   application error — logged as a warning before any boundary is consulted,
 *   whichever answer wins: decline it to keep the 503, or answer with an
 *   explicit unavailability of your own.
 * - Reporting follows the answer: the core's single `Throwables::notify()`
 *   intake runs only when the Catcher answers, so a boundary that answers a
 *   generic Throwable owns the report — exactly as a synchronous `catch`
 *   around `$next` always has.
 * - Answer synchronously. `wait()` is not refused, but the walk is not
 *   unbounded: when the generation has a budget (`defer(timeout:)` or
 *   `deferredTimeout`), a parked boundary is interrupted with a fresh
 *   `Response\Timeout` at its wait point after one budget re-armed for the
 *   walk — that Timeout REPLACES the Throwable that was offered, travels
 *   outward like any throwing `recover()`, and ends on the Catcher's 503
 *   when nobody else answers. Without a budget only transport teardown
 *   bounds it. Nothing is serialized once the generation settled —
 *   including after a nested `defer()` or SSE handoff made from inside
 *   `recover()`. A nested child inherits this chain: a child that throws is
 *   offered to the same boundaries again.
 * - The boundaries are the chain the route was dispatched with, captured
 *   while the onion runs: a deferral started after the onion returned (a
 *   `Request\Events::Handled` listener, a global middleware after its
 *   `$next`) carries no route chain and only reaches the global pipeline —
 *   of which only `SAPI::$Middlewares->stack` is read; a `Middlewares`
 *   subtype composing its pipeline elsewhere is not visible to deferred
 *   work.
 *
 * `process()` keeps its synchronous role; one class serves both paths.
 */
interface Recovering extends Middleware
{
   /**
    * Answer a Throwable raised by deferred work, or decline.
    *
    * @param Request $Request The generation's captured Request snapshot.
    * @param Response $Response The deferred Response clone, as the work left it.
    * @param Throwable $Throwable The failure — a `Response\Timeout` when the budget elapsed.
    *
    * @return null|Response The Response to serialize, or null to decline.
    */
   public function recover (Request $Request, Response $Response, Throwable $Throwable): null|Response;
}
