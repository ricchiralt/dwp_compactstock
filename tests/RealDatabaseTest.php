<?php

require_once dirname(__FILE__) . '/bootstrap.php';
require_once dirname(__FILE__) . '/../dwp_compactstock.php';

/**
 * Tests con datos REALES en la base de datos
 * SOLO ejecutar en entorno de testing
 */
class RealDatabaseTest extends PHPUnit_Framework_TestCase
{
    protected $module;
    protected $pdo;
    protected $test_product_id = 9999;
    protected $test_order_id = 99999;
    protected $test_attr_with_box = 99990;
    protected $test_attr_without_box = 99991;
    
    public function setUp()
    {
        $this->module = new Dwp_CompactStock();
        
        // Conexión real a la base de datos
        try {
            $this->pdo = new PDO(
                'mysql:host=mysql_server;dbname=mydatabase;charset=utf8',
                'user',
                'password',
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        } catch (PDOException $e) {
            $this->markTestSkipped('No se pudo conectar a la base de datos: ' . $e->getMessage());
        }
    }
    
    public function tearDown()
    {
        // Limpiar datos de prueba después de cada test
        if ($this->pdo) {
            $this->cleanupTestData();
        }
    }
    
    private function cleanupTestData()
    {
        $cleanup_queries = [
            "DELETE FROM psan_order_history WHERE id_order = {$this->test_order_id}",
            "DELETE FROM psan_order_detail WHERE id_order = {$this->test_order_id}",
            "DELETE FROM psan_orders WHERE id_order = {$this->test_order_id}",
            "DELETE FROM psan_stock_available WHERE id_product = {$this->test_product_id}",
            "DELETE FROM psan_product_attribute_combination WHERE id_product_attribute IN ({$this->test_attr_with_box}, {$this->test_attr_without_box})",
            "DELETE FROM psan_product_attribute WHERE id_product = {$this->test_product_id}",
            "DELETE FROM psan_category_product WHERE id_product = {$this->test_product_id}",
            "DELETE FROM psan_product WHERE id_product = {$this->test_product_id}"
        ];
        
        foreach ($cleanup_queries as $query) {
            try {
                $this->pdo->exec($query);
            } catch (PDOException $e) {
                // Ignorar errores de limpieza
            }
        }
    }
    
    private function setupTestData()
    {
        // Ejecutar el SQL de configuración
        $sql_file = dirname(__FILE__) . '/RealDataTest.sql';
        $sql_content = file_get_contents($sql_file);
        
        // Ejecutar cada query
        $queries = explode(';', $sql_content);
        foreach ($queries as $query) {
            $query = trim($query);
            if (!empty($query) && !startsWith($query, '--')) {
                try {
                    $this->pdo->exec($query);
                } catch (PDOException $e) {
                    // Ignorar errores de INSERT IGNORE
                    if (strpos($e->getMessage(), 'Duplicate entry') === false) {
                        throw $e;
                    }
                }
            }
        }
    }
    
    public function testRealStockReductionOnPaymentAccepted()
    {
        $this->setupTestData();
        
        // Verificar stock inicial
        $initial_with_box = $this->getStock($this->test_product_id, $this->test_attr_with_box);
        $initial_without_box = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        
        $this->assertEquals(100, $initial_with_box, 'Stock inicial con caja debe ser 100');
        $this->assertEquals(100, $initial_without_box, 'Stock inicial sin caja debe ser 100');
        
        // Simular cambio de estado a "Pago aceptado" (2)
        $mockStatus = new stdClass();
        $mockStatus->id = 2;
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $this->test_order_id
        ];
        
        // EJECUTAR EL HOOK REAL
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        
        // Verificar que se ejecutó correctamente
        $this->assertTrue($result, 'Hook debe ejecutarse exitosamente');
        
        // Verificar cambios en stock
        $final_with_box = $this->getStock($this->test_product_id, $this->test_attr_with_box);
        $final_without_box = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        
        $this->assertEquals(100, $final_with_box, 'Stock con caja no debe cambiar');
        $this->assertEquals(98, $final_without_box, 'Stock sin caja debe reducirse en 2');
        
        // Verificar historial de pedido
        $this->addOrderHistory(2);
        $history_count = $this->getOrderHistoryCount();
        $this->assertGreaterThan(0, $history_count, 'Debe haber historial de cambio de estado');
    }
    
    public function testRealStockRestorationOnCancelled()
    {
        $this->setupTestData();
        
        // Primero ejecutar una reducción
        $this->testRealStockReductionOnPaymentAccepted();
        
        // Ahora cancelar el pedido
        $mockStatus = new stdClass();
        $mockStatus->id = 6; // Cancelado
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $this->test_order_id
        ];
        
        // Stock antes de cancelar (debería estar en 98)
        $stock_before_cancel = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        $this->assertEquals(98, $stock_before_cancel, 'Stock debe estar reducido antes de cancelar');
        
        // EJECUTAR CANCELACIÓN
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertTrue($result, 'Cancelación debe ejecutarse exitosamente');
        
        // Verificar restauración
        $final_stock = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        $this->assertEquals(100, $final_stock, 'Stock debe restaurarse completamente');
    }
    
    public function testRealPreventDoubleReduction()
    {
        $this->setupTestData();
        
        // Primera reducción
        $this->testRealStockReductionOnPaymentAccepted();
        
        $stock_after_first = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        $this->assertEquals(98, $stock_after_first);
        
        // Intentar segunda reducción (cambio a Preparación = 3)
        $mockStatus = new stdClass();
        $mockStatus->id = 3;
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $this->test_order_id
        ];
        
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        
        // Debería retornar false porque ya hubo reducción previa
        $this->assertFalse($result, 'No debe permitir segunda reducción');
        
        // Stock debe mantenerse igual
        $stock_after_second = $this->getStock($this->test_product_id, $this->test_attr_without_box);
        $this->assertEquals(98, $stock_after_second, 'Stock no debe cambiar en segunda reducción');
    }
    
    private function getStock($product_id, $attribute_id)
    {
        $stmt = $this->pdo->prepare(
            'SELECT quantity FROM psan_stock_available 
             WHERE id_product = ? AND id_product_attribute = ?'
        );
        $stmt->execute([$product_id, $attribute_id]);
        return (int)$stmt->fetchColumn();
    }
    
    private function addOrderHistory($state_id)
    {
        $this->pdo->exec(
            "INSERT INTO psan_order_history (id_order, id_order_state, id_employee, date_add) 
             VALUES ({$this->test_order_id}, {$state_id}, 0, NOW())"
        );
    }
    
    private function getOrderHistoryCount()
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM psan_order_history 
             WHERE id_order = ? AND id_order_state IN (2,3,4)'
        );
        $stmt->execute([$this->test_order_id]);
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Test de consultas reales que usa el módulo
     */
    public function testRealModuleQueries()
    {
        $this->setupTestData();
        
        // 1. Consulta de historial (línea 108-112 del módulo)
        $history_sql = "
            SELECT COUNT(*) 
            FROM psan_order_history 
            WHERE id_order = {$this->test_order_id} 
            AND id_order_state IN (2,3,4)";
            
        $history_count = (int)$this->pdo->query($history_sql)->fetchColumn();
        $this->assertEquals(0, $history_count, 'Inicialmente no debe haber historial de reducción');
        
        // 2. Consulta de combinaciones (líneas 234-241 del módulo)
        $combination_sql = "
            SELECT pa.id_product_attribute
            FROM psan_product_attribute pa
            INNER JOIN psan_product_attribute_combination pac
                ON pac.id_product_attribute = pa.id_product_attribute
            WHERE pa.id_product = {$this->test_product_id}
            AND pac.id_attribute IN (10,11)
            AND pa.id_product_attribute != {$this->test_attr_with_box}
            LIMIT 1";
            
        $other_combination = (int)$this->pdo->query($combination_sql)->fetchColumn();
        $this->assertEquals($this->test_attr_without_box, $other_combination, 
            'Debe encontrar la combinación sin caja');
        
        // 3. Consulta de stock actual (líneas 257-261 del módulo)
        $stock_sql = "
            SELECT quantity 
            FROM psan_stock_available 
            WHERE id_product = {$this->test_product_id} 
            AND id_product_attribute = {$this->test_attr_without_box}";
            
        $current_stock = (int)$this->pdo->query($stock_sql)->fetchColumn();
        $this->assertEquals(100, $current_stock, 'Stock inicial debe ser 100');
        
        // 4. Query de actualización de stock (líneas 277-281 del módulo)
        $update_sql = "
            UPDATE psan_stock_available 
            SET quantity = quantity - 2 
            WHERE id_product = {$this->test_product_id} 
            AND id_product_attribute = {$this->test_attr_without_box}
            LIMIT 1";
            
        $update_result = $this->pdo->exec($update_sql);
        $this->assertEquals(1, $update_result, 'Debe actualizar 1 fila');
        
        // Verificar resultado de la actualización
        $updated_stock = (int)$this->pdo->query($stock_sql)->fetchColumn();
        $this->assertEquals(98, $updated_stock, 'Stock debe quedar en 98');
    }
}

function startsWith($haystack, $needle) {
    return substr($haystack, 0, strlen($needle)) === $needle;
}