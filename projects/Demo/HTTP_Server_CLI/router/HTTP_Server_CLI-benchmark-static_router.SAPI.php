<?php

namespace projects\HTTP_Server_CLI\router;


use function explode;
use function count;
use function ltrim;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


$static = [
   '/'        => 'Home',
   '/about'   => 'About',
   '/contact' => 'Contact',
   '/blog'    => 'Blog',
   '/pricing' => 'Pricing',
   '/docs'    => 'Docs',
   '/faq'     => 'FAQ',
   '/terms'   => 'Terms',
   '/privacy' => 'Privacy',
   '/status'  => 'Status',
];

return static function
(Request $Request, Response $Response) use ($static)
{
   // @ Static routes
   $path = $Request->URI;

   if (isSet($static[$path])) {
      return $Response(body: $static[$path]);
   }

   // @ Dynamic routes
   $parts = explode('/', ltrim($path, '/'));

   if (count($parts) === 2) {
      if ($parts[0] === 'user') {
         return $Response(body: 'User: ' . $parts[1]);
      }
      if ($parts[0] === 'post') {
         return $Response(body: 'Post: ' . $parts[1]);
      }
   }

   if (count($parts) === 3 && $parts[0] === 'api' && $parts[1] === 'v1') {
      return $Response(body: 'API: ' . $parts[2]);
   }

   // @ Catch-all 404
   return $Response(code: 404, body: 'Not Found');
};
