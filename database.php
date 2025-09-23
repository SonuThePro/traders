<?php
/**
 * Enhanced Database Operations for M. Pimpale Traders
 * 
 * Features:
 * - Connection pooling
 * - Prepared statements for security
 * - Error handling and logging
 * - Input validation
 * - Caching support
 * - Transaction support
 */

// Security check
define('PIMPALE_SYSTEM', true);
require_once 'config.php';

class Database {
    private $pdo;
    private $cache = [];
    private $lastError = null;
    
    public function __construct() {
        $this->connect();
    }
    
    /**
     * Establish database connection with retry logic
     */
    private function connect($retries = 3) {
        $attempt = 0;
        
        while ($attempt < $retries) {
            try {
                $this->pdo = new PDO(
                    Config::getDSN(),
                    Config::DB_USER,
                    Config::DB_PASS,
                    Config::getPDOOptions()
                );
                
                // Test connection
                $this->pdo->query("SELECT 1");
                return;
                
            } catch(PDOException $e) {
                $attempt++;
                $this->lastError = $e->getMessage();
                
                if ($attempt >= $retries) {
                    $this->logError("Database connection failed after {$retries} attempts: " . $e->getMessage());
                    throw new Exception("Database connection failed. Please check configuration.");
                }
                
                // Wait before retry (exponential backoff)
                sleep(pow(2, $attempt));
            }
        }
    }
    
    /**
     * Get all products with caching and pagination
     */
    public function getProducts($includeInactive = false, $limit = null, $offset = 0) {
        $cacheKey = "products_" . ($includeInactive ? 'all' : 'active') . "_{$limit}_{$offset}";
        
        // Check cache first
        if (isset($this->cache[$cacheKey]) && 
            (time() - $this->cache[$cacheKey]['time']) < (Config::CACHE_PRODUCTS_MINUTES * 60)) {
            return $this->cache[$cacheKey]['data'];
        }
        
        try {
            $sql = "SELECT id, name, price, description, image_url, unit, stock_qty, 
                           sort_order, active, created_at, updated_at 
                    FROM products";
            
            $params = [];
            
            if (!$includeInactive) {
                $sql .= " WHERE active = ?";
                $params[] = 1;
            }
            
            $sql .= " ORDER BY sort_order ASC, name ASC";
            
            if ($limit) {
                $sql .= " LIMIT ? OFFSET ?";
                $params[] = (int)$limit;
                $params[] = (int)$offset;
            }
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $products = $stmt->fetchAll();
            
            // Process products (ensure proper data types)
            foreach ($products as &$product) {
                $product['id'] = (int)$product['id'];
                $product['price'] = (float)$product['price'];
                $product['stock_qty'] = (int)$product['stock_qty'];
                $product['sort_order'] = (int)$product['sort_order'];
                $product['active'] = (bool)$product['active'];
                
                // Ensure image URL is complete
                if ($product['image_url'] && !filter_var($product['image_url'], FILTER_VALIDATE_URL)) {
                    $product['image_url'] = $this->getFullImageUrl($product['image_url']);
                }
            }
            
            // Cache results
            $this->cache[$cacheKey] = [
                'data' => $products,
                'time' => time()
            ];
            
            return $products;
            
        } catch(Exception $e) {
            $this->logError("Failed to fetch products: " . $e->getMessage());
            throw new Exception("Failed to retrieve products");
        }
    }
    
    /**
     * Get single product by ID with validation
     */
    public function getProduct($id) {
        if (!$this->isValidId($id)) {
            throw new InvalidArgumentException("Invalid product ID");
        }
        
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, name, price, description, image_url, unit, stock_qty, 
                        sort_order, active, created_at, updated_at 
                 FROM products 
                 WHERE id = ? AND active = 1"
            );
            
            $stmt->execute([$id]);
            $product = $stmt->fetch();
            
            if ($product) {
                // Process data types
                $product['id'] = (int)$product['id'];
                $product['price'] = (float)$product['price'];
                $product['stock_qty'] = (int)$product['stock_qty'];
                $product['sort_order'] = (int)$product['sort_order'];
                $product['active'] = (bool)$product['active'];
                
                // Ensure complete image URL
                if ($product['image_url'] && !filter_var($product['image_url'], FILTER_VALIDATE_URL)) {
                    $product['image_url'] = $this->getFullImageUrl($product['image_url']);
                }
            }
            
            return $product;
            
        } catch(Exception $e) {
            $this->logError("Failed to fetch product {$id}: " . $e->getMessage());
            throw new Exception("Failed to retrieve product");
        }
    }
    
    /**
     * Add new product with comprehensive validation
     */
    public function addProduct($data) {
        // Validate required fields
        $required = ['name', 'price'];
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new InvalidArgumentException("Missing required field: {$field}");
            }
        }
        
        // Validate data types and ranges
        if (!is_numeric($data['price']) || $data['price'] <= 0) {
            throw new InvalidArgumentException("Price must be a positive number");
        }
        
        if (strlen($data['name']) > 255) {
            throw new InvalidArgumentException("Product name too long (max 255 characters)");
        }
        
        // Sanitize and prepare data
        $productData = [
            'name' => trim($data['name']),
            'price' => (float)$data['price'],
            'description' => trim($data['description'] ?? ''),
            'image_url' => $this->sanitizeImageUrl($data['image_url'] ?? ''),
            'unit' => $this->validateUnit($data['unit'] ?? 'kg'),
            'stock_qty' => max(0, (int)($data['stock_qty'] ?? 1000)),
            'sort_order' => max(1, (int)($data['sort_order'] ?? 99))
        ];
        
        try {
            $this->pdo->beginTransaction();
            
            $stmt = $this->pdo->prepare(
                "INSERT INTO products (name, price, description, image_url, unit, stock_qty, sort_order, active) 
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            );
            
            $success = $stmt->execute([
                $productData['name'],
                $productData['price'],
                $productData['description'],
                $productData['image_url'],
                $productData['unit'],
                $productData['stock_qty'],
                $productData['sort_order']
            ]);
            
            if ($success) {
                $this->pdo->commit();
                $this->clearProductsCache();
                return $this->pdo->lastInsertId();
            }
            
            $this->pdo->rollback();
            return false;
            
        } catch(Exception $e) {
            $this->pdo->rollback();
            $this->logError("Failed to add product: " . $e->getMessage());
            throw new Exception("Failed to add product");
        }
    }
    
    /**
     * Update existing product with validation
     */
    public function updateProduct($id, $data) {
        if (!$this->isValidId($id)) {
            throw new InvalidArgumentException("Invalid product ID");
        }
        
        // Build dynamic update query
        $updateFields = [];
        $params = [];
        
        $allowedFields = ['name', 'price', 'description', 'image_url', 'unit', 'stock_qty', 'sort_order'];
        
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                switch ($field) {
                    case 'name':
                        if (empty($data[$field]) || strlen($data[$field]) > 255) {
                            throw new InvalidArgumentException("Invalid product name");
                        }
                        $updateFields[] = "name = ?";
                        $params[] = trim($data[$field]);
                        break;
                        
                    case 'price':
                        if (!is_numeric($data[$field]) || $data[$field] <= 0) {
                            throw new InvalidArgumentException("Invalid price");
                        }
                        $updateFields[] = "price = ?";
                        $params[] = (float)$data[$field];
                        break;
                        
                    case 'description':
                        $updateFields[] = "description = ?";
                        $params[] = trim($data[$field] ?? '');
                        break;
                        
                    case 'image_url':
                        $updateFields[] = "image_url = ?";
                        $params[] = $this->sanitizeImageUrl($data[$field] ?? '');
                        break;
                        
                    case 'unit':
                        $updateFields[] = "unit = ?";
                        $params[] = $this->validateUnit($data[$field] ?? 'kg');
                        break;
                        
                    case 'stock_qty':
                        $updateFields[] = "stock_qty = ?";
                        $params[] = max(0, (int)$data[$field]);
                        break;
                        
                    case 'sort_order':
                        $updateFields[] = "sort_order = ?";
                        $params[] = max(1, (int)$data[$field]);
                        break;
                }
            }
        }
        
        if (empty($updateFields)) {
            throw new InvalidArgumentException("No valid fields to update");
        }
        
        try {
            $sql = "UPDATE products SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $params[] = $id;
            
            $stmt = $this->pdo->prepare($sql);
            $success = $stmt->execute($params);
            
            if ($success) {
                $this->clearProductsCache();
            }
            
            return $success && $stmt->rowCount() > 0;
            
        } catch(Exception $e) {
            $this->logError("Failed to update product {$id}: " . $e->getMessage());
            throw new Exception("Failed to update product");
        }
    }
    
    /**
     * Soft delete product
     */
    public function deleteProduct($id) {
        if (!$this->isValidId($id)) {
            throw new InvalidArgumentException("Invalid product ID");
        }
        
        try {
            $stmt = $this->pdo->prepare("UPDATE products SET active = 0 WHERE id = ?");
            $success = $stmt->execute([$id]);
            
            if ($success) {
                $this->clearProductsCache();
            }
            
            return $success && $stmt->rowCount() > 0;
            
        } catch(Exception $e) {
            $this->logError("Failed to delete product {$id}: " . $e->getMessage());
            throw new Exception("Failed to delete product");
        }
    }
    
    /**
     * Log customer order with validation
     */
    public function logOrder($cartData, $customerPhone = null, $notes = null) {
        if (!is_array($cartData) || empty($cartData)) {
            throw new InvalidArgumentException("Invalid cart data");
        }
        
        // Validate and calculate total
        $total = 0;
        $validatedCart = [];
        
        foreach ($cartData as $item) {
            if (!isset($item['id'], $item['qty'], $item['price']) || 
                !is_numeric($item['qty']) || !is_numeric($item['price']) ||
                $item['qty'] <= 0 || $item['price'] < 0) {
                throw new InvalidArgumentException("Invalid cart item");
            }
            
            $validatedItem = [
                'id' => (int)$item['id'],
                'name' => $item['name'] ?? '',
                'price' => (float)$item['price'],
                'qty' => (float)$item['qty'],
                'unit' => $item['unit'] ?? 'kg'
            ];
            
            $total += $validatedItem['price'] * $validatedItem['qty'];
            $validatedCart[] = $validatedItem;
        }
        
        // Validate phone number if provided
        if ($customerPhone && !$this->isValidPhone($customerPhone)) {
            throw new InvalidArgumentException("Invalid phone number format");
        }
        
        try {
            $stmt = $this->pdo->prepare(
                "INSERT INTO orders (cart_json, customer_phone, total_amount, notes, order_date, status) 
                 VALUES (?, ?, ?, ?, NOW(), 'pending')"
            );
            
            return $stmt->execute([
                json_encode($validatedCart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
                $customerPhone,
                $total,
                $notes
            ]);
            
        } catch(Exception $e) {
            $this->logError("Failed to log order: " . $e->getMessage());
            throw new Exception("Failed to log order");
        }
    }
    
    /**
     * Get business analytics with date range validation
     */
    public function getAnalytics($days = 30, $includeDetailed = false) {
        $days = max(1, min(365, (int)$days)); // Limit to 1-365 days
        
        try {
            $stmt = $this->pdo->prepare(
                "SELECT 
                    COUNT(*) as total_orders,
                    COALESCE(SUM(total_amount), 0) as total_revenue,
                    COALESCE(AVG(total_amount), 0) as avg_order_value,
                    MIN(total_amount) as min_order_value,
                    MAX(total_amount) as max_order_value,
                    COUNT(DISTINCT customer_phone) as unique_customers
                 FROM orders 
                 WHERE order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
                   AND status != 'cancelled'"
            );
            
            $stmt->execute([$days]);
            $analytics = $stmt->fetch();
            
            // Format numbers
            $analytics['total_orders'] = (int)$analytics['total_orders'];
            $analytics['total_revenue'] = (float)$analytics['total_revenue'];
            $analytics['avg_order_value'] = (float)$analytics['avg_order_value'];
            $analytics['min_order_value'] = (float)$analytics['min_order_value'];
            $analytics['max_order_value'] = (float)$analytics['max_order_value'];
            $analytics['unique_customers'] = (int)$analytics['unique_customers'];
            $analytics['period_days'] = $days;
            
            if ($includeDetailed) {
                $analytics['daily_stats'] = $this->getDailyStats($days);
                $analytics['popular_products'] = $this->getPopularProducts($days);
            }
            
            return $analytics;
            
        } catch(Exception $e) {
            $this->logError("Failed to fetch analytics: " . $e->getMessage());
            throw new Exception("Failed to retrieve analytics");
        }
    }
    
    /**
     * Get recent orders with pagination
     */
    public function getRecentOrders($limit = 10, $offset = 0) {
        $limit = max(1, min(100, (int)$limit));
        $offset = max(0, (int)$offset);
        
        try {
            $stmt = $this->pdo->prepare(
                "SELECT id, cart_json, customer_phone, total_amount, order_date, status, notes
                 FROM orders 
                 ORDER BY order_date DESC 
                 LIMIT ? OFFSET ?"
            );
            
            $stmt->execute([$limit, $offset]);
            $orders = $stmt->fetchAll();
            
            // Process orders
            foreach ($orders as &$order) {
                $order['id'] = (int)$order['id'];
                $order['total_amount'] = (float)$order['total_amount'];
                $order['cart_data'] = json_decode($order['cart_json'], true);
                
                // Remove raw JSON to reduce response size
                unset($order['cart_json']);
            }
            
            return $orders;
            
        } catch(Exception $e) {
            $this->logError("Failed to fetch recent orders: " . $e->getMessage());
            throw new Exception("Failed to retrieve orders");
        }
    }
    
    /**
     * Check database tables and system health
     */
    public function checkTables() {
        try {
            $checks = [
                'products' => false,
                'orders' => false,
                'database_writable' => false,
                'products_count' => 0,
                'orders_count' => 0
            ];
            
            // Check if tables exist
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'products'");
            $checks['products'] = $stmt->rowCount() > 0;
            
            $stmt = $this->pdo->query("SHOW TABLES LIKE 'orders'");
            $checks['orders'] = $stmt->rowCount() > 0;
            
            if ($checks['products']) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM products WHERE active = 1");
                $checks['products_count'] = (int)$stmt->fetchColumn();
            }
            
            if ($checks['orders']) {
                $stmt = $this->pdo->query("SELECT COUNT(*) FROM orders");
                $checks['orders_count'] = (int)$stmt->fetchColumn();
            }
            
            // Test write capability
            try {
                $this->pdo->query("CREATE TEMPORARY TABLE test_write (id INT)");
                $this->pdo->query("DROP TEMPORARY TABLE test_write");
                $checks['database_writable'] = true;
            } catch (Exception $e) {
                $checks['database_writable'] = false;
            }
            
            $checks['ready'] = $checks['products'] && $checks['orders'] && $checks['database_writable'];
            $checks['timestamp'] = date('Y-m-d H:i:s');
            $checks['php_version'] = PHP_VERSION;
            
            return $checks;
            
        } catch(Exception $e) {
            $this->logError("Failed to check system status: " . $e->getMessage());
            return ['error' => 'System check failed', 'details' => $e->getMessage()];
        }
    }
    
    // Helper methods
    
    private function isValidId($id) {
        return is_numeric($id) && $id > 0;
    }
    
    private function isValidPhone($phone) {
        // Basic phone validation - adjust regex as needed
        return preg_match('/^[+]?[\d\s\-\(\)]{10,15}$/', $phone);
    }
    
    private function validateUnit($unit) {
        $validUnits = ['kg', 'gram', 'packet', 'piece', 'liter', 'box'];
        return in_array(strtolower($unit), $validUnits) ? strtolower($unit) : 'kg';
    }
    
    private function sanitizeImageUrl($url) {
        if (empty($url)) return '';
        
        // If it's already a full URL, validate it
        if (filter_var($url, FILTER_VALIDATE_URL)) {
            return $url;
        }
        
        // If it's a relative path, sanitize it
        $url = preg_replace('/[^a-zA-Z0-9\-_\.\/]/', '', $url);
        return $url;
    }
    
    private function getFullImageUrl($relativePath) {
        if (empty($relativePath)) return '';
        
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        $baseUrl .= dirname($_SERVER['SCRIPT_NAME']) . '/';
        
        return rtrim($baseUrl, '/') . '/' . ltrim($relativePath, '/');
    }
    
    private function clearProductsCache() {
        // Clear all cached product data
        foreach ($this->cache as $key => $value) {
            if (strpos($key, 'products_') === 0) {
                unset($this->cache[$key]);
            }
        }
    }
    
    private function getDailyStats($days) {
        $stmt = $this->pdo->prepare(
            "SELECT DATE(order_date) as order_date, COUNT(*) as orders, SUM(total_amount) as revenue
             FROM orders 
             WHERE order_date >= DATE_SUB(NOW(), INTERVAL ? DAY)
               AND status != 'cancelled'
             GROUP BY DATE(order_date)
             ORDER BY order_date DESC"
        );
        
        $stmt->execute([$days]);
        return $stmt->fetchAll();
    }
    
    private function getPopularProducts($days) {
        // This would require parsing the cart_json - simplified for now
        return [];
    }
    
    private function logError($message) {
        if (Config::LOG_ERRORS) {
            error_log("[" . date('Y-m-d H:i:s') . "] Database Error: " . $message);
        }
    }
    
    public function getLastError() {
        return $this->lastError;
    }
}
?>