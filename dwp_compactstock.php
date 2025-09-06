<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

class Dwp_CompactStock extends Module
{
    const DEBUG_MODE = false; // Change to true to enable debug
    const TARGET_CATEGORY = 1200;
    const ATTRIBUTE_WITH_BOX = 10;
    const ATTRIBUTE_WITHOUT_BOX = 11;
    
    public function __construct()
    {
        $this->name = 'dwp_compactstock';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Desarrollo Web Profesional';
        $this->need_instance = 0;
        $this->bootstrap = true;

        // Set module icon
        if (file_exists(dirname(__FILE__).'/logo.png')) {
            $this->module_key = '';
        }

        parent::__construct();

        $this->displayName = $this->l('Automatic stock reduction for Compact Discs');
        $this->description = $this->l('For compact discs, reduces stock of both combinations (with box / without box) on each purchase.');
    }

    public function install()
    {
        return parent::install() &&
            $this->registerHook('actionOrderStatusPostUpdate');
    }

    public function uninstall()
    {
        return parent::uninstall();
    }

    private function debugLog($message, $data = null)
    {
        if (!self::DEBUG_MODE) {
            return;
        }
        
        $log_entry = '[' . date('Y-m-d H:i:s') . '] DWP_CompactStock: ' . $message;
        if ($data !== null) {
            $log_entry .= ' | Data: ' . json_encode($data);
        }
        
        // error_log($log_entry, 3, dirname(__FILE__) . '/debug_log.php');
        // In production, comment the line above to disable logging
    }
    
    private function validateInput($params)
    {
        if (!isset($params['newOrderStatus'])) {
            // $this->debugLog('ERROR: Missing newOrderStatus parameter');
            return false;
        }
        
        if (!isset($params['id_order'])) {
            // $this->debugLog('ERROR: Missing id_order parameter');
            return false;
        }
        
        if (!is_object($params['newOrderStatus']) || !isset($params['newOrderStatus']->id)) {
            // $this->debugLog('ERROR: Invalid newOrderStatus object');
            return false;
        }
        
        $id_order = (int)$params['id_order'];
        if ($id_order <= 0) {
            // $this->debugLog('ERROR: Invalid order ID', $id_order);
            return false;
        }
        
        return true;
    }
    
    public function hookActionOrderStatusPostUpdate($params)
    {
        // $this->debugLog('Hook triggered', $params);
        
        if (!$this->validateInput($params)) {
            return false;
        }

        $new_status = (int)$params['newOrderStatus']->id;
        $id_order = (int)$params['id_order'];
        
        // $this->debugLog('Processing order status change', [
        //     'order_id' => $id_order,
        //     'new_status' => $new_status
        // ]);
        
        // States that reduce stock
        $stock_reduction_states = array(2, 3, 4); // Payment accepted, Preparation, Shipped
        // States that restore stock
        $stock_restoration_states = array(6, 7, 8); // Cancelled, Refunded, Payment error
        
        // Check if the order previously had a state that reduced stock - SECURE
        $had_stock_reduction = (int)Db::getInstance()->getValue('
            SELECT COUNT(*) 
            FROM `'._DB_PREFIX_.'order_history` 
            WHERE id_order = '.(int)$id_order.' 
            AND id_order_state IN ('.implode(',', array_map('intval', $stock_reduction_states)).')');
            
        // $this->debugLog('Stock reduction history check', [
        //     'order_id' => $id_order,
        //     'had_previous_reduction' => $had_stock_reduction
        // ]);
        
        $should_reduce = in_array($new_status, $stock_reduction_states) && ($had_stock_reduction == 0);
        $should_restore = in_array($new_status, $stock_restoration_states) && ($had_stock_reduction > 0);
        
        if (!$should_reduce && !$should_restore) {
            // $this->debugLog('No action needed for this status change');
            return false;
        }

        // $this->debugLog('Action determined', [
        //     'should_reduce' => $should_reduce,
        //     'should_restore' => $should_restore
        // ]);

        // Get order products with validation
        try {
            $order = new Order($id_order);
            if (!Validate::isLoadedObject($order)) {
                // $this->debugLog('ERROR: Invalid order object', $id_order);
                return false;
            }
            
            $products = $order->getProducts();
            if (empty($products)) {
                // $this->debugLog('No products found in order', $id_order);
                return false;
            }
            
            // $this->debugLog('Processing products', ['count' => count($products)]);
            
        } catch (Exception $e) {
            // $this->debugLog('ERROR: Exception getting order products', $e->getMessage());
            return false;
        }

        // Optimization: Filter products by category in batch
        $product_ids = array();
        $valid_products = array();
        
        foreach ($products as $product) {
            $id_product = (int)$product['product_id'];
            if ($id_product > 0) {
                $product_ids[] = $id_product;
                $valid_products[$id_product] = $product;
            }
        }
        
        if (empty($product_ids)) {
            // $this->debugLog('No valid products to process');
            return false;
        }
        
        // Get all categories at once (N+1 optimization)
        $categories_sql = 'SELECT id_product, id_category 
                          FROM `'._DB_PREFIX_.'category_product` 
                          WHERE id_product IN ('.implode(',', array_map('intval', $product_ids)).') 
                          AND id_category = '.self::TARGET_CATEGORY;
                          
        $target_products = array();
        try {
            $category_results = $db->executeS($categories_sql);
            foreach ($category_results as $row) {
                $target_products[(int)$row['id_product']] = true;
            }
            
            // $this->debugLog('Products in target category', [
            //     'total_products' => count($product_ids),
            //     'target_products' => count($target_products)
            // ]);
            
        } catch (Exception $e) {
            // $this->debugLog('ERROR: Could not filter products by category', $e->getMessage());
            return false;
        }
        
        if (empty($target_products)) {
            // $this->debugLog('No products in target category found');
            return false;
        }

        // Start transaction for atomic operations
        $db = Db::getInstance();
        if (!$db->execute('START TRANSACTION')) {
            // $this->debugLog('ERROR: Could not start transaction');
            return false;
        }
        
        $transaction_success = true;
        $processed_products = 0;

        foreach ($valid_products as $id_product => $product) {
            // Skip products that are not in the target category
            if (!isset($target_products[$id_product])) {
                continue;
            }
            // Product data validation
            $id_product = (int)$product['product_id'];
            $id_product_attribute = (int)$product['product_attribute_id'];
            $qty = (int)$product['product_quantity'];
            
            if ($id_product <= 0 || $id_product_attribute <= 0 || $qty <= 0) {
                // $this->debugLog('Invalid product data, skipping', $product);
                continue;
            }

            // $this->debugLog('Processing product', [
            //     'product_id' => $id_product,
            //     'attribute_id' => $id_product_attribute,
            //     'quantity' => $qty
            // ]);

            // Category already validated in batch above - optimization applied

            // Search for the other combination safely
            $target_attributes = array(self::ATTRIBUTE_WITH_BOX, self::ATTRIBUTE_WITHOUT_BOX);
            
            $sql = 'SELECT pa.id_product_attribute
                    FROM `'._DB_PREFIX_.'product_attribute` pa
                    INNER JOIN `'._DB_PREFIX_.'product_attribute_combination` pac
                        ON pac.id_product_attribute = pa.id_product_attribute
                    WHERE pa.id_product = '.(int)$id_product.'
                    AND pac.id_attribute IN ('.implode(',', array_map('intval', $target_attributes)).')
                    AND pa.id_product_attribute != '.(int)$id_product_attribute.'
                    LIMIT 1';

            try {
                $other_id = (int)$db->getValue($sql);
                // $this->debugLog('Found related combination', [
                //     'current_attribute' => $id_product_attribute,
                //     'other_attribute' => $other_id
                // ]);
            } catch (Exception $e) {
                // $this->debugLog('ERROR: Could not find related combination', $e->getMessage());
                $transaction_success = false;
                break;
            }

            if ($other_id > 0) {
                // Check current stock before operation
                $current_stock = (int)$db->getValue('
                    SELECT quantity 
                    FROM `'._DB_PREFIX_.'stock_available` 
                    WHERE id_product = '.(int)$id_product.' 
                    AND id_product_attribute = '.(int)$other_id
                );
                
                if ($should_reduce) {
                    // Check that there is sufficient stock to reduce
                    if ($current_stock < $qty) {
                        // $this->debugLog('WARNING: Insufficient stock for reduction', [
                        //     'product_id' => $id_product,
                        //     'attribute_id' => $other_id,
                        //     'current_stock' => $current_stock,
                        //     'requested_reduction' => $qty
                        // ]);
                        // Continue but log the warning
                    }
                    
                    // Reduce the same quantity in the other combination - SECURE
                    $sql = 'UPDATE `'._DB_PREFIX_.'stock_available` 
                            SET quantity = quantity - '.(int)$qty.' 
                            WHERE id_product = '.(int)$id_product.' 
                            AND id_product_attribute = '.(int)$other_id.'
                            LIMIT 1';
                            
                    // $this->debugLog('Reducing stock', [
                    //     'product_id' => $id_product,
                    //     'attribute_id' => $other_id,
                    //     'quantity' => $qty,
                    //     'previous_stock' => $current_stock
                    // ]);
                    
                } else if ($should_restore) {
                    // Restore the same quantity in the other combination - SECURE
                    $sql = 'UPDATE `'._DB_PREFIX_.'stock_available` 
                            SET quantity = quantity + '.(int)$qty.' 
                            WHERE id_product = '.(int)$id_product.' 
                            AND id_product_attribute = '.(int)$other_id.'
                            LIMIT 1';
                            
                    // $this->debugLog('Restoring stock', [
                    //     'product_id' => $id_product,
                    //     'attribute_id' => $other_id,
                    //     'quantity' => $qty,
                    //     'previous_stock' => $current_stock
                    // ]);
                }
                
                // Execute query with error handling
                try {
                    $result = $db->execute($sql);
                    if (!$result) {
                        // $this->debugLog('ERROR: Stock update failed', [
                        //     'sql' => $sql,
                        //     'mysql_error' => $db->getMsgError()
                        // ]);
                        $transaction_success = false;
                        break;
                    }
                    
                    $processed_products++;
                    // $this->debugLog('Stock updated successfully', [
                    //     'product_id' => $id_product,
                    //     'attribute_id' => $other_id
                    // ]);
                    
                } catch (Exception $e) {
                    // $this->debugLog('ERROR: Exception during stock update', $e->getMessage());
                    $transaction_success = false;
                    break;
                }
            } else {
                // $this->debugLog('No related combination found, skipping product', [
                //     'product_id' => $id_product,
                //     'attribute_id' => $id_product_attribute
                // ]);
            }
        }
        
        // Complete transaction
        if ($transaction_success) {
            if ($db->execute('COMMIT')) {
                // $this->debugLog('Transaction committed successfully', [
                //     'processed_products' => $processed_products
                // ]);
                return true;
            } else {
                // $this->debugLog('ERROR: Could not commit transaction');
                $db->execute('ROLLBACK');
                return false;
            }
        } else {
            // $this->debugLog('Transaction rolled back due to errors');
            $db->execute('ROLLBACK');
            return false;
        }
    }
}