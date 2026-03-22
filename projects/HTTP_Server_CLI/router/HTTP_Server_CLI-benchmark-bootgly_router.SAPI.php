<?php

namespace projects\HTTP_Server_CLI\router;


use Generator;

use Bootgly\WPI\Modules\HTTP\Server\Router;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Request;
use Bootgly\WPI\Nodes\HTTP_Server_CLI\Response;


return static function
(Request $Request, Response $Response, Router $Router): Generator
{
   // @ Static routes
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

   // @ Dynamic routes
   yield $Router->route('/user/:id', function (Request $Request, Response $Response) {
      return $Response(body: 'User: ' . $this->Params->id);
   }, GET);
   yield $Router->route('/post/:slug', function (Request $Request, Response $Response) {
      return $Response(body: 'Post: ' . $this->Params->slug);
   }, GET);
   yield $Router->route('/api/v1/:resource', function (Request $Request, Response $Response) {
      return $Response(body: 'API: ' . $this->Params->resource);
   }, GET);

   // @ Catch-all 404
   yield $Router->route('/*', function (Request $Request, Response $Response) {
      return $Response(code: 404, body: 'Not Found');
   });
};
