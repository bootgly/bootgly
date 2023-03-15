<h1 align="center">Bootgly PHP Framework</h1>

<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/favicon-temp1-128.png" alt="bootgly-logo" width="120px" height="120px"/>
  <br>
  <i>Full Stack PHP Framework for Multi Projects.</i>
  <br>
</p>

🚧 Do not use it in production environments. The alpha version hasn't even been released yet. 🚧

*Repository initially created for the Github Accelerator.*

First beta release is planned for mid-year 2023.

Documentation and Website will be released alongside the beta.

Clients | Servers | Database | Back-end | Front-end
--- | --- | --- | --- | ---
[TCP Client [Beta]](/interfaces/Web/TCP/Client.php) | [TCP Server [Beta]](/interfaces/Web/TCP/Server.php) | DBAL [TODO] | [Router [WIP]](/nodes/Web/HTTP/Server/Router.php) | [Templating Engine [WIP]](/core/Template.php)
HTTP Client (CLI) [TODO] | [HTTP Server (CLI) [WIP]](/nodes/CLI/HTTP/Server.php) | ORM [TODO] | [Router/Route [WIP]](/nodes/Web/HTTP/Server/Router/Route.php) | _
_ | WS Server [TODO] | _ | [Request [WIP]](/nodes/CLI/HTTP/Server/Request.php) | _
_ | _ | _ | [Response [WIP]](/nodes/CLI/HTTP/Server/Response.php) | _

---

## ⚙️ Dependencies

- PHP 8.2+ `[Required]`
- Apache Rewrite enabled `[Required for Non-CLI SAPI only]`
- Opcache with JIT enabled (+50% performance) `[Optional]`
- Linux OS (Debian based OS is recommended: Debian, Ubuntu...) `[Required]`

---

## 🔧 Usage

### Running a HTTP Server in Bootgly PHP Framework:

#### **Option 1: Non-CLI SAPI (Apache, LiteSpeed, Nginx, etc)**

1) Enable support to Rewrite;
2) Rename the file `projects/web.constructor.php.example` to `projects/web.constructor.php`;
3) Configure the Web constructor in `projects/web.constructor.php` file;
4) Run the Non-CLI HTTP Server pointing to `index.php`.

#### **Option 2: CLI SAPI**

Directly in Linux OS *(max performance)*:

1) Configure the Bootgly HTTP Server in `server.http.php` file;
2) Rename `projects/sapi.http.constructor.php.example` to `projects/sapi.http.constructor.php`;
3) Configure SAPI constructor in `projects/sapi.http.constructor.php` file;
4) Run the Bootgly HTTP Server in the terminal:

`php server.http.php`

--

or using Docker:

1) Pull the Bootgly image from Docker Hub:

`docker pull bootgly/bootgly-php-framework`

2) Run the Bootgly container in interactive mode:

`docker run -it --network host bootgly/bootgly-php-framework`

---

## 🖼 Screenshots

| ![HTTP Server CLI - My Benchmark results using Ryzen 9 3900X (24 CPUs) on WSL2 - Simple 'Hello World!'](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Server-CLI-HTTP-Benchmark-Ryzen-9-3900X-WSL2.png "HTTP Server CLI - My Benchmark results using Ryzen 9 3900X (24 CPUs) on WSL2 - Simple 'Hello World!'") |
|:--:| 
| *HTTP Server CLI - My Benchmark results using Ryzen 9 3900X (24 CPUs) on WSL2 - Simple 'Hello World!'* |

| ![HTTP Server CLI started - Initial output](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Server-CLI-HTTP-started.png "HTTP Server CLI started - Initial output") |
|:--:| 
| *HTTP Server CLI started - Initial output* |

| ![HTTP Server CLI - Test suites](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-HTTP-Server-Test-Suite5.png "HTTP Server CLI - Test suites") |
|:--:| 
| *HTTP Server CLI - Test suites* |

---

## 💖 Sponsorship

A lot of time and energy is devoted to this project. To accelerate your growth, if you like this project or depend on it for your stack to work, consider sponsoring it.

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

---

## 🌱 Community

Join the conversation and help the community.

- [Telegram][telegram]

**Love Bootgly? Give our repo a star ⭐!**

---

## 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][codeofconduct].

---

## 📑 Versioning

Bootgly PHP Framework will follow [Semantic Versioning 2.0][semver].

---

## 📃 License

The Bootgly PHP Framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).


[telegram]: https://t.me/bootgly
[codeofconduct]: CODE_OF_CONDUCT.md
[semver]: https://semver.org/