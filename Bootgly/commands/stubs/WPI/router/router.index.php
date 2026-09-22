<?php

/**
 * Router index — the manifest read by `Router::load()`.
 *
 * Returns the active route set names. Each name resolves to `routes/<Name>.routes.php`
 * (a generator-closure `(Request, Response, Router): Generator`).
 */

return [
   'Welcome',
];
