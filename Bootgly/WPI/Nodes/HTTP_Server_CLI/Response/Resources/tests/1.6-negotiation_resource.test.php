<?php

namespace Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Tests\NegotiationResource;


use function assert;

use Bootgly\ACI\Tests\Suite\Test\Specification;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response\Resources\Negotiation;


return new Specification(
   description: 'Response Negotiation resource: server-side Accept offer matching',
   test: function () {
      // Offers when a view is available (HTML in play) and when it is not (API only)
      $offers = ['application/json', 'application/xml', 'text/html'];
      $api = ['application/json', 'application/xml'];

      // @ Exact media type match
      yield assert(
         assertion: Negotiation::choose(['application/json'], $offers) === 'application/json',
         description: 'Exact media type is selected'
      );

      // @ Client preference order wins (xml before json)
      yield assert(
         assertion: Negotiation::choose(['application/xml', 'application/json'], $offers) === 'application/xml',
         description: 'The most-preferred acceptable type wins'
      );

      // @ HTML selected when offered
      yield assert(
         assertion: Negotiation::choose(['text/html'], $offers) === 'text/html',
         description: 'text/html is selected when offered'
      );

      // @ Full wildcard → the server's first offer
      yield assert(
         assertion: Negotiation::choose(['*/*'], $offers) === 'application/json',
         description: '*/* falls back to the first offer'
      );

      // @ Bare * → the server's first offer
      yield assert(
         assertion: Negotiation::choose(['*'], $offers) === 'application/json',
         description: 'bare * falls back to the first offer'
      );

      // @ Type wildcard → first offer of that type
      yield assert(
         assertion: Negotiation::choose(['application/*'], $offers) === 'application/json',
         description: 'application/* picks the first application offer'
      );
      yield assert(
         assertion: Negotiation::choose(['text/*'], $offers) === 'text/html',
         description: 'text/* picks the first text offer'
      );

      // @ No overlap → null (caller returns 406)
      yield assert(
         assertion: Negotiation::choose(['image/png'], $offers) === null,
         description: 'An unsatisfiable Accept yields no match'
      );

      // @ Empty Accept → null (caller serves the default)
      yield assert(
         assertion: Negotiation::choose([], $offers) === null,
         description: 'No Accept preferences yield no match'
      );

      // @ HTML preferred but not offered (no view) → null
      yield assert(
         assertion: Negotiation::choose(['text/html'], $api) === null,
         description: 'HTML wins the Accept but is not offered without a view'
      );

      // @ HTML unavailable degrades to the next acceptable offer
      yield assert(
         assertion: Negotiation::choose(['text/html', 'application/json'], $api) === 'application/json',
         description: 'Negotiation degrades past an unoffered type to the next acceptable one'
      );
   }
);
