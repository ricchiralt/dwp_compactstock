-- SQL para crear datos de prueba reales en la base de datos
-- Ejecutar SOLO en entorno de testing, NUNCA en producción

-- 1. Crear producto de prueba en categoría 1200
INSERT IGNORE INTO psan_product (id_product, reference, price, active, date_add, date_upd) 
VALUES (9999, 'TEST_COMPACT_DISC', 15.99, 1, NOW(), NOW());

-- 2. Asignar a categoría 1200 (compact discs)  
INSERT IGNORE INTO psan_category_product (id_category, id_product, position) 
VALUES (1200, 9999, 1);

-- 3. Crear combinaciones con atributos 10 (con caja) y 11 (sin caja)
INSERT IGNORE INTO psan_product_attribute (id_product_attribute, id_product, reference, price, default_on, minimal_quantity, available_date) 
VALUES 
(99990, 9999, 'TEST_CD_WITH_BOX', 0.00, 1, 1, '0000-00-00'),
(99991, 9999, 'TEST_CD_WITHOUT_BOX', 0.00, 0, 1, '0000-00-00');

-- 4. Asignar atributos a las combinaciones
INSERT IGNORE INTO psan_product_attribute_combination (id_attribute, id_product_attribute) 
VALUES 
(10, 99990),  -- Con caja
(11, 99991);  -- Sin caja

-- 5. Configurar stock inicial
INSERT IGNORE INTO psan_stock_available (id_stock_available, id_product, id_product_attribute, id_shop, id_shop_group, quantity, depends_on_stock, out_of_stock, location) 
VALUES 
(999990, 9999, 99990, 1, 0, 100, 0, 2, ''),  -- Con caja: 100 unidades
(999991, 9999, 99991, 1, 0, 100, 0, 2, '');  -- Sin caja: 100 unidades

-- 6. Crear pedido de prueba
INSERT IGNORE INTO psan_orders (id_order, reference, id_customer, id_cart, id_currency, id_lang, id_address_delivery, id_address_invoice, current_state, payment, total_paid, date_add, date_upd)
VALUES (99999, 'TEST_ORDER_001', 1, 1, 1, 1, 1, 1, 1, 'Test Payment', 31.98, NOW(), NOW());

-- 7. Agregar productos al pedido
INSERT IGNORE INTO psan_order_detail (id_order_detail, id_order, product_id, product_attribute_id, product_name, product_quantity, product_price, unit_price_tax_incl, unit_price_tax_excl)
VALUES (999999, 99999, 9999, 99990, 'Test Compact Disc - Con Caja', 2, 15.99, 15.99, 15.99);

-- 8. Crear historial inicial del pedido (pendiente)
INSERT IGNORE INTO psan_order_history (id_order_history, id_order, id_order_state, id_employee, date_add)
VALUES (9999999, 99999, 1, 0, NOW());

-- QUERIES PARA VERIFICAR ESTADO INICIAL:
-- SELECT * FROM psan_stock_available WHERE id_product = 9999;
-- SELECT * FROM psan_product_attribute WHERE id_product = 9999;  
-- SELECT * FROM psan_product_attribute_combination WHERE id_product_attribute IN (99990, 99991);
-- SELECT * FROM psan_order_history WHERE id_order = 99999;