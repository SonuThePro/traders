-- setup.sql - Database setup for M. Pimpale Traders
-- Run this in your hosting control panel's phpMyAdmin or MySQL interface

-- Create the database (if your hosting allows it)
-- CREATE DATABASE pimpale_traders;
-- USE pimpale_traders;

-- Create products table
CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    description TEXT,
    image_url VARCHAR(500),
    unit VARCHAR(20) DEFAULT 'kg',
    stock_qty INT DEFAULT 1000,
    active TINYINT(1) DEFAULT 1,
    sort_order INT DEFAULT 10,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create orders table for logging customer orders
CREATE TABLE IF NOT EXISTS orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cart_json TEXT NOT NULL,
    customer_phone VARCHAR(15),
    total_amount DECIMAL(10,2),
    order_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'confirmed', 'delivered', 'cancelled') DEFAULT 'pending',
    notes TEXT
);

-- Insert your current products
INSERT INTO products (name, price, description, image_url, unit, sort_order, active) VALUES
('Basmati Rice', 90.00, 'Aromatic long-grain basmati rice, perfect for biryani and pulao.', 'https://imgs.search.brave.com/7xJYMkDUPA34f-Ff3nxLOAFyzsFx8a1zDN1MFOtGK3I/rs:fit:860:0:0:0/g:ce/aHR0cHM6Ly9zdC5k/ZXBvc2l0cGhvdG9z/LmNvbS8yMjUyNTQx/LzIzOTQvaS80NTAv/ZGVwb3NpdHBob3Rv/c18yMzk0ODM0Ny1z/dG9jay1waG90by1i/YXNtYXRpLXJpY2Uu/anBn', 'kg', 1, 1),

('Khusboo Biryani Rice', 80.00, 'Fragrant biryani blend that absorbs flavors beautifully.', 'https://imgs.search.brave.com/qKwAlskpaL5g6yIb3YVrzdOmEryjAFaXcy_SA_RcjUQ/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly80Lmlt/aW1nLmNvbS9kYXRh/NC9FVS9BVi9NWS0y/ODM0ODUwNi9raHVz/aGJvby1yaWNlLTI1/MHgyNTAuanBn', 'kg', 2, 1),

('Indrayani Rice', 70.00, 'Soft and fluffy — great for everyday meals.', 'https://imgs.search.brave.com/pIU8I-wzPa6wy9HGTgHpECTgS0_P5or-2HUAIFXkM2k/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly93d3cu/YXJhbnlhcHVyZWZv/b2QuY29tL2Nkbi9z/aG9wL2ZpbGVzL0lN/Ry0yMDI0MDIxNC1X/QTAwMDUuanBnP3Y9/MTcwNzg4ODk5MiZ3/aWR0aD0xNDQ1', 'kg', 3, 1),

('Daptari Rice', 80.00, 'Reliable quality rice, widely used in households.', 'https://imgs.search.brave.com/ZInrSTBmfeEZztRAXEu7YVP8CGKD9DTerZ1943rKot0/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly81Lmlt/aW1nLmNvbS9kYXRh/NS9OQS9UTi9BVi9T/RUxMRVItNDE0Mjcx/NjEvb3J5emEtZGFm/dGFyaS1yaWNlLTUw/MHg1MDAuanBn', 'kg', 4, 1),

('Chimansaal Rice', 80.00, 'Traditional variety known for its texture.', 'https://imgs.search.brave.com/y8ZpGdyU45_ERoayAQjiObpG0d20A71EUjZUyC8ZHW8/rs:fit:500:0:1:0/g:ce/aHR0cHM6Ly9vb29m/YXJtcy5jb20vY2Ru/L3Nob3AvcHJvZHVj/dHMvT09PX0Zhcm1z/X0NoaW1hbnNhYWxf/UmljZV9TZW1pcG9s/aXNoZWQuanBnP3Y9/MTczODY1MDQ5NCZ3/aWR0aD0xMTAw', 'kg', 5, 1),

('Udid dal Papad', 400.00, 'Crispy urad dal papad — perfect accompaniment to meals.', 'images/udiddal.jpg', 'packet', 6, 1),

('Kurdai Papad', 230.00, 'Light, crunchy papad made from moong dal.', 'images/kurdai.jpg', 'packet', 7, 1),

('Nachani che Papad', 80.00, 'Spiced papad with a punch of flavors.', 'images/nachani.jpg', 'packet', 8, 1);

-- Create indexes for better performance
CREATE INDEX idx_products_active ON products(active);
CREATE INDEX idx_products_sort ON products(sort_order);
CREATE INDEX idx_orders_date ON orders(order_date);

-- Insert a test order (optional)
INSERT INTO orders (cart_json, total_amount, notes) VALUES 
('{"test": "This is a test order from setup"}', 0.00, 'Setup test order');

-- Show confirmation message
SELECT 'Database setup completed successfully!' as message;
SELECT COUNT(*) as total_products FROM products WHERE active = 1;