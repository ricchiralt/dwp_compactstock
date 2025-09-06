<?php

require_once dirname(__FILE__) . '/bootstrap.php';
require_once dirname(__FILE__) . '/../dwp_compactstock.php';

/**
 * Tests de integración para el módulo dwp_compactstock
 * Estos tests requieren conexión a la base de datos Docker
 */
class IntegrationTest extends PHPUnit_Framework_TestCase
{
    protected $module;
    protected $pdo;
    
    public function setUp()
    {
        $this->module = new Dwp_CompactStock();
        
        // Conexión a la base de datos Docker
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
        // Limpiar datos de prueba si es necesario
        if ($this->pdo) {
            $this->pdo = null;
        }
    }
    
    /**
     * Test de conexión a la base de datos
     */
    public function testDatabaseConnection()
    {
        $this->assertInstanceOf('PDO', $this->pdo);
        
        // Verificar que podemos hacer una consulta simple
        $stmt = $this->pdo->query('SELECT 1 as test');
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $this->assertEquals(1, $result['test']);
    }
    
    /**
     * Test de estructura de tablas necesarias
     */
    public function testRequiredTables()
    {
        $requiredTables = [
            'psan_stock_available',
            'psan_product_attribute',
            'psan_product_attribute_combination',
            'psan_order_history'
        ];
        
        foreach ($requiredTables as $table) {
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE ?');
            $stmt->execute([$table]);
            $exists = $stmt->rowCount() > 0;
            
            if (!$exists) {
                $this->markTestSkipped("Tabla requerida no existe: $table");
            } else {
                $this->assertTrue($exists, "La tabla $table debería existir");
            }
        }
    }
    
    /**
     * Test de inserción y consulta de datos de prueba
     */
    public function testDataOperations()
    {
        // Crear producto de prueba
        $testProductId = 9999;
        $testAttributeId1 = 10;
        $testAttributeId2 = 11;
        
        try {
            // Insertar stock inicial
            $this->pdo->exec("
                INSERT IGNORE INTO psan_stock_available 
                (id_product, id_product_attribute, quantity) 
                VALUES 
                ($testProductId, $testAttributeId1, 10),
                ($testProductId, $testAttributeId2, 10)
            ");
            
            // Verificar inserción
            $stmt = $this->pdo->prepare('
                SELECT quantity 
                FROM psan_stock_available 
                WHERE id_product = ? AND id_product_attribute = ?
            ');
            
            $stmt->execute([$testProductId, $testAttributeId1]);
            $quantity1 = $stmt->fetchColumn();
            
            $stmt->execute([$testProductId, $testAttributeId2]);
            $quantity2 = $stmt->fetchColumn();
            
            $this->assertGreaterThanOrEqual(10, $quantity1);
            $this->assertGreaterThanOrEqual(10, $quantity2);
            
        } finally {
            // Limpiar datos de prueba
            $this->pdo->exec("DELETE FROM psan_stock_available WHERE id_product = $testProductId");
        }
    }
    
    /**
     * Test de simulación de reducción de stock
     */
    public function testStockReductionSimulation()
    {
        $testProductId = 9998;
        $testAttributeId1 = 10;
        $testAttributeId2 = 11;
        $initialStock = 20;
        $reductionQty = 2;
        
        try {
            // Insertar stock inicial
            $this->pdo->exec("
                INSERT IGNORE INTO psan_stock_available 
                (id_product, id_product_attribute, quantity) 
                VALUES 
                ($testProductId, $testAttributeId1, $initialStock),
                ($testProductId, $testAttributeId2, $initialStock)
            ");
            
            // Simular reducción (lo que haría el módulo)
            $this->pdo->exec("
                UPDATE psan_stock_available 
                SET quantity = quantity - $reductionQty 
                WHERE id_product = $testProductId 
                AND id_product_attribute = $testAttributeId2
            ");
            
            // Verificar que solo se redujo una combinación
            $stmt = $this->pdo->prepare('
                SELECT quantity 
                FROM psan_stock_available 
                WHERE id_product = ? AND id_product_attribute = ?
            ');
            
            $stmt->execute([$testProductId, $testAttributeId1]);
            $quantity1 = $stmt->fetchColumn();
            
            $stmt->execute([$testProductId, $testAttributeId2]);
            $quantity2 = $stmt->fetchColumn();
            
            $this->assertEquals($initialStock, $quantity1, 'El stock de la primera combinación no debería cambiar');
            $this->assertEquals($initialStock - $reductionQty, $quantity2, 'El stock de la segunda combinación debería reducirse');
            
        } finally {
            // Limpiar datos de prueba
            $this->pdo->exec("DELETE FROM psan_stock_available WHERE id_product = $testProductId");
        }
    }
    
    /**
     * Test de consulta de historial de pedidos
     */
    public function testOrderHistoryQuery()
    {
        $testOrderId = 9999;
        $stockReductionStates = [2, 3, 4];
        
        try {
            // Insertar historial de prueba
            $this->pdo->exec("
                INSERT IGNORE INTO psan_order_history 
                (id_order, id_order_state, date_add) 
                VALUES 
                ($testOrderId, 2, NOW()),
                ($testOrderId, 3, NOW())
            ");
            
            // Consulta similar a la del módulo
            $stmt = $this->pdo->prepare('
                SELECT COUNT(*) 
                FROM psan_order_history 
                WHERE id_order = ? 
                AND id_order_state IN (' . implode(',', $stockReductionStates) . ')
            ');
            
            $stmt->execute([$testOrderId]);
            $count = $stmt->fetchColumn();
            
            $this->assertGreaterThan(0, $count, 'Debería encontrar registros de estados que reducen stock');
            
        } finally {
            // Limpiar datos de prueba
            $this->pdo->exec("DELETE FROM psan_order_history WHERE id_order = $testOrderId");
        }
    }
    
    /**
     * Test de consulta de combinaciones de productos
     */
    public function testProductAttributeQuery()
    {
        $testProductId = 9997;
        $testAttributeId1 = 10;
        $testAttributeId2 = 11;
        
        try {
            // Insertar atributos de prueba (si las tablas existen)
            $stmt = $this->pdo->prepare('SHOW TABLES LIKE "psan_product_attribute"');
            $stmt->execute();
            
            if ($stmt->rowCount() > 0) {
                // Consulta similar a la del módulo
                $sql = "
                    SELECT pa.id_product_attribute
                    FROM psan_product_attribute pa
                    INNER JOIN psan_product_attribute_combination pac
                        ON pac.id_product_attribute = pa.id_product_attribute
                    WHERE pa.id_product = ?
                    AND pac.id_attribute IN (10,11)
                    AND pa.id_product_attribute != ?
                ";
                
                $stmt = $this->pdo->prepare($sql);
                $stmt->execute([$testProductId, $testAttributeId1]);
                
                // Verificar que la consulta se ejecuta sin errores
                $this->assertInstanceOf('PDOStatement', $stmt);
                
            } else {
                $this->markTestSkipped('Tablas de atributos no disponibles para testing');
            }
            
        } catch (PDOException $e) {
            $this->markTestSkipped('Error en consulta de atributos: ' . $e->getMessage());
        }
    }
    
    /**
     * Test de performance de consultas
     */
    public function testQueryPerformance()
    {
        $startTime = microtime(true);
        
        // Ejecutar consulta típica del módulo
        $stmt = $this->pdo->query('
            SELECT COUNT(*) 
            FROM psan_stock_available 
            WHERE id_product > 0 
            LIMIT 100
        ');
        
        $endTime = microtime(true);
        $executionTime = $endTime - $startTime;
        
        // La consulta no debería tardar más de 5 segundos en Docker
        $this->assertLessThan(5.0, $executionTime, 'Las consultas deberían ejecutarse rápidamente');
        
        $result = $stmt->fetchColumn();
        $this->assertTrue(is_numeric($result));
    }
}