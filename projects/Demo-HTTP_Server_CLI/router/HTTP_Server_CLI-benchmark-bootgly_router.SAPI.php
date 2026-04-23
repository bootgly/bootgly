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
   // @ Extra static routes (11..100)
   yield $Router->route('/static/11', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 11');
   }, GET);
   yield $Router->route('/static/12', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 12');
   }, GET);
   yield $Router->route('/static/13', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 13');
   }, GET);
   yield $Router->route('/static/14', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 14');
   }, GET);
   yield $Router->route('/static/15', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 15');
   }, GET);
   yield $Router->route('/static/16', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 16');
   }, GET);
   yield $Router->route('/static/17', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 17');
   }, GET);
   yield $Router->route('/static/18', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 18');
   }, GET);
   yield $Router->route('/static/19', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 19');
   }, GET);
   yield $Router->route('/static/20', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 20');
   }, GET);
   yield $Router->route('/static/21', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 21');
   }, GET);
   yield $Router->route('/static/22', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 22');
   }, GET);
   yield $Router->route('/static/23', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 23');
   }, GET);
   yield $Router->route('/static/24', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 24');
   }, GET);
   yield $Router->route('/static/25', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 25');
   }, GET);
   yield $Router->route('/static/26', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 26');
   }, GET);
   yield $Router->route('/static/27', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 27');
   }, GET);
   yield $Router->route('/static/28', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 28');
   }, GET);
   yield $Router->route('/static/29', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 29');
   }, GET);
   yield $Router->route('/static/30', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 30');
   }, GET);
   yield $Router->route('/static/31', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 31');
   }, GET);
   yield $Router->route('/static/32', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 32');
   }, GET);
   yield $Router->route('/static/33', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 33');
   }, GET);
   yield $Router->route('/static/34', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 34');
   }, GET);
   yield $Router->route('/static/35', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 35');
   }, GET);
   yield $Router->route('/static/36', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 36');
   }, GET);
   yield $Router->route('/static/37', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 37');
   }, GET);
   yield $Router->route('/static/38', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 38');
   }, GET);
   yield $Router->route('/static/39', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 39');
   }, GET);
   yield $Router->route('/static/40', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 40');
   }, GET);
   yield $Router->route('/static/41', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 41');
   }, GET);
   yield $Router->route('/static/42', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 42');
   }, GET);
   yield $Router->route('/static/43', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 43');
   }, GET);
   yield $Router->route('/static/44', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 44');
   }, GET);
   yield $Router->route('/static/45', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 45');
   }, GET);
   yield $Router->route('/static/46', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 46');
   }, GET);
   yield $Router->route('/static/47', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 47');
   }, GET);
   yield $Router->route('/static/48', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 48');
   }, GET);
   yield $Router->route('/static/49', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 49');
   }, GET);
   yield $Router->route('/static/50', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 50');
   }, GET);
   yield $Router->route('/static/51', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 51');
   }, GET);
   yield $Router->route('/static/52', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 52');
   }, GET);
   yield $Router->route('/static/53', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 53');
   }, GET);
   yield $Router->route('/static/54', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 54');
   }, GET);
   yield $Router->route('/static/55', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 55');
   }, GET);
   yield $Router->route('/static/56', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 56');
   }, GET);
   yield $Router->route('/static/57', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 57');
   }, GET);
   yield $Router->route('/static/58', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 58');
   }, GET);
   yield $Router->route('/static/59', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 59');
   }, GET);
   yield $Router->route('/static/60', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 60');
   }, GET);
   yield $Router->route('/static/61', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 61');
   }, GET);
   yield $Router->route('/static/62', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 62');
   }, GET);
   yield $Router->route('/static/63', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 63');
   }, GET);
   yield $Router->route('/static/64', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 64');
   }, GET);
   yield $Router->route('/static/65', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 65');
   }, GET);
   yield $Router->route('/static/66', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 66');
   }, GET);
   yield $Router->route('/static/67', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 67');
   }, GET);
   yield $Router->route('/static/68', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 68');
   }, GET);
   yield $Router->route('/static/69', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 69');
   }, GET);
   yield $Router->route('/static/70', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 70');
   }, GET);
   yield $Router->route('/static/71', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 71');
   }, GET);
   yield $Router->route('/static/72', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 72');
   }, GET);
   yield $Router->route('/static/73', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 73');
   }, GET);
   yield $Router->route('/static/74', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 74');
   }, GET);
   yield $Router->route('/static/75', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 75');
   }, GET);
   yield $Router->route('/static/76', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 76');
   }, GET);
   yield $Router->route('/static/77', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 77');
   }, GET);
   yield $Router->route('/static/78', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 78');
   }, GET);
   yield $Router->route('/static/79', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 79');
   }, GET);
   yield $Router->route('/static/80', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 80');
   }, GET);
   yield $Router->route('/static/81', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 81');
   }, GET);
   yield $Router->route('/static/82', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 82');
   }, GET);
   yield $Router->route('/static/83', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 83');
   }, GET);
   yield $Router->route('/static/84', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 84');
   }, GET);
   yield $Router->route('/static/85', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 85');
   }, GET);
   yield $Router->route('/static/86', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 86');
   }, GET);
   yield $Router->route('/static/87', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 87');
   }, GET);
   yield $Router->route('/static/88', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 88');
   }, GET);
   yield $Router->route('/static/89', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 89');
   }, GET);
   yield $Router->route('/static/90', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 90');
   }, GET);
   yield $Router->route('/static/91', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 91');
   }, GET);
   yield $Router->route('/static/92', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 92');
   }, GET);
   yield $Router->route('/static/93', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 93');
   }, GET);
   yield $Router->route('/static/94', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 94');
   }, GET);
   yield $Router->route('/static/95', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 95');
   }, GET);
   yield $Router->route('/static/96', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 96');
   }, GET);
   yield $Router->route('/static/97', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 97');
   }, GET);
   yield $Router->route('/static/98', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 98');
   }, GET);
   yield $Router->route('/static/99', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 99');
   }, GET);
   yield $Router->route('/static/100', function (Request $Request, Response $Response) {
      return $Response(body: 'Static 100');
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
   // @ Extra dynamic routes (11..100)
   yield $Router->route('/d11/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d12/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d13/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d14/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d15/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d16/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d17/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d18/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d19/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d20/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d21/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d22/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d23/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d24/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d25/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d26/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d27/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d28/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d29/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d30/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d31/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d32/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d33/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d34/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d35/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d36/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d37/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d38/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d39/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d40/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d41/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d42/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d43/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d44/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d45/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d46/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d47/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d48/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d49/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d50/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d51/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d52/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d53/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d54/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d55/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d56/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d57/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d58/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d59/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d60/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d61/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d62/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d63/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d64/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d65/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d66/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d67/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d68/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d69/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d70/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d71/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d72/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d73/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d74/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d75/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d76/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d77/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d78/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d79/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d80/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d81/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d82/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d83/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d84/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d85/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d86/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d87/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d88/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d89/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d90/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d91/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d92/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d93/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d94/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d95/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d96/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d97/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d98/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d99/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
   }, GET);
   yield $Router->route('/d100/:param', function (Request $Request, Response $Response) {
      return $Response(body: 'Dynamic ' . $this->Params->param);
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
