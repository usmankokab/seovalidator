# SEO Workbook Verifier - Setup Instructions

## Prerequisites
- PHP 8.1 or higher
- Composer (PHP dependency manager)
- Git
- Web server (Apache/Nginx) with mod_rewrite for production
- MySQL/PostgreSQL (optional, app uses file processing)

## Installation

### 1. Clone the Repository
```bash
git clone https://github.com/your-repo/seo-workbook-verifier.git
cd seo-workbook-verifier
```

### 2. Install PHP Dependencies
```bash
composer install
```

### 3. Environment Configuration
- Copy `.env.example` to `.env`
- Edit `.env`:
  - Set `APP_NAME=SEO Workbook Verifier`
  - Set `APP_URL=http://localhost:8000` (for local) or your domain
  - Configure database if needed (app primarily processes Excel files)

### 4. Generate Application Key
```bash
php artisan key:generate
```

### 5. Storage Permissions
Ensure `storage/` and `bootstrap/cache/` are writable:
```bash
chmod -R 755 storage/
chmod -R 755 bootstrap/cache/
```

## Running the Application

### Development (Local)
```bash
php artisan serve
```
Access at: http://localhost:8000

### Production (Shared Hosting)
1. Upload all files to `public_html/`
2. Set document root to `public_html/`
3. Ensure `.htaccess` is present for URL rewriting
4. Configure PHP limits in `.htaccess` if needed:
   ```
   php_value max_execution_time 300
   php_value memory_limit 256M
   ```
5. Access via your domain

## Usage
1. Upload Excel file with SEO workbook data
2. Select verification options
3. Process URLs (may take time for large files)
4. Download reports (Word, PDF, Excel)

## Troubleshooting
- **500 Error**: Check `.env` configuration and storage permissions
- **403 Forbidden**: Verify file permissions and document root
- **Timeout for large files**: Increase PHP limits or reduce concurrency in code
- **403 URLs showing as Broken**: Clear cache and re-run verification

## Support
For issues, check Laravel logs in `storage/logs/laravel.log`
