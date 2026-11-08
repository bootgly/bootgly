<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\TrustedProxy;


/**
 * Where `TrustedProxy` reads the client address once the socket peer is
 * trusted.
 *
 * Every strategy only runs behind a trusted socket peer, and none of them can
 * tell a header the edge wrote from one the client wrote: each one names the
 * deployment shape it is sound for. Picking one is an operator decision about
 * the edge in front of the server.
 */
enum Strategies: string
{
   /**
    * Walk `X-Forwarded-For` from the right, skipping trusted hops: the first
    * untrusted entry is the client. Without `X-Forwarded-For`, `X-Real-IP` is
    * read instead. Sound when every proxy that appends to the chain is in the
    * trust list (AWS ALB, HAProxy `option forwardfor`, Cloudflare ranges).
    * The default.
    */
   case Rightmost = 'rightmost';
   /**
    * The left-most `X-Forwarded-For` entry. Sound ONLY behind an edge that
    * overwrites the header (client copies dropped) and whose hops' ranges are
    * not published, so the right-most walk would stop at an edge hop. Behind
    * an edge that appends to or passes on a client's header, any client
    * chooses its own address.
    */
   case Leftmost = 'leftmost';
   /**
    * One named header the edge sets itself (`X-Real-IP`, `CF-Connecting-IP`,
    * `Fly-Client-IP`), given as `TrustedProxy`'s `header`. Sound ONLY when
    * the edge overwrites that header on every request.
    */
   case Header = 'header';
}
