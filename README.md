# Laravel Starter Template

Welcome to the **My Project** repository.

## 📋 Prerequisites

Ensure you have the following installed on your local machine before starting:

  * **PHP**: Version 8.4.1 or higher
  * **Composer**: Dependency Manager for PHP
  * **MySQL**: Database server
  * **Git**: Version control

-----

## 🚀 Installation & Setup

Follow these steps to set up the project locally.

### 1\. Clone the Repository

```bash
git clone <git_repository_url>
```

### 2\. Install Dependencies

Install PHP and Node dependencies:

```bash
composer install
```

### 3\. Environment Configuration

Create your environment file and generate the application key:

```bash
cp .env.example .env
php artisan key:generate
```

### 4\. Database Migration & Seeding

```bash
php artisan migrate:fresh --seed
```

### 5\. Storage Link

```bash
php artisan storage:link
```

-----

## 🏃‍♂️ Running the Application

Start the local development server:

```bash
php artisan serve
```

The API will be available at: `http://127.0.0.1:8000`

-----

## 📚 API Documentation

This project uses **Scramble** for automatic API documentation.

  * **View Docs:** Visit `http://127.0.0.1:8000/docs/api`
  * **Authentication:**
    1.  Login via the `POST /api/login` endpoint using the credentials above.
    2.  Copy the `token` from the response.
    3.  Navigate to other authenticated endpoints and click the "Token" field in the docs and paste the token.

-----