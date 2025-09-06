# DWP CompactStock Module

A PrestaShop 1.6 module that automatically synchronizes stock between compact disc product combinations (with box / without box) during order status changes.

## Overview

This module ensures that when a customer purchases a compact disc in any combination (with box or without box), the stock of both combinations remains synchronized. This is particularly useful for physical products where the same item can be sold in different packaging options.

## Features

- ✅ **Automatic Stock Synchronization**: Maintains identical stock levels between "with box" and "without box" combinations
- ✅ **Smart Order Status Detection**: Only processes relevant order status changes (payment accepted, preparation, shipped, cancelled, refunded, payment error)
- ✅ **Duplicate Prevention**: Prevents multiple stock reductions for the same order
- ✅ **Transaction Safety**: Uses database transactions for atomic operations with rollback support
- ✅ **Security Hardened**: SQL injection protection and comprehensive input validation
- ✅ **Performance Optimized**: Batch category filtering to prevent N+1 query problems
- ✅ **Debug Support**: Optional logging system for troubleshooting (disabled by default for production)

## How It Works

### Stock Reduction (Order Confirmed)
When an order status changes to payment accepted (2), preparation (3), or shipped (4):
1. PrestaShop automatically reduces stock for the purchased combination
2. The module reduces stock for the non-purchased combination by the same amount
3. Result: Both combinations maintain identical stock levels

### Stock Restoration (Order Cancelled)
When an order status changes to cancelled (6), refunded (7), or payment error (8):
1. PrestaShop automatically restores stock for the originally purchased combination  
2. The module restores stock for the other combination by the same amount
3. Result: Both combinations return to synchronized stock levels

### Example Flow
```
Initial Stock: With Box: 100, Without Box: 100
Customer buys 2 "With Box" → Payment Accepted
Final Stock: With Box: 98, Without Box: 98 ✅

Customer cancels order → Cancelled Status  
Final Stock: With Box: 100, Without Box: 100 ✅
```

## Requirements

- **PrestaShop**: 1.6.x
- **PHP**: 5.6+ or 7.x
- **Database**: MySQL 5.5+
- **Product Setup**: Products must be in category 1200 with attribute combinations 10 (with box) and 11 (without box)

## Installation

1. Download or clone this repository
2. Upload the `dwp_compactstock` folder to your PrestaShop `modules/` directory
3. Go to PrestaShop Admin → Modules → Modules & Services
4. Find "DWP CompactStock" and click Install
5. The module will automatically register the required hook (`actionOrderStatusPostUpdate`)

## Configuration

The module works out of the box with these default settings:

```php
const TARGET_CATEGORY = 1200;        // Compact disc category
const ATTRIBUTE_WITH_BOX = 10;       // "With box" attribute ID
const ATTRIBUTE_WITHOUT_BOX = 11;    // "Without box" attribute ID
const DEBUG_MODE = false;            // Set to true for debugging
```

### Debug Mode

To enable debug logging for troubleshooting:

1. Change `DEBUG_MODE` to `true` in line 9 of `dwp_compactstock.php`
2. Uncomment the logging line in the `debugLog()` method (line 56)
3. Debug logs will be written to `modules/dwp_compactstock/debug_log.php`

**⚠️ Important**: Always disable debug mode in production environments to avoid performance impact.

## Order Status Mapping

| Status ID | Status Name | Action |
|-----------|-------------|---------|
| 2 | Payment Accepted | Reduce Stock |
| 3 | Preparation in Progress | Reduce Stock* |
| 4 | Shipped | Reduce Stock* |
| 6 | Cancelled | Restore Stock |
| 7 | Refunded | Restore Stock |
| 8 | Payment Error | Restore Stock |

*Only if stock wasn't already reduced in a previous status change

## Testing

The module includes a comprehensive test suite with 3 types of tests:

### Unit Tests
```bash
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar Dwp_CompactStockTest.php"
```

### Functional Tests  
```bash
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar FunctionalStockTest.php"
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar OrderStatusChangeTest.php"
```

### Integration Tests
```bash
docker exec php_fpm_server bash -c "cd /var/www/html/modules/dwp_compactstock/tests && php phpunit.phar IntegrationTest.php"
```

## Security Features

- **SQL Injection Protection**: All database queries use proper escaping and casting
- **Input Validation**: Comprehensive parameter validation and sanitization  
- **Transaction Safety**: Database transactions with automatic rollback on errors
- **Error Handling**: Graceful error handling without exposing sensitive information

## Performance Optimizations

- **Batch Processing**: Category filtering done in bulk to avoid N+1 queries
- **Early Returns**: Quick exit for non-applicable orders and products
- **Efficient Queries**: Optimized SQL with proper LIMIT clauses and indexing considerations

## Troubleshooting

### Common Issues

**Stock not synchronizing:**
1. Verify products are in category 1200
2. Check that combinations have attributes 10 and 11
3. Enable debug mode to see detailed logs

**Module not triggering:**
1. Confirm the hook `actionOrderStatusPostUpdate` is registered
2. Check that order status changes are within the configured states (2,3,4,6,7,8)
3. Verify no duplicate reductions are being prevented

**Performance issues:**
1. Ensure debug mode is disabled in production
2. Check database indexing on frequently queried tables
3. Monitor transaction rollback frequency

### Debug Information

With debug mode enabled, the module logs:
- Hook trigger events
- Order status validation
- Product category filtering  
- Stock reduction/restoration operations
- Database transaction results
- Error conditions and warnings

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Run the test suite to ensure compatibility
4. Commit your changes (`git commit -m 'Add amazing feature'`)
5. Push to the branch (`git push origin feature/amazing-feature`)
6. Open a Pull Request

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## Support

For issues, feature requests, or questions:

1. Check the [Issues](https://github.com/ricchiralt/dwp_compactstock/issues) page
2. Create a new issue with detailed information
3. Include debug logs if applicable (with sensitive data removed)

## Changelog

### v1.0.0
- Initial release
- Basic stock synchronization functionality
- Order status change detection
- Duplicate prevention system
- Security hardening and input validation
- Performance optimizations
- Comprehensive test suite

---

**Made with ❤️ by Desarrollo Web Profesional**
