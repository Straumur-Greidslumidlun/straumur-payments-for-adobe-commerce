# Straumur Payments for Adobe Commerce

A comprehensive payment module that integrates Straumur payment services with Adobe Commerce (Magento 2) to provide secure and reliable payment processing for Icelandic merchants.

## Features

- **Secure Payment Processing**: PCI-compliant payment handling with Straumur's secure payment gateway
- **Multiple Payment Methods**: Support for credit cards, debit cards, and other Icelandic payment methods
- **Real-time Transaction Processing**: Instant payment authorization and capture
- **Refund Management**: Easy refund processing through the admin panel
- **Multi-currency Support**: Handle transactions in ISK and other supported currencies
- **Order Management Integration**: Seamless integration with Adobe Commerce order workflow
- **Comprehensive Logging**: Detailed transaction logs for debugging and audit purposes

## Requirements

- Adobe Commerce 2.4.x or higher
- PHP 8.1 or higher
- SSL certificate (required for production)
- Straumur merchant account and API credentials

## Installation

### Via Composer (Recommended)

```bash
composer require straumur/payments-for-adobe-commerce
bin/magento module:enable Straumur_Payments
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento setup:static-content:deploy
bin/magento cache:flush
```

### Manual Installation

1. Download the latest release from this repository
2. Extract the files to `app/code/Straumur/Payments/`
3. Enable the module:
   ```bash
   bin/magento module:enable Straumur_Payments
   bin/magento setup:upgrade
   bin/magento setup:di:compile
   bin/magento setup:static-content:deploy
   bin/magento cache:flush
   ```

## Configuration

1. Navigate to **Stores** > **Configuration** > **Sales** > **Payment Methods**
2. Find **Straumur Payments** section
3. Configure the following settings:
   - **Enabled**: Set to "Yes"
   - **Title**: Display name for the payment method
   - **Merchant ID**: Your Straumur merchant identifier
   - **API Key**: Your Straumur API key
   - **API Secret**: Your Straumur API secret
   - **Environment**: Select "Sandbox" for testing or "Production" for live transactions
   - **Payment Action**: Choose "Authorize" or "Authorize and Capture"

### Test Credentials

For testing purposes, you can use the following sandbox credentials:
- **Environment**: Sandbox
- **Merchant ID**: `test_merchant_123`
- **API Key**: `test_api_key`
- **API Secret**: `test_api_secret`

## Usage

### Frontend

1. Customers can select Straumur Payments during checkout
2. They will be redirected to Straumur's secure payment page
3. After successful payment, customers are redirected back to the success page
4. Failed payments redirect to the failure page with error details

### Admin Panel

1. View transaction details in **Sales** > **Orders**
2. Process refunds directly from the order view
3. Monitor payment logs in **System** > **Logs** > **Straumur Payments**

## API Documentation

### Payment Flow

1. **Order Creation**: When a customer places an order, the module creates a payment request
2. **Payment Authorization**: Customer is redirected to Straumur for payment authorization
3. **Callback Processing**: Straumur sends payment status via webhook
4. **Order Completion**: Order status is updated based on payment result

### Webhook Configuration

Configure the following webhook URL in your Straumur merchant dashboard:
```
https://yourstore.com/straumur/webhook/callback
```

## Development

### Testing

```bash
# Run unit tests
vendor/bin/phpunit Test/Unit/

# Run integration tests
vendor/bin/phpunit Test/Integration/
```

### Code Standards

This module follows Adobe Commerce coding standards:
- PSR-12 code style
- Adobe Commerce best practices
- Comprehensive documentation

## Troubleshooting

### Common Issues

**Payment fails with "Invalid credentials" error**
- Verify your API credentials in the configuration
- Ensure you're using the correct environment (sandbox/production)

**Orders stuck in "Pending Payment" status**
- Check webhook configuration
- Verify webhook URL is accessible
- Review payment logs for error details

**Refunds not processing**
- Ensure the original transaction is captured
- Check API credentials have refund permissions
- Verify the refund amount doesn't exceed the original transaction

### Debug Mode

Enable debug mode for detailed logging:
1. Go to **Stores** > **Configuration** > **Sales** > **Payment Methods** > **Straumur Payments**
2. Set **Debug Mode** to "Yes"
3. Check logs at `var/log/straumur_payments.log`

## Security

- All sensitive data is encrypted in the database
- API communications use TLS 1.2 or higher
- PCI DSS compliance maintained through secure payment flow
- No card data is stored on the merchant server

## Support

For technical support and questions:
- **Documentation**: [Straumur Developer Portal](https://developers.straumur.is)
- **Email**: support@straumur.is
- **Phone**: +354 440 4000

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

### Development Setup

```bash
git clone https://github.com/Straumur-Greidslumidlun/straumur-payments-for-adobe-commerce.git
cd straumur-payments-for-adobe-commerce
composer install
```

## Changelog

### Version 1.0.0
- Initial release
- Basic payment processing functionality
- Admin configuration interface
- Webhook support for payment status updates

## License

This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details.

## About Straumur

Straumur is Iceland's leading payment service provider, offering secure and reliable payment solutions for businesses of all sizes. We specialize in local payment methods and provide comprehensive support for Icelandic merchants.

---

**Straumur - Greiðslumidlun** | [Website](https://www.straumur.is) | [Developer Portal](https://developers.straumur.is)
