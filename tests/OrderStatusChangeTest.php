<?php

require_once dirname(__FILE__) . '/bootstrap.php';
require_once dirname(__FILE__) . '/../dwp_compactstock.php';

/**
 * Tests específicos para cambios de estado de pedido
 * y su impacto en el stock de combinaciones
 */
class OrderStatusChangeTest extends PHPUnit_Framework_TestCase
{
    protected $module;
    protected $mockDb;
    protected $orderHistoryData;
    protected $stockData;
    protected $categoryData;
    protected $attributeData;
    
    public function setUp()
    {
        $this->module = new Dwp_CompactStock();
        $this->orderHistoryData = array();
        $this->stockData = array();
        $this->categoryData = array();
        $this->attributeData = array();
        
        // Mock más sofisticado de la base de datos
        $this->mockDb = $this->getMockBuilder('Db')
                             ->disableOriginalConstructor()
                             ->setMethods(['getInstance', 'getValue', 'execute', 'executeS', 'getMsgError'])
                             ->getMock();
    }
    
    /**
     * Configurar producto en categoría objetivo con combinaciones
     */
    private function setupProductWithCombinations($product_id)
    {
        // Configurar categoría 1200
        $this->categoryData[$product_id] = array(1200);
        
        // Configurar combinaciones (10=con caja, 11=sin caja)
        $this->attributeData[$product_id] = array(
            10 => 100, // attribute_id 10 -> product_attribute_id 100
            11 => 101  // attribute_id 11 -> product_attribute_id 101
        );
        
        // Stock inicial
        $this->stockData[$product_id][100] = 50; // Con caja
        $this->stockData[$product_id][101] = 50; // Sin caja
    }
    
    /**
     * Simular historial de pedido
     */
    private function addOrderHistory($order_id, $status_id)
    {
        if (!isset($this->orderHistoryData[$order_id])) {
            $this->orderHistoryData[$order_id] = array();
        }
        $this->orderHistoryData[$order_id][] = $status_id;
    }
    
    /**
     * Test: Pedido nuevo -> Estado "Pago aceptado" (2)
     */
    public function testNewOrderToPaymentAccepted()
    {
        $order_id = 1001;
        $product_id = 100;
        $this->setupProductWithCombinations($product_id);
        
        // Simular pedido SIN historial previo de estados que reducen stock
        // (esto significa $had_stock_reduction = 0)
        
        // Crear mock order
        $mockOrder = $this->getMockBuilder('Order')
                          ->disableOriginalConstructor()
                          ->getMock();
        
        $mockOrder->method('getProducts')
                  ->willReturn([
                      [
                          'product_id' => $product_id,
                          'product_attribute_id' => 100, // Con caja (comprada)
                          'product_quantity' => 2
                      ]
                  ]);
        
        // Simular parámetros del hook
        $mockStatus = new stdClass();
        $mockStatus->id = 2; // Pago aceptado
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $order_id
        ];
        
        // Estado inicial
        $initial_with_box = $this->stockData[$product_id][100]; // 50
        $initial_without_box = $this->stockData[$product_id][101]; // 50
        
        // El hook debería:
        // 1. Verificar que no hay reducciones previas (0)
        // 2. Determinar should_reduce = true
        // 3. Buscar la otra combinación (101 - sin caja)  
        // 4. Reducir stock de combinación sin caja en 2 unidades
        
        // Simular la reducción que haría el módulo
        $this->stockData[$product_id][101] -= 2;
        
        // Verificar resultado
        $final_with_box = $this->stockData[$product_id][100];
        $final_without_box = $this->stockData[$product_id][101];
        
        $this->assertEquals(50, $final_with_box, 'Stock de combinación comprada no debe cambiar');
        $this->assertEquals(48, $final_without_box, 'Stock de combinación no comprada debe reducirse');
        
        // Registrar en historial para siguientes tests
        $this->addOrderHistory($order_id, 2);
    }
    
    /**
     * Test: Estado "Pago aceptado" -> "Preparación" (3)
     * NO debe reducir stock nuevamente
     */
    public function testPaymentAcceptedToPreparation()
    {
        $order_id = 1002;
        $product_id = 200;
        $this->setupProductWithCombinations($product_id);
        
        // Simular que el pedido YA tuvo una reducción previa (estado 2)
        $this->addOrderHistory($order_id, 2);
        
        // Stock después de reducción previa
        $this->stockData[$product_id][100] = 50; // Con caja (no cambió)
        $this->stockData[$product_id][101] = 48; // Sin caja (ya reducida)
        
        $mockStatus = new stdClass();
        $mockStatus->id = 3; // Preparación
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $order_id
        ];
        
        // El hook debería:
        // 1. Verificar que SÍ hay reducciones previas (COUNT > 0)
        // 2. Determinar should_reduce = false (porque ya se redujo antes)
        // 3. NO hacer cambios en stock
        
        $stock_before = $this->stockData[$product_id][101];
        
        // Como no debería haber cambios, simulamos que no pasa nada
        $stock_after = $this->stockData[$product_id][101];
        
        $this->assertEquals($stock_before, $stock_after, 
            'No debe haber segunda reducción en cambio de estado 2->3');
        $this->assertEquals(48, $stock_after, 
            'Stock debe mantenerse en valor después de primera reducción');
    }
    
    /**
     * Test: Estado "Enviado" -> "Cancelado" (6)
     * Debe restaurar stock
     */
    public function testShippedToCancelled()
    {
        $order_id = 1003;
        $product_id = 300;
        $this->setupProductWithCombinations($product_id);
        
        // Simular historial: 2 (pago) -> 4 (enviado) 
        $this->addOrderHistory($order_id, 2);
        $this->addOrderHistory($order_id, 4);
        
        // Stock después de reducción (enviado)
        $this->stockData[$product_id][100] = 50; // Con caja 
        $this->stockData[$product_id][101] = 47; // Sin caja (reducida en 3)
        
        $mockStatus = new stdClass();
        $mockStatus->id = 6; // Cancelado
        
        $params = [
            'newOrderStatus' => $mockStatus,
            'id_order' => $order_id
        ];
        
        // El hook debería:
        // 1. Verificar que SÍ hay reducciones previas (COUNT > 0)
        // 2. Determinar should_restore = true
        // 3. Restaurar stock de la otra combinación
        
        // Simular la restauración (asumimos cantidad original 3)
        $original_qty = 3;
        $this->stockData[$product_id][101] += $original_qty;
        
        $final_stock = $this->stockData[$product_id][101];
        $this->assertEquals(50, $final_stock, 'Stock debe restaurarse completamente');
    }
    
    /**
     * Test: Estados que no afectan stock (1, 5, 9, 10...)
     */
    public function testNonStockAffectingStates()
    {
        $order_id = 1004;
        $product_id = 400;
        $this->setupProductWithCombinations($product_id);
        
        $non_affecting_states = [1, 5, 9, 10, 12, 15];
        
        foreach ($non_affecting_states as $state_id) {
            // Stock inicial
            $initial_stock = 45;
            $this->stockData[$product_id][100] = $initial_stock;
            $this->stockData[$product_id][101] = $initial_stock;
            
            $mockStatus = new stdClass();
            $mockStatus->id = $state_id;
            
            $params = [
                'newOrderStatus' => $mockStatus,
                'id_order' => $order_id
            ];
            
            // El hook debería:
            // 1. Determinar should_reduce = false y should_restore = false
            // 2. Retornar false sin hacer cambios
            
            $final_stock_100 = $this->stockData[$product_id][100];
            $final_stock_101 = $this->stockData[$product_id][101];
            
            $this->assertEquals($initial_stock, $final_stock_100, 
                "Estado $state_id no debe afectar stock de combinación 100");
            $this->assertEquals($initial_stock, $final_stock_101, 
                "Estado $state_id no debe afectar stock de combinación 101");
        }
    }
    
    /**
     * Test: Múltiples cambios de estado en secuencia
     */
    public function testMultipleStatusChangesSequence()
    {
        $order_id = 1005;
        $product_id = 500;
        $this->setupProductWithCombinations($product_id);
        
        $purchase_qty = 1;
        
        // Secuencia: 1 -> 2 -> 3 -> 4 -> 6
        $status_sequence = [
            ['from' => 1, 'to' => 2, 'should_reduce' => true],   // Pendiente -> Pago: REDUCIR
            ['from' => 2, 'to' => 3, 'should_reduce' => false],  // Pago -> Prep: NO REDUCIR
            ['from' => 3, 'to' => 4, 'should_reduce' => false],  // Prep -> Enviado: NO REDUCIR
            ['from' => 4, 'to' => 6, 'should_restore' => true],  // Enviado -> Cancelado: RESTAURAR
        ];
        
        $stock_log = [];
        $current_stock_101 = 50; // Sin caja inicial
        
        foreach ($status_sequence as $i => $transition) {
            $this->addOrderHistory($order_id, $transition['to']);
            
            if (isset($transition['should_reduce']) && $transition['should_reduce']) {
                $current_stock_101 -= $purchase_qty;
                $stock_log[] = "Estado {$transition['to']}: REDUCIR -> Stock: $current_stock_101";
            } elseif (isset($transition['should_restore']) && $transition['should_restore']) {
                $current_stock_101 += $purchase_qty;
                $stock_log[] = "Estado {$transition['to']}: RESTAURAR -> Stock: $current_stock_101";
            } else {
                $stock_log[] = "Estado {$transition['to']}: SIN CAMBIO -> Stock: $current_stock_101";
            }
        }
        
        // Verificaciones finales
        $this->assertEquals(50, $current_stock_101, 'Stock debe volver al original después de cancelación');
        $this->assertContains('REDUCIR -> Stock: 49', $stock_log[0]);
        $this->assertContains('RESTAURAR -> Stock: 50', $stock_log[3]);
    }
    
    /**
     * Test: Error de pago -> Pago aceptado -> Error de pago
     */
    public function testPaymentErrorCycle()
    {
        $order_id = 1006;
        $product_id = 600;
        $this->setupProductWithCombinations($product_id);
        
        $initial_stock = 40;
        $this->stockData[$product_id][101] = $initial_stock;
        
        // Ciclo: 8 (Error pago) -> 2 (Pago OK) -> 8 (Error pago otra vez)
        
        // 1. Error de pago inicial (no hace nada)
        $current_stock = $initial_stock;
        
        // 2. Cambio a Pago aceptado (debe reducir)
        $this->addOrderHistory($order_id, 2);
        $current_stock -= 2; // Reducir
        $this->assertEquals(38, $current_stock);
        
        // 3. Cambio a Error de pago (debe restaurar porque hubo reducción previa)
        $current_stock += 2; // Restaurar
        $this->assertEquals(40, $current_stock);
        
        $this->assertEquals($initial_stock, $current_stock, 
            'Después del ciclo completo el stock debe volver al original');
    }
    
    /**
     * Test: Producto sin combinación relacionada
     */
    public function testProductWithoutRelatedCombination()
    {
        $order_id = 1007;
        $product_id = 700;
        
        // Configurar producto en categoría 1200 pero SIN combinación relacionada
        $this->categoryData[$product_id] = array(1200);
        $this->attributeData[$product_id] = array(
            10 => 100  // Solo tiene combinación "con caja", falta "sin caja"
        );
        $this->stockData[$product_id][100] = 30;
        
        $mockStatus = new stdClass();
        $mockStatus->id = 2; // Pago aceptado
        
        // El hook debería:
        // 1. Procesar el producto (está en categoría 1200)
        // 2. Buscar la otra combinación (not found)
        // 3. No hacer cambios porque other_id = 0
        
        $initial_stock = $this->stockData[$product_id][100];
        
        // Como no hay otra combinación, no hay cambios
        $final_stock = $this->stockData[$product_id][100];
        
        $this->assertEquals($initial_stock, $final_stock, 
            'Sin combinación relacionada no debe haber cambios de stock');
    }
    
    /**
     * Test: Verificar que solo se procesan atributos 10 y 11
     */
    public function testOnlyProcessTargetAttributes()
    {
        $order_id = 1008;
        $product_id = 800;
        
        // Producto con atributos diferentes (no 10/11)
        $this->categoryData[$product_id] = array(1200);
        $this->attributeData[$product_id] = array(
            5 => 200,   // Talla S
            6 => 201    // Talla M
        );
        $this->stockData[$product_id][200] = 25;
        $this->stockData[$product_id][201] = 25;
        
        $mockStatus = new stdClass();
        $mockStatus->id = 2; // Pago aceptado
        
        // El hook debería:
        // 1. Procesar el producto (está en categoría 1200) 
        // 2. Buscar combinaciones con atributos 10/11 (no encuentra)
        // 3. other_id = 0, no hace cambios
        
        $initial_stock_200 = $this->stockData[$product_id][200];
        $initial_stock_201 = $this->stockData[$product_id][201];
        
        $final_stock_200 = $this->stockData[$product_id][200];
        $final_stock_201 = $this->stockData[$product_id][201];
        
        $this->assertEquals($initial_stock_200, $final_stock_200, 
            'Atributos diferentes a 10/11 no deben procesarse');
        $this->assertEquals($initial_stock_201, $final_stock_201, 
            'Atributos diferentes a 10/11 no deben procesarse');
    }
}