# Bootgly PHP Framework (v0.0.1-pre-alpha)

**Full Stack PHP Framework for Multi Projects (WIP)**

🚧 Do not use it in production environments. The alpha version hasn't even been released yet. 🚧

*Temporary repository created for Github Accelerator.*

First beta release is planned for mid-year 2023.

Documentation and Website will be released alongside the beta.

Servers | Database | Back-end | Front-end
--- | --- | --- | ---
[TCP Server (WIP)](/interfaces/Web/TCP/Server.php) | ORM (TODO) | [Router (WIP)](/nodes/Web/HTTP/Server/Router.php) | [Templating Engine (WIP)](/core/Template.php)
[HTTP Server (WIP)](/nodes/Web/HTTP/Server.php) | _ | [Router/Route (WIP)](/nodes/Web/HTTP/Server/Router/Route.php) | _
_ | _ | [Request (WIP)](/nodes/Web/HTTP/Server/Request.php) | _
_ | _ | [Response (WIP)](/nodes/Web/HTTP/Server/Response.php) | _

## ❗️ Dependencies

- PHP 8.2+ *[Required]*
- Apache Rewrite enabled *[Required for Non-CLI Web Servers only]*
- Opcache with JIT enabled (+50% performance) *[Optional]*
- Debian based OS (Debian, Ubuntu...) *[Required]*

## Usage

### Non-CLI SAPI (Apache, LiteSpeed, Nginx, etc)

1) Clone this repository
2) Rename `projects/web.constructor.php.example` to `projects/web.constructor.php`
3) Enable Rewrite

### CLI SAPI

1) Check `server.http.php` file
2) php -a > opcache_reset() > quit;
3) Input in terminal:

`php server.http.php`

or with Docker (-10% performance):

1) Input in terminal:

`docker run -it --network host bootglyphpframework`

## 📃 License

The Bootgly PHP Framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
