<a name="readme-top"></a>

<p align="center">
  <img src="https://github.com/bootgly/.github/raw/main/favicon-temp1-128.png" alt="bootgly-logo" width="120px" height="120px"/>
</p>
<h1 align="center">Bootgly</h1>
<p align="center">
  <i>Base PHP Framework for Multi Projects.</i>
</p>
<p align="center">
  <a href="https://packagist.org/packages/bootgly/bootgly">
    <img alt="Github Actions - Bootgly Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/bootgly.yml?label=Bootgly"/>
    <img alt="Github Actions - Docker Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/docker.yml?label=Docker"/>
    <img alt="Github Actions - PHPStan Workflow" src="https://img.shields.io/github/actions/workflow/status/bootgly/bootgly/phpstan.yml?label=PHPStan"/>
    <br>
    <img alt="Bootgly License" src="https://img.shields.io/github/license/bootgly/bootgly"/>
  </a>
</p>

> Bootgly is the first PHP framework to use the [I2P (Interface-to-Platform) architecture][I2P_ARQUITECTURE].

> [!NOTE]
> Bootgly will be completely refactored to use "Property Hooks" and v1.0 will be released only after PHP 8.4 is released.

> [!WARNING]
> 🚧 DO NOT USE IT IN PRODUCTION ENVIRONMENTS. 🚧
>
> Bootgly is in beta testing. A major version (1.0) is soon to release.
>
> [Documentation is under construction][PROJECT_DOCS].

## Table of Contents

- [🤔 About](#-about)
- [🟢 Boot Requirements](#-boot-requirements)
  - [🤝 Compatibility](#-compatibility)
  - [⚙️ Dependencies](#️-dependencies)
- [🌱 Community](#-community)
  - [💻 Contributing](#-contributing)
  - [🛂 Code of Conduct](#-code-of-conduct)
  - [🔗 Social Networks](#-social-networks)
  - [💖 Sponsorship](#-sponsorship)
- [🚀 Getting started](#-getting-started)
- [🖼 Highlights](#-highlights)
- [📃 License](#-license)
- [📑 Versioning System](#-versioning-system)

---

## 🤔 About

Bootgly is a base framework for developing APIs and Apps for both CLI (Console) 📟 and WPI (Web) 🌐.

> "Bootgly is focused on **efficiency** and follows a minimum dependency policy. Thanks to this approach, its **unique I2P architecture**, along with some uncommon code conventions and design patterns, allows Bootgly to offer **superior performance** while providing an **easy-to-understand Code APIs**."

### Bootgly CLI 📟

> Command Line Interface

- Interface: [CLI][CLI_INTERFACE]
- Platform: [Console][CONSOLE_PLATFORM] (TODO)

For the base CLI development, Bootgly already has the following UI Components:
[Alert][CLI_TERMINAL_ALERT], [Fieldset][CLI_TERMINAL_FIELDSET], [Header][CLI_HEADER], [Menu][CLI_TERMINAL_MENU], [Progress][CLI_TERMINAL_PROGRESS], [Table][CLI_TERMINAL_TABLE].

### Bootgly WPI 🌐

> Web Programming Interface 

- Interface: [WPI][WPI_INTERFACE]
- Platform: [Web][WEB_PLATFORM] (IN DEVELOPMENT)

For the base Web development, Bootgly has a [HTTP Server CLI][WEB_HTTP_SERVER_CLI], a [TCP Client CLI][WEB_TCP_CLIENT_INTERFACE] and a [TCP Server CLI][WEB_TCP_SERVER_INTERFACE].

More news may come until the release of v1.0. Stay tuned.

---

<div align="right">

[![][BACK_TO_TOP]](#readme-top)

</div>

## 🟢 Boot Requirements

### 🤝 Compatibility

Operation System |
--- |
✅ Linux (Debian based) |
❌ Windows |
❔ Unix |

--

✅ = Compatible

❌ = Incompatible

❔ = Untested

Above is the native compatibility, of course it is possible to run on Windows and Unix using Docker containers.

### ⚙️ Dependencies

- PHP 8.2+ ⚠️
- Opcache + JIT enabled (+50% performance) 👍

#### \- Bootgly CLI 📟

- `php-cli` ⚠️
- `php-mbstring` ⚠️
- `php-readline` ⚠️

#### \- Bootgly WPI 🌐

- `rewrite` module enabled ⚠️

--

⚠️ = Required

👍 = Recommended

---

<div align="right">

[![][BACK_TO_TOP]](#readme-top)

</div>

## 🌱 Community

Join us and help the community.

**Love Bootgly? Give [our repo][GITHUB_REPOSITORY] a star ⭐!**

### 💻 Contributing

Wait for the "contributing guidelines" to start your contribution.

#### 🛂 Code of Conduct

Help us keep Bootgly open and inclusive. Please read and follow our [Code of Conduct][CODE_OF_CONDUCT].

### 🔗 Social networks

- Bootgly on **LinkedIn**: [[Company Page][LINKEDIN]]
- Bootgly on **Telegram**: [[Telegram Group][TELEGRAM]]
- Bootgly on **Reddit**: [[Reddit Community][REDDIT]]
- Bootgly on **Discord**: [[Discord Channel][DISCORD]]

### 💖 Sponsorship

A lot of time and energy is devoted to Bootgly projects. To accelerate your growth, if you like this project or depend on it for your stack to work, consider [sponsoring it][GITHUB_SPONSOR].

Your sponsorship will keep this project always **up to date** with **new features** and **improvements** / **bug fixes**.

---

<div align="right">

[![][BACK_TO_TOP]](#readme-top)

</div>

## 🚀 Getting started

### 📟 Bootgly CLI

<details>
   <summary><kbd>Run Bootgly CLI demo</kbd></summary><br>

   1) See the examples in `projects/Bootgly/CLI/examples/`;
   2) Check the file `projects/Bootgly/CLI.php`;
   3) Run the Bootgly CLI demo in terminal:

   ```bash
   php bootgly demo
   ```
</details>

<details>
   <summary><kbd>Setup Bootgly CLI globally</kbd></summary><br>

   1) Run the Bootgly CLI setup command in terminal (with sudo):

   ```bash
   sudo php bootgly setup
   ```
</details>

<details>
   <summary><kbd>Perform Bootgly tests</kbd></summary><br>

   1) Check the bootstrap tests file `tests/@.php`;
   2) Run the Bootgly CLI test command in terminal:

   ```bash
   bootgly test
   ```
</details>

### 🌐 Bootgly WPI

<details>
   <summary><kbd>Running a HTTP Server</kbd></summary>

   ##### **Option 1: Non-CLI SAPI (Apache, LiteSpeed, Nginx, etc)**

   1) Enable support to `rewrite`;
   2) Configure the WPI boot file in `projects/Bootgly/WPI.boot.php` file;
   3) Run the Non-CLI HTTP Server pointing to `index.php`.

   ##### **Option 2: CLI SAPI**

   Directly in Linux OS *(max performance)*:

   1) Configure the Bootgly HTTP Server script in `scripts/http-server-cli` file;
   2) Configure the HTTP Server API in `projects/Bootgly/WPI/HTTP_Server_CLI-1.SAPI.php` file;
   3) Run the Bootgly HTTP Server CLI in the terminal:

   ```bash
   bootgly serve
   ```
   or
   ```bash
   php scripts/http-server-cli
   ```

   --

   or using Docker:

   1) Pull the image:

   ```bash
   docker pull bootgly/http-server-cli
   ```

   2) Run the container in interactive mode and in the host network for max performance:

   ```bash
   docker run -it --network host bootgly/http-server-cli
   ```
</details>

<b>[Routing HTTP Requests on the Server-side][ROUTING]</b>

---

<div align="right">

[![][BACK_TO_TOP]](#readme-top)

</div>

## 🖼 Highlights

### \- Bootgly CLI 📟

| ![](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-CLI.png "Bootgly CLI - initial output") |
|:--:| 
| *Bootgly CLI - initial output* |
---
| ![](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-CLI-Terminal-components-Progress.png "Render 7x faster than Laravel / Symfony") |
|:--:| 
| *Progress component (with Bar) - [Render ≈7x faster than Laravel / Symfony][BENCHMARK_1]* |

### \- Bootgly WPI 🌐

| ![](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Server-CLI-HTTP-Benchmark-Ryzen-9-3900X-WSL2.png "Bootgly HTTP Server CLI (wrk benchmark) - +7% faster than Workerman in the Plain Text test") |
|:--:| 
| *Bootgly HTTP Server CLI (wrk benchmark) - +7% faster than [Workerman](https://www.techempower.com/benchmarks/#section=data-r21&test=plaintext&l=zik073-6bj) in the [Plain Text test](https://github.com/TechEmpower/FrameworkBenchmarks/wiki/Project-Information-Framework-Tests-Overview#plaintext)* |
---
| ![](https://github.com/bootgly/.github/raw/main/screenshots/bootgly-php-framework/Bootgly-WPI-Nodes-HTTP-Server-CLI.png "Bootgly HTTP Server CLI - started in Monitor mode") |
|:--:| 
| *HTTP Server CLI - started in `monitor` mode*


More **Screenshots**, videos and details can be found in the home page of [Bootgly Docs][PROJECT_DOCS].

---

<div align="right">

[![][BACK_TO_TOP]](#readme-top)

</div>

## 📃 License

The Bootgly is open-sourced software licensed under the [MIT license][MIT_LICENSE].

---

## 📑 Versioning System

Bootgly uses [Semantic Versioning 2.0][SEMANTIC_VERSIONING].


<!-- Links -->
[I2P_ARQUITECTURE]: https://docs.bootgly.com/manual/Bootgly/basic/architecture/overview
[ROUTING]: https://docs.bootgly.com/manual/WPI/HTTP/HTTP_Server_Router/overview

[CLI_INTERFACE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/
[CLI_TERMINAL_COMPONENTS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components

[CLI_TERMINAL_ALERT]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Alert
[CLI_TERMINAL_FIELDSET]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Fieldset
[CLI_TERMINAL_MENU]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Menu
[CLI_TERMINAL_PROGRESS]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Progress
[CLI_TERMINAL_TABLE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/Terminal/components/Table
[CLI_HEADER]: https://github.com/bootgly/bootgly/tree/main/Bootgly/CLI/components/Header.php
[CONSOLE_PLATFORM]: https://github.com/bootgly/bootgly-console

[WPI_INTERFACE]: https://github.com/bootgly/bootgly/tree/main/Bootgly/WPI/
[HTTP_SERVER_ROUTER_CLASS]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Modules/HTTP/Server/Router.php
[WEB_TCP_CLIENT_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/TCP_Client_CLI.php
[WEB_TCP_SERVER_INTERFACE]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Interfaces/TCP_Server_CLI.php
[WEB_HTTP_SERVER_CLI]: https://github.com/bootgly/bootgly/blob/main/Bootgly/WPI/Nodes/HTTP_Server_CLI.php
[WEB_PLATFORM]: https://github.com/bootgly/bootgly-web


[BENCHMARK_1]: https://github.com/bootgly/bootgly_benchmarks/tree/main/Progress_Bar

[PROJECT_DOCS]: https://docs.bootgly.com/
[GITHUB_REPOSITORY]: https://github.com/bootgly/bootgly/
[GITHUB_SPONSOR]: https://github.com/sponsors/bootgly/

[TELEGRAM]: https://t.me/bootgly/
[REDDIT]: https://www.reddit.com/r/bootgly/
[DISCORD]: https://discord.com/invite/SKRHsYmtyJ/
[LINKEDIN]: https://www.linkedin.com/company/bootgly/


[CODE_OF_CONDUCT]: https://github.com/bootgly/bootgly/blob/main/.github/CODE_OF_CONDUCT.md
[SEMANTIC_VERSIONING]: https://semver.org/


[MIT_LICENSE]: https://opensource.org/license/mit/

[BACK_TO_TOP]: https://img.shields.io/badge/-BACK_TO_TOP-151515?style=flat-square
