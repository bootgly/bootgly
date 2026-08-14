<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\ACI\Events;


/**
 * Marker for a contextual scheduler that observes Cancellation generations.
 *
 * The capability deliberately adds no methods: legacy Contextualizing
 * implementations remain source-compatible, while an observed HTTP defer can
 * fail before cloning or moving uploads when its scheduler cannot guarantee
 * deterministic cancellation.
 */
interface Cancelling extends Contextualizing
{
}
