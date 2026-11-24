<p align="center">
    <picture>
        <source media="(prefers-color-scheme: dark)" height="100" srcset="packages/Webkul/Admin/src/Resources/assets/images/dark-logo.svg">
        <source media="(prefers-color-scheme: light)" height="100" srcset="packages/Webkul/Admin/src/Resources/assets/images/logo.svg">
        <img alt="Volmaster Tech CRM" height="100" src="packages/Webkul/Admin/src/Resources/assets/images/logo.svg">
    </picture>
</p>

## Topics

1. [Introduction](#introduction)
2. [Requirements](#requirements)
3. [Installation & Configuration](#installation-and-configuration)
4. [License](#license)

### Introduction

Volmaster Tech CRM is a CRM built for heavy-truck repair shops, covering service orders, vehicles, parts/inventory, mechanics, work bays, and a dedicated **Remote Service** module for TeamViewer-based remote support.

Built on [PHP](https://secure.php.net/) and [Vue.js](https://vuejs.org).

### Requirements

-   **SERVER**: Apache 2 or NGINX.
-   **RAM**: 3 GB or higher.
-   **PHP**: 8.3 or higher
-   **Composer**: 2.5 or higher
-   **For MySQL users**: 8.0.32 or higher.

### Installation and Configuration

##### Execute these commands below, in order

```
composer create-project
```

-   Find **.env** file in root directory and change the **APP_URL** param to your **domain**.

-   Also, configure the **Mail** and **Database** parameters inside the **.env** file.

```
php artisan volmaster-tech:install
```

##### On server:

Warning: Before going into production mode we recommend you uninstall developer dependencies.
In order to do that, run the command below:

> composer install --no-dev

```
Open the specified entry point in your hosts file in your browser or make an entry in hosts file if not done.
```

##### On local:

```
php artisan route:clear
php artisan serve
```

**How to log in as admin:**

> _http(s)://example.com/admin/login_

```
email:admin@example.com
password:admin123
```

### License

Proprietary. All rights reserved.
