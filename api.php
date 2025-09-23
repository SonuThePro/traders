<?php
/**
 * Enhanced API for M. Pimpale Traders E-commerce System
 * 
 * Features:
 * - Rate limiting
 * - Input validation and sanitization
 * - Comprehensive error handling
 * - Security headers
 * - Request logging
 * - CORS support
 */

// Security and initialization
define('PIMPALE_SYSTEM', true);
session_start();

// Load configuration and database
require_once 'config.php';
require_once 'database.php';

// Security headers
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// CORS headers (adjust origins for production)
$allowedOrigins = ['http://localhost', 'https://yourdomain.com'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if (in_array($origin, $allowedOrigins) || Config::DEBUG_MODE) {
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Max-Age: 86400');
}

// Handle preflight OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Initialize rate limiting
RateLimiter::init();

// Main API handler
class ApiHandler {
    private $db;
    private $requestStart;
    private $clientIp;
    
    public function __construct() {
        $this->requestStart = microtime(true);
        $this->clientIp = $this->getClientIp();
        
        try {
            $this->db = new Database();
        } catch (Exception $e) {
            $this->sendError(500, 'Database connection failed', $e->getMessage());
        }
    }
    
    public function handleRequest() {
        try {
            // Check rate limiting
            if (!RateLimiter::isAllowed($this->clientIp)) {
                $this->sendError(429, 'Rate limit exceeded', 'Too many requests from your IP');
            }
            
            // Get and validate endpoint
            $method = $_SERVER['REQUEST_METHOD'];
            $endpoint = $this->getEndpoint();
            $routeKey = $method . ' ' . $endpoint;
            
            // Log request
            $this->logRequest($method, $endpoint);
            
            // Route the request
            switch($routeKey) {
                // Public endpoints
                case 'GET products':
                    $this->getProducts();
                    break;
                    
                case 'GET product':
                    $this->getProduct();
                    break;
                    
                case 'POST order':
                    $this->createOrder();
                    break;
                    
                case 'GET status':
                    $this->getSystemStatus();
                    break;
                
                // Admin endpoints
                case 'GET admin/products':
                    $this->requireAuth();
                    $this->getAdminProducts();
                    break;
                    
                case 'POST admin/product':
                    $this->requireAuth();
                    $this->createProduct();
                    break;
                    
                case 'PUT admin/product':
                    $this->requireAuth();
                    $this->updateProduct();
                    break;
                    
                case 'DELETE admin/product':
                    $this->requireAuth();
                    $this->deleteProduct();
                    break;
                    
                case 'GET admin/orders':
                    $this->requireAuth();
                    $this->getOrders();
                    break;
                    
                case 'GET admin/analytics':
                    $this->requireAuth();
                    $this->getAnalytics();
                    break;
                    
                case 'GET admin/status':
                    $this->requireAuth();
                    $this->getAdminStatus();
                    break;
                    
                default:
                    $this->sendError(404, 'Endpoint not found', $this->getAvailableEndpoints());
            }
            
        } catch (InvalidArgumentException $e) {
            $this->sendError(400, 'Invalid input', $e->getMessage());
        } catch (Exception $e) {
            $this->logError($e->getMessage());
            $this->sendError(500, 'Internal server error', Config::DEBUG_MODE ? $e->getMessage() : 'Please try again later');
        }
    }
    
    // Public endpoint handlers
    
    private function getProducts() {
        $limit = $this->getIntParam('limit', Config::MAX_PRODUCTS_PER_PAGE);
        $offset = $this->getIntParam('offset', 0);
        
        $products = $this->db->getProducts(false, $limit, $offset);
        
        $this->sendSuccess([
            'products' => $products,
            'count' => count($products),
            'limit' => $limit,
            'offset' => $offset
        ]);
    }
    
    private function getProduct() {
        $id = $this->getRequiredParam('id');
        
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException('Invalid product ID');
        }
        
        $product = $this->db->getProduct($id);
        
        if (!$product) {
            $this->sendError(404, 'Product not found');
        }
        
        $this->sendSuccess(['product' => $product]);
    }
    
    private function createOrder() {
        $input = $this->getJsonInput();
        
        if (!isset($input['cart']) || !is_array($input['cart']) || empty($input['cart'])) {
            throw new InvalidArgumentException('Invalid or empty cart');
        }
        
        // Validate cart items
        foreach ($input['cart'] as $item) {
            if (!isset($item['id'], $item['qty'], $item['price']) ||
                !is_numeric($item['qty']) || !is_numeric($item['price']) ||
                $item['qty'] <= 0 || $item['price'] < 0) {
                throw new InvalidArgumentException('Invalid cart item format');
            }
        }
        
        $phone = $this->sanitizePhone($input['phone'] ?? null);
        $notes = $this->sanitizeString($input['notes'] ?? null);
        
        $orderId = $this->db->logOrder($input['cart'], $phone, $notes);
        
        if ($orderId) {
            $this->sendSuccess([
                'message' => 'Order logged successfully',
                'order_id' => $orderId
            ]);
        } else {
            $this->sendError(500, 'Failed to create order');
        }
    }
    
    private function getSystemStatus() {
        $status = $this->db->checkTables();
        $status['api_version'] = '1.0';
        $status['server_time'] = date('c');
        
        // Don't expose sensitive info in public status
        if (isset($status['error'])) {
            unset($status['details']);
        }
        
        $this->sendSuccess($status);
    }
    
    // Admin endpoint handlers
    
    private function getAdminProducts() {
        $includeInactive = $this->getBoolParam('include_inactive', false);
        $limit = $this->getIntParam('limit', Config::MAX_PRODUCTS_PER_PAGE);
        $offset = $this->getIntParam('offset', 0);
        
        $products = $this->db->getProducts($includeInactive, $limit, $offset);
        
        $this->sendSuccess([
            'products' => $products,
            'count' => count($products),
            'include_inactive' => $includeInactive
        ]);
    }
    
    private function createProduct() {
        $input = $this->getJsonInput();
        
        // Validate required fields
        if (empty($input['name']) || empty($input['price'])) {
            throw new InvalidArgumentException('Name and price are required');
        }
        
        $productId = $this->db->addProduct($input);
        
        if ($productId) {
            $product = $this->db->getProduct($productId);
            $this->sendSuccess([
                'message' => 'Product created successfully',
                'product' => $product
            ]);
        } else {
            $this->sendError(500, 'Failed to create product');
        }
    }
    
    private function updateProduct() {
        $id = $this->getRequiredParam('id');
        $input = $this->getJsonInput();
        
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException('Invalid product ID');
        }
        
        $success = $this->db->updateProduct($id, $input);
        
        if ($success) {
            $product = $this->db->getProduct($id);
            $this->sendSuccess([
                'message' => 'Product updated successfully',
                'product' => $product
            ]);
        } else {
            $this->sendError(404, 'Product not found or no changes made');
        }
    }
    
    private function deleteProduct() {
        $id = $this->getRequiredParam('id');
        
        if (!is_numeric($id) || $id <= 0) {
            throw new InvalidArgumentException('Invalid product ID');
        }
        
        $success = $this->db->deleteProduct($id);
        
        if ($success) {
            $this->sendSuccess(['message' => 'Product deleted successfully']);
        } else {
            $this->sendError(404, 'Product not found');
        }
    }
    
    private function getOrders() {
        $limit = $this->getIntParam('limit', 10);
        $offset = $this->getIntParam('offset', 0);
        
        $orders = $this->db->getRecentOrders($limit, $offset);
        
        $this->sendSuccess([
            'orders' => $orders,
            'count' => count($orders)
        ]);
    }
    
    private function getAnalytics() {
        $days = $this->getIntParam('days', 30);
        $detailed = $this->getBoolParam('detailed', false);
        
        $analytics = $this->db->getAnalytics($days, $detailed);
        
        $this->sendSuccess($analytics);
    }
    
    private function getAdminStatus() {
        $status = $this->db->checkTables();
        $status['config_errors'] = Config::validate();
        $status['rate_limits'] = RateLimiter::getStats();
        $status['memory_usage'] = memory_get_usage(true);
        $status['execution_time'] = microtime(true) - $this->requestStart;
        
        $this->sendSuccess($status);
    }
    
    // Helper methods
    
    private function getEndpoint() {
        $endpoint = $_GET['endpoint'] ?? '';
        
        // Sanitize endpoint
        $endpoint = preg_replace('/[^a-zA-Z0-9\/\-_]/', '', $endpoint);
        
        return trim($endpoint, '/');
    }
    
    private function getJsonInput() {
        $input = file_get_contents('php://input');
        
        if (empty($input)) {
            throw new InvalidArgumentException('Empty request body');
        }
        
        $data = json_decode($input, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('Invalid JSON: ' . json_last_error_msg());
        }
        
        return $data;
    }
    
    private function getRequiredParam($name) {
        $value = $_GET[$name] ?? $_POST[$name] ?? null;
        
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Required parameter missing: {$name}");
        }
        
        return $this->sanitizeString($value);
    }
    
    private function getIntParam($name, $default = 0) {
        $value = $_GET[$name] ?? $default;
        return max(0, (int)$value);
    }
    
    private function getBoolParam($name, $default = false) {
        $value = $_GET[$name] ?? $default;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
    
    private function sanitizeString($input) {
        if ($input === null) return null;
        return trim(strip_tags((string)$input));
    }
    
    private function sanitizePhone($phone) {
        if (empty($phone)) return null;
        
        // Remove non-numeric characters except + and spaces
        $clean = preg_replace('/[^+\d\s]/', '', $phone);
        
        // Basic validation
        if (strlen(preg_replace('/[^0-9]/', '', $clean)) < 10) {
            throw new InvalidArgumentException('Invalid phone number format');
        }
        
        return $clean;
    }
    
    private function requireAuth() {
        if (!$this->checkAuth()) {
            $this->sendError(401, 'Authentication required', 'Please provide valid credentials');
        }
    }
    
    private function checkAuth() {
        $headers = getallheaders();
        $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        
        if (strpos($auth, 'Basic ') !== 0) {
            return false;
        }
        
        $credentials = base64_decode(substr($auth, 6));
        
        if (strpos($credentials, ':') === false) {
            return false;
        }
        
        list($user, $pass) = explode(':', $credentials, 2);
        
        // Constant-time comparison to prevent timing attacks
        return hash_equals(Config::ADMIN_USER, $user) && hash_equals(Config::ADMIN_PASS, $pass);
    }
    
    private function getClientIp() {
        $headers = ['HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ips = explode(',', $_SERVER[$header]);
                return trim($ips[0]);
            }
        }
        
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
    
    private function sendSuccess($data = null, $code = 200) {
        http_response_code($code);
        
        $response = [
            'success' => true,
            'timestamp' => date('c'),
            'execution_time' => microtime(true) - $this->requestStart
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    private function sendError($code, $message, $details = null) {
        http_response_code($code);
        
        $response = [
            'success' => false,
            'error' => $message,
            'code' => $code,
            'timestamp' => date('c')
        ];
        
        if ($details !== null && Config::DEBUG_MODE) {
            $response['details'] = $details;
        }
        
        echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }
    
    private function getAvailableEndpoints() {
        return [
            'Public endpoints:' => [
                'GET ?endpoint=products - Get all products',
                'GET ?endpoint=product&id=X - Get single product', 
                'POST ?endpoint=order - Create order',
                'GET ?endpoint=status - System status'
            ],
            'Admin endpoints (require Basic Auth):' => [
                'GET ?endpoint=admin/products - Get all products',
                'POST ?endpoint=admin/product - Create product',
                'PUT ?endpoint=admin/product&id=X - Update product',
                'DELETE ?endpoint=admin/product&id=X - Delete product',
                'GET ?endpoint=admin/orders - Get recent orders',
                'GET ?endpoint=admin/analytics - Get analytics',
                'GET ?endpoint=admin/status - Admin status'
            ]
        ];
    }
    
    private function logRequest($method, $endpoint) {
        if (Config::LOG_ERRORS) {
            $logData = [
                'timestamp' => date('c'),
                'ip' => $this->clientIp,
                'method' => $method,
                'endpoint' => $endpoint,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
            ];
            
            error_log("API Request: " . json_encode($logData));
        }
    }
    
    private function logError($message) {
        if (Config::LOG_ERRORS) {
            error_log("[" . date('c') . "] API Error: " . $message);
        }
    }
}

// Simple rate limiter class
class RateLimiter {
    private static $requests = [];
    
    public static function init() {
        // Clean old entries every request (simple approach)
        self::cleanup();
    }
    
    public static function isAllowed($ip) {
        $now = time();
        $hourAgo = $now - 3600;
        
        // Count requests from this IP in the last hour
        $count = 0;
        foreach (self::$requests as $request) {
            if ($request['ip'] === $ip && $request['time'] > $hourAgo) {
                $count++;
            }
        }
        
        if ($count >= Config::RATE_LIMIT_REQUESTS) {
            return false;
        }
        
        // Log this request
        self::$requests[] = ['ip' => $ip, 'time' => $now];
        
        return true;
    }
    
    public static function getStats() {
        $now = time();
        $hourAgo = $now - 3600;
        
        $recent = array_filter(self::$requests, function($req) use ($hourAgo) {
            return $req['time'] > $hourAgo;
        });
        
        return [
            'total_requests_last_hour' => count($recent),
            'unique_ips' => count(array_unique(array_column($recent, 'ip')))
        ];
    }
    
    private static function cleanup() {
        $hourAgo = time() - 3600;
        self::$requests = array_filter(self::$requests, function($req) use ($hourAgo) {
            return $req['time'] > $hourAgo;
        });
    }
}

// Initialize and run the API
try {
    $api = new ApiHandler();
    $api->handleRequest();
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'System error',
        'details' => Config::DEBUG_MODE ? $e->getMessage() : 'Please contact support'
    ]);
}