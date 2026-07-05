<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright 2023-present
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

// Unified project registry — the security allow-list.
//
// Only the project paths declared here may be started (`bootgly project <path> start`)
// or autobooted by the Web platform. Each key is the project's canonical path,
// relative to this `projects/` directory, at any depth (subprojects). Each value
// binds the project to one or more interfaces:
//   - CLI → Console platform
//   - WPI → Web platform (the first WPI entry is the web SAPI default)

// The wizard (`bootgly project create`) fills this list when a project is
// created or imported.
return [];
