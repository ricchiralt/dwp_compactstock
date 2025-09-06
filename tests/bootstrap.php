<?php

// Bootstrap para los tests del módulo dwp_compactstock

// Definir constantes de PrestaShop si no están definidas
if (!defined('_PS_VERSION_')) {
    define('_PS_VERSION_', '1.6.1.11');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'psan_');
}

// Cargar configuración de la base de datos
require_once dirname(dirname(dirname(__DIR__))) . '/config/settings.inc.php';

// Mock de clases de PrestaShop necesarias para los tests
if (!class_exists('Module')) {
    class Module
    {
        public $name;
        public $tab;
        public $version;
        public $author;
        public $need_instance;
        public $bootstrap;
        public $displayName;
        public $description;
        public $module_key;
        
        public function __construct() {}
        
        public function install() {
            return true;
        }
        
        public function uninstall() {
            return true;
        }
        
        public function registerHook($hook) {
            return true;
        }
        
        public function l($string) {
            return $string;
        }
    }
}

if (!class_exists('Order')) {
    class Order
    {
        public $id;
        
        public function __construct($id = null) {
            $this->id = $id;
        }
        
        public function getProducts() {
            return [
                [
                    'product_id' => 123,
                    'product_attribute_id' => 10,
                    'product_quantity' => 1
                ]
            ];
        }
    }
}

if (!class_exists('Product')) {
    class Product
    {
        public static function getProductCategories($id_product) {
            // Mock: returns category 1200 for tests
            return [1200, 500];
        }
    }
}

if (!class_exists('Validate')) {
    class Validate
    {
        public static function isLoadedObject($object) {
            return is_object($object) && isset($object->id) && $object->id > 0;
        }
    }
}

if (!class_exists('Db')) {
    class Db
    {
        private static $instance;
        
        public static function getInstance() {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }
        
        public function getValue($sql) {
            // Mock implementation
            return 0;
        }
        
        public function execute($sql) {
            // Mock implementation
            return true;
        }
        
        public function executeS($sql) {
            // Mock implementation for bulk queries
            return array();
        }
        
        public function getMsgError() {
            return 'Mock DB error';
        }
    }
}