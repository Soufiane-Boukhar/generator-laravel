# Soufiane Generator Laravel

`SoufianeGenerator` is a custom Laravel Artisan command designed to simplify and automate the process of generating repositories, controllers, models, migrations, views, routes, and layouts. It supports generating code with or without relationships and integrates seamlessly with the AdminLTE layout.

## Features

- **Repository Pattern Support**: Generate repositories for efficient data handling.
- **Controllers**: Automatically create controllers with the desired logic.
- **Models**: Create models with or without relationships.
- **Migrations**: Generate migration files, including support for relationships between tables.
- **Views**: Scaffold views for CRUD operations.
- **Routes**: Register routes automatically for the generated resources.
- **AdminLTE Layout Integration**: Generate views that use the AdminLTE layout.
- **Customizable**: Tailor the generated files to fit your project's requirements.

## Installation

1. Place the `SoufianeGenerator` command in the `App\Console\Commands` directory of your Laravel application.

## Usage

```bash

php artisan generate:full-code ModelName

```

