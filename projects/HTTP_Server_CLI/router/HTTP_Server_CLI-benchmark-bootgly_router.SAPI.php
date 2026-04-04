<?php

namespace projects\HTTP_Server_CLI\router;


use Generator;

use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Router\Middlewares\RequestId;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // @ Static routes (10)
   yield $Router->route('/', function (Request $Request, Response $Response) {
      return $Response(body: 'Home');
   }, GET);
   yield $Router->route('/about', function (Request $Request, Response $Response) {
      return $Response(body: 'About');
   }, GET);
   yield $Router->route('/contact', function (Request $Request, Response $Response) {
      return $Response(body: 'Contact');
   }, GET);
   yield $Router->route('/blog', function (Request $Request, Response $Response) {
      return $Response(body: 'Blog');
   }, GET);
   yield $Router->route('/pricing', function (Request $Request, Response $Response) {
      return $Response(body: 'Pricing');
   }, GET);
   yield $Router->route('/docs', function (Request $Request, Response $Response) {
      return $Response(body: 'Docs');
   }, GET);
   yield $Router->route('/faq', function (Request $Request, Response $Response) {
      return $Response(body: 'FAQ');
   }, GET);
   yield $Router->route('/terms', function (Request $Request, Response $Response) {
      return $Response(body: 'Terms');
   }, GET);
   yield $Router->route('/privacy', function (Request $Request, Response $Response) {
      return $Response(body: 'Privacy');
   }, GET);
   yield $Router->route('/status', function (Request $Request, Response $Response) {
      return $Response(body: 'Status');
   }, GET);

   // @ Dynamic routes (10)
   yield $Router->route('/user/:id', function (Request $Request, Response $Response) {
      return $Response(body: 'User: ' . $this->Params->id);
   }, GET);
   yield $Router->route('/post/:slug', function (Request $Request, Response $Response) {
      return $Response(body: 'Post: ' . $this->Params->slug);
   }, GET);
   yield $Router->route('/api/v1/:resource', function (Request $Request, Response $Response) {
      return $Response(body: 'API: ' . $this->Params->resource);
   }, GET);
   yield $Router->route('/category/:name', function (Request $Request, Response $Response) {
      return $Response(body: 'Category: ' . $this->Params->name);
   }, GET);
   yield $Router->route('/tag/:label', function (Request $Request, Response $Response) {
      return $Response(body: 'Tag: ' . $this->Params->label);
   }, GET);
   yield $Router->route('/product/:sku', function (Request $Request, Response $Response) {
      return $Response(body: 'Product: ' . $this->Params->sku);
   }, GET);
   yield $Router->route('/order/:code', function (Request $Request, Response $Response) {
      return $Response(body: 'Order: ' . $this->Params->code);
   }, GET);
   yield $Router->route('/invoice/:number', function (Request $Request, Response $Response) {
      return $Response(body: 'Invoice: ' . $this->Params->number);
   }, GET);
   yield $Router->route('/review/:rid', function (Request $Request, Response $Response) {
      return $Response(body: 'Review: ' . $this->Params->rid);
   }, GET);
   yield $Router->route('/comment/:cid', function (Request $Request, Response $Response) {
      return $Response(body: 'Comment: ' . $this->Params->cid);
   }, GET);

   // @ Nested routes (route groups)
   yield $Router->route('/admin/:*', function () use ($Router) {
      yield $Router->route('dashboard', function ($Request, $Response) {
         return $Response(body: 'Admin Dashboard');
      }, GET);
      yield $Router->route('settings', function ($Request, $Response) {
         return $Response(body: 'Admin Settings');
      }, GET);
      yield $Router->route('users', function ($Request, $Response) {
         return $Response(body: 'Admin Users');
      }, GET);
   }, GET);

   yield $Router->route('/account/:*', function () use ($Router) {
      yield $Router->route('profile', function ($Request, $Response) {
         return $Response(body: 'Account Profile');
      }, GET);
      yield $Router->route('billing', function ($Request, $Response) {
         return $Response(body: 'Account Billing');
      }, GET);
      yield $Router->route('security', function ($Request, $Response) {
         return $Response(body: 'Account Security');
      }, GET);
   }, GET);

   // @ Middleware routes (routes with per-route middleware)
   $requestId = new RequestId;
   yield $Router->route('/protected/dashboard', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Dashboard');
   }, GET, middlewares: [$requestId]);
   yield $Router->route('/protected/settings', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Settings');
   }, GET, middlewares: [$requestId]);
   yield $Router->route('/protected/profile', function (Request $Request, Response $Response) {
      return $Response(body: 'Protected Profile');
   }, GET, middlewares: [$requestId]);

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
