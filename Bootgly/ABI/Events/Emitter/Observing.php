<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ABI\Events\Emitter;


use Bootgly\ABI\Event;


/**
 * Marks an event whose listeners only observe — they never steer.
 *
 * The Emitter isolates each listener of an Observing event: a listener
 * Throwable is reported through `Throwables::notify()` and never escapes into
 * the emitting engine path, blinds later listeners, or aborts delivery.
 * Events without this marker keep the steering contract: a listener Throwable
 * propagates to the emitter, which may treat it as control flow (a refusal
 * gate, a bounded error boundary).
 */
interface Observing extends Event
{
}
