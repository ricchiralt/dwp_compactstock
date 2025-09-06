# Tests para el módulo dwp_compactstock

Este directorio contiene una suite completa de tests para el módulo dwp_compactstock de PrestaShop.

## Estructura de archivos

- `Dwp_CompactStockTest.php` - Tests unitarios principales (validaciones, configuración)
- `FunctionalStockTest.php` - Tests funcionales de comportamiento de stock
- `OrderStatusChangeTest.php` - Tests específicos de cambios de estado de pedido
- `IntegrationTest.php` - Tests de integración con base de datos Docker
- `bootstrap.php` - Configuración inicial para los tests
- `phpunit.xml` - Configuración de PHPUnit
- `README.md` - Esta documentación

## Requisitos

- PHPUnit 4.8+ (compatible con PrestaShop 1.6)
- Docker con contenedores activos (para tests de integración)
- PHP 5.6+ o 7.x

## Instalación de PHPUnit

```bash
# Opción 1: Composer (recomendado)
composer require --dev phpunit/phpunit:^4.8

# Opción 2: Descarga directa
wget https://phar.phpunit.de/phpunit-4.8.phar
chmod +x phpunit-4.8.phar
sudo mv phpunit-4.8.phar /usr/local/bin/phpunit
```

## Ejecución de tests

### Tests unitarios solamente
```bash
cd /path/to/modules/dwp_compactstock/tests
phpunit Dwp_CompactStockTest.php
```

### Todos los tests (unitarios + integración)
```bash
cd /path/to/modules/dwp_compactstock/tests
phpunit
```

### Con coverage (si tienes xdebug)
```bash
phpunit --coverage-html coverage/
```

## Descripción de tests

### Tests Unitarios (Dwp_CompactStockTest.php)

1. **testInstallRegistersHook** - Verifica que la instalación registra el hook correctamente
2. **testHookReturnsEarlyWithMissingParams** - Valida manejo de parámetros faltantes
3. **testStockReductionStates** - Verifica identificación de estados que reducen stock
4. **testStockRestorationStates** - Verifica identificación de estados que restauran stock
5. **testIgnoreInvalidStates** - Confirma que estados inválidos se ignoran
6. **testPreventDuplicateStockReduction** - Previene reducciones duplicadas
7. **testCategoryFiltering** - Valida filtrado por categoría 1200
8. **testAttributeMatching** - Verifica búsqueda de combinaciones relacionadas
9. **testQuantityValidation** - Valida manejo de cantidades
10. **testSqlQueryStructure** - Verifica estructura de consultas SQL
11. **testIdSanitization** - Prueba sanitización de IDs
12. **testModuleConfiguration** - Valida configuración del módulo
13. **testDatabaseErrorHandling** - Manejo de errores de base de datos

### Tests Funcionales (FunctionalStockTest.php)

1. **testStockReductionOnPaymentAccepted** - Verifica reducción de stock en pago aceptado
2. **testStockRestorationOnOrderCancelled** - Verifica restauración en cancelación
3. **testPreventNegativeStock** - Manejo de stock insuficiente
4. **testMultipleProductsInOrder** - Pedidos con múltiples productos
5. **testPreventDoubleReduction** - Prevención de reducciones duplicadas
6. **testStockSynchronization** - Sincronización entre combinaciones
7. **testNonTargetCategoryProducts** - Productos fuera de categoría objetivo
8. **testTransactionIntegrity** - Integridad de transacciones (rollback)

### Tests de Cambio de Estado (OrderStatusChangeTest.php)

1. **testNewOrderToPaymentAccepted** - Nuevo pedido → Pago aceptado (reducir)
2. **testPaymentAcceptedToPreparation** - Pago → Preparación (no reducir)
3. **testShippedToCancelled** - Enviado → Cancelado (restaurar)
4. **testNonStockAffectingStates** - Estados que no afectan stock
5. **testMultipleStatusChangesSequence** - Secuencia completa de cambios
6. **testPaymentErrorCycle** - Ciclo error-pago-error
7. **testProductWithoutRelatedCombination** - Sin combinación relacionada
8. **testOnlyProcessTargetAttributes** - Solo procesa atributos 10/11

### Tests de Integración (IntegrationTest.php)

1. **testDatabaseConnection** - Verifica conexión a la BD Docker
2. **testRequiredTables** - Confirma existencia de tablas necesarias
3. **testDataOperations** - Prueba inserción y consulta de datos
4. **testStockReductionSimulation** - Simula reducción real de stock
5. **testOrderHistoryQuery** - Prueba consultas de historial de pedidos
6. **testProductAttributeQuery** - Valida consultas de atributos de productos
7. **testQueryPerformance** - Mide performance de consultas

## Cobertura de tests

Los tests cubren:

- ✅ Instalación y desinstalación del módulo
- ✅ Validación de parámetros de entrada
- ✅ Lógica de estados de pedidos
- ✅ Filtrado por categoría de productos
- ✅ Búsqueda de combinaciones relacionadas
- ✅ Sanitización de datos de entrada
- ✅ Generación de consultas SQL
- ✅ Manejo de errores de base de datos
- ✅ Prevención de operaciones duplicadas
- ✅ Performance de consultas

## Casos edge detectados y testados

1. **Parámetros faltantes o inválidos**
2. **Estados de pedido no válidos**
3. **Productos fuera de la categoría objetivo**
4. **Cantidades inválidas (negativas, no numéricas)**
5. **IDs malformados**
6. **Fallos de base de datos**
7. **Intentos de reducción duplicada**
8. **Combinaciones de productos inexistentes**

## Comandos útiles

```bash
# Tests unitarios básicos (más rápidos)
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar --filter 'testInstall|testUninstall|testModuleConfiguration|testSqlQueryStructure|testQuantityValidation' Dwp_CompactStockTest.php"

# Tests funcionales de stock
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar FunctionalStockTest.php"

# Tests de cambio de estado
docker exec php_fmp_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar OrderStatusChangeTest.php"

# Tests de integración (requieren BD)
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar IntegrationTest.php"

# Ejecutar solo un test específico
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar --filter testStockReductionOnPaymentAccepted FunctionalStockTest.php"

# Con más verbosidad
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar --verbose FunctionalStockTest.php"
```

## Notas importantes

- Los tests de integración requieren que Docker esté corriendo
- La base de datos debe estar configurada según `config/settings.inc.php`
- Los tests unitarios no requieren base de datos (usan mocks)
- Se recomienda ejecutar tests en entorno de desarrollo, no producción

## Contribuir

Al agregar nuevas funcionalidades al módulo:

1. Agregar tests correspondientes
2. Ejecutar toda la suite de tests
3. Mantener cobertura > 90%
4. Documentar casos edge nuevos