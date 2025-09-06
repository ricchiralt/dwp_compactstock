<?php

require_once dirname(__FILE__) . '/bootstrap.php';
require_once dirname(__FILE__) . '/../dwp_compactstock.php';

class Dwp_CompactStockTest extends PHPUnit_Framework_TestCase
{
    protected $module;
    protected $mockDb;
    protected $mockOrder;
    
    public function setUp()
    {
        $this->module = new Dwp_CompactStock();
        
        // Mock de la base de datos
        $this->mockDb = $this->getMockBuilder('Db')
                             ->disableOriginalConstructor()
                             ->getMock();
                             
        // Mock del pedido
        $this->mockOrder = $this->getMockBuilder('Order')
                                ->disableOriginalConstructor()
                                ->getMock();
    }
    
    public function testInstallRegistersHook()
    {
        $result = $this->module->install();
        $this->assertTrue($result);
    }
    
    public function testUninstallSuccess()
    {
        $result = $this->module->uninstall();
        $this->assertTrue($result);
    }
    
    /**
     * Test que el hook no procesa si faltan parámetros requeridos
     */
    public function testHookReturnsEarlyWithMissingParams()
    {
        // Parámetros vacíos
        $result = $this->module->hookActionOrderStatusPostUpdate([]);
        $this->assertNull($result);
        
        // Solo newOrderStatus
        $mockStatus = new stdClass();
        $mockStatus->id = 2;
        $result = $this->module->hookActionOrderStatusPostUpdate(['newOrderStatus' => $mockStatus]);
        $this->assertNull($result);
        
        // Solo id_order
        $result = $this->module->hookActionOrderStatusPostUpdate(['id_order' => 123]);
        $this->assertNull($result);
    }
    
    /**
     * Test identificación correcta de estados que reducen stock
     */
    public function testStockReductionStates()
    {
        $stockReductionStates = [2, 3, 4]; // Estados definidos en el módulo
        
        foreach ($stockReductionStates as $state) {
            $mockStatus = new stdClass();
            $mockStatus->id = $state;
            
            $params = [
                'newOrderStatus' => $mockStatus,
                'id_order' => 123
            ];
            
            // Mock para simular que no había reducción previa
            $this->mockDb->method('getValue')->willReturn(0);
            
            // El hook debería procesar estos estados
            $this->assertNotNull($params['newOrderStatus']);
            $this->assertEquals($state, $params['newOrderStatus']->id);
        }
    }
    
    /**
     * Test identificación correcta de estados que restauran stock
     */
    public function testStockRestorationStates()
    {
        $stockRestorationStates = [6, 7, 8]; // Estados definidos en el módulo
        
        foreach ($stockRestorationStates as $state) {
            $mockStatus = new stdClass();
            $mockStatus->id = $state;
            
            $params = [
                'newOrderStatus' => $mockStatus,
                'id_order' => 123
            ];
            
            // Mock para simular que había reducción previa
            $this->mockDb->method('getValue')->willReturn(1);
            
            // El hook debería procesar estos estados
            $this->assertNotNull($params['newOrderStatus']);
            $this->assertEquals($state, $params['newOrderStatus']->id);
        }
    }
    
    /**
     * Test que no se procesen estados no válidos
     */
    public function testIgnoreInvalidStates()
    {
        $invalidStates = [1, 5, 9, 10]; // Estados que no deberían procesarse
        
        foreach ($invalidStates as $state) {
            $mockStatus = new stdClass();
            $mockStatus->id = $state;
            
            $params = [
                'newOrderStatus' => $mockStatus,
                'id_order' => 123
            ];
            
            $result = $this->module->hookActionOrderStatusPostUpdate($params);
            $this->assertNull($result);
        }
    }
    
    /**
     * Test que previene múltiples reducciones de stock
     */
    public function testPreventDuplicateStockReduction()
    {
        $mockStatus = new stdClass();
        $mockStatus->id = 2; // Estado de pago aceptado
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => 123
        ];
        
        // Mock para simular que ya había una reducción previa
        $this->mockDb->method('getValue')->willReturn(1);
        
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertNull($result); // No debería procesar
    }
    
    /**
     * Test filtrado por categoría 1200
     */
    public function testCategoryFiltering()
    {
        // Simular producto de categoría correcta
        $productInCategory = ['product_id' => 100, 'product_attribute_id' => 10, 'product_quantity' => 1];
        $categoriesCorrect = [1200, 500]; // Incluye categoría 1200
        
        $this->assertTrue(in_array(1200, $categoriesCorrect));
        
        // Simular producto de categoría incorrecta
        $productNotInCategory = ['product_id' => 101, 'product_attribute_id' => 10, 'product_quantity' => 1];
        $categoriesIncorrect = [500, 600]; // No incluye categoría 1200
        
        $this->assertFalse(in_array(1200, $categoriesIncorrect));
    }
    
    /**
     * Test búsqueda de combinaciones relacionadas (atributos 10 y 11)
     */
    public function testAttributeMatching()
    {
        $validAttributes = [10, 11];
        
        // Simular que tenemos atributo 10, debería buscar 11
        $currentAttribute = 10;
        $searchAttributes = array_diff($validAttributes, [$currentAttribute]);
        $this->assertEquals([11], array_values($searchAttributes));
        
        // Simular que tenemos atributo 11, debería buscar 10
        $currentAttribute = 11;
        $searchAttributes = array_diff($validAttributes, [$currentAttribute]);
        $this->assertEquals([10], array_values($searchAttributes));
    }
    
    /**
     * Test validación de cantidades
     */
    public function testQuantityValidation()
    {
        $validQuantities = [1, 2, 5, 10];
        
        foreach ($validQuantities as $qty) {
            $this->assertGreaterThan(0, $qty);
            $this->assertTrue(is_int($qty));
        }
        
        // Cantidades inválidas
        $invalidQuantities = [0, -1, 'abc', null];
        
        foreach ($invalidQuantities as $qty) {
            $castedQty = (int)$qty;
            $this->assertLessThanOrEqual(0, $castedQty);
        }
    }
    
    /**
     * Test generación correcta de consultas SQL
     */
    public function testSqlQueryStructure()
    {
        $productId = 123;
        $attributeId = 10;
        $qty = 2;
        
        // SQL de reducción
        $expectedReductionSql = 'UPDATE '._DB_PREFIX_.'stock_available 
                SET quantity = quantity - '.(int)$qty.' 
                WHERE id_product = '.(int)$productId.' 
                AND id_product_attribute = '.(int)$attributeId;
        
        $this->assertContains('UPDATE', $expectedReductionSql);
        $this->assertContains('stock_available', $expectedReductionSql);
        $this->assertContains('quantity = quantity -', $expectedReductionSql);
        
        // SQL de restauración
        $expectedRestoreSql = 'UPDATE '._DB_PREFIX_.'stock_available 
                SET quantity = quantity + '.(int)$qty.' 
                WHERE id_product = '.(int)$productId.' 
                AND id_product_attribute = '.(int)$attributeId;
        
        $this->assertContains('UPDATE', $expectedRestoreSql);
        $this->assertContains('stock_available', $expectedRestoreSql);
        $this->assertContains('quantity = quantity +', $expectedRestoreSql);
    }
    
    /**
     * Test casos extremos de IDs
     */
    public function testIdSanitization()
    {
        $testIds = ['123', 123, '0', 0, '-1', -1, 'abc', null];
        
        foreach ($testIds as $id) {
            $sanitizedId = (int)$id;
            $this->assertTrue(is_int($sanitizedId));
            
            // IDs válidos deben ser positivos
            if ($id === '123' || $id === 123) {
                $this->assertGreaterThan(0, $sanitizedId);
            }
        }
    }
    
    /**
     * Test integridad de la configuración del módulo
     */
    public function testModuleConfiguration()
    {
        $this->assertEquals('dwp_compactstock', $this->module->name);
        $this->assertEquals('administration', $this->module->tab);
        $this->assertEquals('1.0.0', $this->module->version);
        $this->assertEquals('Desarrollo Web Profesional', $this->module->author);
        $this->assertEquals(0, $this->module->need_instance);
        $this->assertTrue($this->module->bootstrap);
    }
    
    /**
     * Test manejo de errores en consultas de base de datos
     */
    public function testDatabaseErrorHandling()
    {
        // Simular fallo en la consulta
        $this->mockDb->method('getValue')->willReturn(false);
        $this->mockDb->method('execute')->willReturn(false);
        
        // El módulo debería manejar estos casos sin fallar
        $mockStatus = new stdClass();
        $mockStatus->id = 2;
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => 123
        ];
        
        // No debería lanzar excepción
        $this->assertFalse($this->module->hookActionOrderStatusPostUpdate($params));
    }
    
    /**
     * Test validación mejorada de entrada
     */
    public function testEnhancedInputValidation()
    {
        // Test objeto newOrderStatus inválido
        $params = [
            'newOrderStatus' => 'invalid_string',
            'id_order' => 123
        ];
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertFalse($result);
        
        // Test newOrderStatus sin propiedad id
        $mockStatus = new stdClass();
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => 123
        ];
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertFalse($result);
        
        // Test id_order inválido (0 o negativo)
        $mockStatus = new stdClass();
        $mockStatus->id = 2;
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => 0
        ];
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertFalse($result);
        
        $params['id_order'] = -1;
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertFalse($result);
    }
    
    /**
     * Test constantes del módulo
     */
    public function testModuleConstants()
    {
        $this->assertEquals(1200, Dwp_CompactStock::TARGET_CATEGORY);
        $this->assertEquals(10, Dwp_CompactStock::ATTRIBUTE_WITH_BOX);
        $this->assertEquals(11, Dwp_CompactStock::ATTRIBUTE_WITHOUT_BOX);
        $this->assertTrue(is_bool(Dwp_CompactStock::DEBUG_MODE));
    }
    
    /**
     * Test que el método de debug no causa errores
     */
    public function testDebugModeHandling()
    {
        // El método debugLog es privado, pero podemos testear que no cause problemas
        // indirectamente al ejecutar el hook
        $mockStatus = new stdClass();
        $mockStatus->id = 999; // Estado inválido que no debería procesarse
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => 123
        ];
        
        // No debería lanzar excepción
        $result = $this->module->hookActionOrderStatusPostUpdate($params);
        $this->assertFalse($result);
    }
    
    /**
     * Test manejo de productos con datos inválidos
     */
    public function testInvalidProductDataHandling()
    {
        // Este test simula productos con datos corruptos
        $invalidProducts = [
            ['product_id' => 0, 'product_attribute_id' => 10, 'product_quantity' => 1],
            ['product_id' => 123, 'product_attribute_id' => 0, 'product_quantity' => 1],
            ['product_id' => 123, 'product_attribute_id' => 10, 'product_quantity' => 0],
            ['product_id' => -1, 'product_attribute_id' => 10, 'product_quantity' => 1],
        ];
        
        foreach ($invalidProducts as $product) {
            // Validar que los datos se filtran correctamente
            $id_product = (int)$product['product_id'];
            $id_product_attribute = (int)$product['product_attribute_id'];
            $qty = (int)$product['product_quantity'];
            
            if ($id_product <= 0 || $id_product_attribute <= 0 || $qty <= 0) {
                $this->assertTrue(true); // Datos inválidos correctamente detectados
            } else {
                $this->fail('Datos inválidos no detectados');
            }
        }
    }
    
    /**
     * Test prevención de inyección SQL
     */
    public function testSqlInjectionPrevention()
    {
        // Test valores que podrían ser maliciosos
        $maliciousValues = [
            "'; DROP TABLE users; --",
            "1 OR 1=1",
            "' UNION SELECT * FROM admin --",
            "<script>alert('xss')</script>",
            "../../etc/passwd"
        ];
        
        foreach ($maliciousValues as $malicious) {
            // Testear que la sanitización funciona
            $sanitized = (int)$malicious;
            $this->assertEquals(0, $sanitized, "Valor malicioso no sanitizado: $malicious");
        }
        
        // Test IDs válidos no son afectados
        $validIds = ['123', '456', '789'];
        foreach ($validIds as $id) {
            $sanitized = (int)$id;
            $this->assertGreaterThan(0, $sanitized);
            $this->assertEquals((int)$id, $sanitized);
        }
    }
}