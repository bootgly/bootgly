<?php
/*
 * --------------------------------------------------------------------------
 * Bootgly PHP Framework
 * Developed by Rodrigo Vieira (@rodrigoslayertech)
 * Copyright (c) 2023-present Bootgly and contributors
 * Licensed under MIT
 * --------------------------------------------------------------------------
 */


use Bootgly\ACI\Tests\Suite\Test;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;


/**
 * A copy of a Request — `clone`, or the snapshot `capture()` parks for a
 * deferral — parses the cookies of its own head.
 *
 * `Raw\Header` creates its `Cookies` lazily, bound to the Header it was asked
 * on, and parses them on first read. A copy made after the object was created
 * but before it was read shared it — still bound to the live Header, which the
 * connection's next request re-defines — so the parked work read the NEXT
 * request's cookies.
 */
return new Test(
   description: 'A copied Request reads the cookies of its own head',
   test: function () {
      // ! The live per-connection Request after its first request decoded
      $Live = new Request;
      $Live->Header->define("cookie: who=first\r\n");
      $Live->Header->adopt(['cookie' => 'who=first']);
      // @ Reached, not read: the object exists, its cookies are not parsed yet
      $Bound = $Live->Cookies;

      // @ The copies a deferral and a decode template make
      $Clone = clone $Live;
      $Captured = $Live->capture();

      // @ The connection's next request re-defines the live head
      $Live->Header->define("cookie: who=second\r\n");
      $Live->Header->adopt(['cookie' => 'who=second']);

      // !
      $read = [
         'clone' => $Clone->Cookies->get('who'),
         'captured' => $Captured->Cookies->get('who'),
         'live' => $Live->Cookies->get('who'),
         'bound' => $Bound->get('who'),
      ];
      $Captured->clean();
      $json = json_encode($read);

      yield assert(
         assertion: $read['clone'] === 'first' && $read['captured'] === 'first',
         description: "Each copy reads the cookies of its own head: {$json}",
      );

      yield assert(
         assertion: $read['live'] === 'second' && $read['bound'] === 'second',
         description: "The live Request — and an object taken from it — read the next request's: {$json}",
      );
   },
);
