CREATE TABLE products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_products_sku (sku),
    KEY idx_products_category_id (category_id),
    KEY idx_products_name (name),
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
