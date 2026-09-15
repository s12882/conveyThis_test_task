<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

## Laravel & PHP Code Style
- **Strict Typing:** Always declare `declare(strict_types=1);` at the top of every PHP file.
- **PHP Features:** Use PHP 8.3+ modern syntax.
- **Naming Conventions:**
    - Controllers, Models, Services: `PascalCase`
    - Routes, Database columns, Attributes: `snake_case`
    - Variables, Methods, Properties: `camelCase`
- **Controllers:** Keep controllers thin. Validate using `FormRequest` classes, delegate business logic to Actions/Services, and return typed responses. Prefer single-action `__invoke()` controllers for non-CRUD endpoints.
- **Type Hinting:** Explicitly define return types and argument types on all controller actions, models, and custom methods. Do not use generic docblocks (`/** @return void */`) unless necessary for IDEs.

## General 
- Ask before editing a file