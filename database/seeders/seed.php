<?php

declare(strict_types=1);

/**
 * Sample data for manual/Postman testing — enough categories and products
 * (25+) to exercise pagination, search, filtering, and sorting once those
 * endpoints exist. Idempotent: uses INSERT IGNORE against the unique
 * name/slug/sku constraints, so re-running never duplicates rows.
 *
 * Usage: php database/seeders/seed.php
 */

require __DIR__ . '/../../src/Config/Env.php';
require __DIR__ . '/../../src/Config/Database.php';

use App\Config\Database;
use App\Config\Env;

Env::load(__DIR__ . '/../../.env');
$pdo = Database::connection();

$categories = [
    ['name' => 'Electronics', 'slug' => 'electronics', 'description' => 'Devices, accessories, and components.'],
    ['name' => 'Furniture', 'slug' => 'furniture', 'description' => 'Office and home furniture.'],
    ['name' => 'Stationery', 'slug' => 'stationery', 'description' => 'Paper goods, writing tools, and office supplies.'],
    ['name' => 'Groceries', 'slug' => 'groceries', 'description' => 'Packaged food and household consumables.'],
];

$insertCategory = $pdo->prepare(
    'INSERT IGNORE INTO categories (name, slug, description) VALUES (:name, :slug, :description)'
);
foreach ($categories as $category) {
    $insertCategory->execute($category);
}

$categoryIds = $pdo->query('SELECT id, slug FROM categories')->fetchAll();
$idBySlug = array_column($categoryIds, 'id', 'slug');

$products = [];
$catalog = [
    'electronics' => ['Wireless Mouse', 'Mechanical Keyboard', 'USB-C Hub', 'Webcam 1080p', 'Bluetooth Speaker', 'Laptop Stand', 'Monitor Arm', 'Portable SSD 1TB'],
    'furniture' => ['Office Chair', 'Standing Desk', 'Bookshelf', 'Filing Cabinet', 'Desk Lamp', 'Conference Table'],
    'stationery' => ['Notebook A5', 'Ballpoint Pen Pack', 'Sticky Notes', 'Stapler', 'Whiteboard Markers', 'Binder Clips'],
    'groceries' => ['Coffee Beans 1kg', 'Green Tea Box', 'Pasta 500g', 'Olive Oil 1L', 'Rice 5kg', 'Cereal Box'],
];

$sku = 1000;
foreach ($catalog as $slug => $names) {
    foreach ($names as $name) {
        $sku++;
        $products[] = [
            'category_id' => $idBySlug[$slug],
            'name' => $name,
            'sku' => 'SKU-' . $sku,
            'description' => $name . ' — sample seed data.',
            'price' => round(mt_rand(500, 25000) / 100, 2),
            'quantity' => mt_rand(0, 200),
        ];
    }
}

$insertProduct = $pdo->prepare(
    'INSERT IGNORE INTO products (category_id, name, sku, description, price, quantity)
     VALUES (:category_id, :name, :sku, :description, :price, :quantity)'
);

$count = 0;
foreach ($products as $product) {
    $count += $insertProduct->execute($product) ? $insertProduct->rowCount() : 0;
}

echo count($categories) . " categories ensured, {$count} product(s) inserted (" . count($products) . " total in seed set).\n";
