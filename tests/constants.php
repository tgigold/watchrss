<?php
/**
 * Konstanten aus config.inc.php für Tests und statische Analyse.
 *
 * Tests laden nie die echte Konfiguration und sprechen nie eine echte
 * Datenbank an - diese Werte sind absichtlich ungültig.
 */

declare(strict_types=1);

defined('DB_HOST') || define('DB_HOST', '127.0.0.1');
defined('DB_PORT') || define('DB_PORT', '1');
defined('DB_NAME') || define('DB_NAME', 'watchrss_test');
defined('DB_USER') || define('DB_USER', 'watchrss_test');
defined('DB_PASS') || define('DB_PASS', 'not-a-real-password');
defined('DB_CHARSET') || define('DB_CHARSET', 'utf8mb4');
defined('APP_BASE_URL') || define('APP_BASE_URL', 'https://watchrss.test');
defined('CRON_TOKEN') || define('CRON_TOKEN', 'test-cron-token-0123456789');
defined('TRACKING_SALT') || define('TRACKING_SALT', 'test-salt');
defined('MATOMO_ENABLED') || define('MATOMO_ENABLED', false);
defined('TRUSTED_PROXIES') || define('TRUSTED_PROXIES', '10.0.0.0/8');
defined('ERROR_LOG_FILE') || define('ERROR_LOG_FILE', '');
defined('ADMIN_TOKEN') || define('ADMIN_TOKEN', 'test-admin-token-0123456789');
defined('BACKUP_DIR') || define('BACKUP_DIR', sys_get_temp_dir() . '/watchrss-test-backups');
defined('CACHE_DIR') || define('CACHE_DIR', sys_get_temp_dir() . '/watchrss-test-cache');

require_once dirname(__DIR__) . '/config.app.inc.php';
