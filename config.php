<?php
/**
 * Configuration for M. Pimpale Traders E-commerce System
 * 
 * SECURITY NOTICE: Change all default values before deployment!
 * This file contains sensitive information - ensure it's not publicly accessible.
 */

// Prevent direct access to this file
if (!defined('PIMPALE_SYSTEM')) {
    die('Direct access not allowed');
}

class Config {
    // Database Configuration
    const DB_HOST = 'localhost';
    const DB_NAME = 'pimpale_traders';
    const DB_USER = 'your_username';           // CHANGE THIS!
    const DB_PASS = 'your_secure_password';    // CHANGE THIS!
    const DB_CHARSET = 'utf8mb4';
    
    // Admin Authentication - CHANGE THESE IMMEDIATELY!
    const ADMIN_USER = 'admin';
    const ADMIN_PASS = 'your_very_secure_password_2024!';  // Use strong password!
    
    // Business Information
    const BUSINESS_NAME = 'M. Pimpale Traders';
    const BUSINESS_ADDRESS = 'Your complete business address here';
    const BUSINESS_EMAIL = 'contact@pimpaletraders.com';
    const WHATSAPP_NUMBER = '919112295256';
    
    // System Settings
    const TIMEZONE = 'Asia/Kolkata';
    const MAX_PRODUCTS_PER_PAGE = 100;
    const ORDER_RETENTION_DAYS = 365;  // Keep orders for 1 year
    const CACHE_PRODUCTS_MINUTES = 5;
    
    // Security Settings
    const RATE_LIMIT_REQUESTS = 100;   // Max requests per hour per IP
    const SESSION_TIMEOUT = 3600;      // 1 hour for admin sessions
    const BCRYPT_ROUNDS = 12;          // Password hashing rounds
    
    // File Upload Settings
    const MAX_IMAGE_SIZE = 2097152;    // 2MB max image size
    const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/webp'];
    const UPLOAD_PATH = 'uploads/';
    
    // Email Settings (for future use)
    const SMTP_HOST = '';
    const SMTP_PORT = 587;
    const SMTP_USER = '';
    const SMTP_PASS = '';
    
    // Debug Settings - SET TO FALSE IN PRODUCTION!
    const DEBUG_MODE = true;           // CHANGE TO FALSE IN PRODUCTION!
    const LOG_ERRORS = true;
    const ERROR_LOG_FILE = 'logs/error.log';
    
    /**
     * Get database DSN string
     */
    public static function getDSN() {
        return sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            self::DB_HOST,
            self::DB_NAME,
            self::DB_CHARSET
        );
    }
    
    /**
     * Get PDO options array
     */
    public static function getPDOOptions() {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES " . self::DB_CHARSET
        ];
    }
    
    /**
     * Initialize system settings
     */
    public static function init() {
        // Set timezone
        date_default_timezone_set(self::TIMEZONE);
        
        // Set error reporting based on debug mode
        if (self::DEBUG_MODE) {
            error_reporting(E_ALL);
            ini_set('display_errors', 1);
        } else {
            error_reporting(0);
            ini_set('display_errors', 0);
        }
        
        // Set up error logging
        if (self::LOG_ERRORS && !empty(self::ERROR_LOG_FILE)) {
            ini_set('log_errors', 1);
            ini_set('error_log', self::ERROR_LOG_FILE);
        }
    }
    
    /**
     * Validate configuration
     */
    public static function validate() {
        $errors = [];
        
        // Check if default passwords are still being used
        if (self::ADMIN_PASS === 'pimpale123' || self::ADMIN_PASS === 'your_very_secure_password_2024!') {
            $errors[] = 'Default admin password detected - change immediately!';
        }
        
        if (self::DB_USER === 'your_username' || self::DB_PASS === 'your_secure_password') {
            $errors[] = 'Default database credentials detected - update config.php';
        }
        
        // Check password strength
        if (strlen(self::ADMIN_PASS) < 8) {
            $errors[] = 'Admin password too short - use at least 8 characters';
        }
        
        // Check required directories exist
        $dirs = ['uploads/', 'logs/'];
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                $errors[] = "Directory missing: {$dir} - please create it";
            }
        }
        
        return $errors;
    }
}

// Auto-initialize when config is loaded
Config::init();
?>