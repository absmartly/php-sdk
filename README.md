# A/B Smartly PHP SDK [![Version](https://poser.pugx.org/absmartly/php-sdk/version)](https://packagist.org/packages/absmartly/php-sdk)

## Compatibility
[![PHP Version Require](http://poser.pugx.org/absmartly/php-sdk/require/php)](https://packagist.org/packages/absmartly/php-sdk)

## Installation

```bash
composer require absmartly/php-sdk
```

## Getting Started

```php
$clientConfig = new \Absmartly\SDK\Client\ClientConfig(
    $apiKey,
    $application,
    $endpoint,
    $environment
);

$client = new \Absmartly\SDK\\Absmartly\SDK\DefaultClient();
$sdk = new \Absmartly\SDK\SDK();
```


```php
$sdk = new \Absmartly\SDK\SDK();


$contextConfig = new \Absmartly\SDK\Context\ContextConfig();
$context = $sdk->createContext($params, $options, $requestOptions);
```

## Contributing, Security, and License

## About A/B Smartly
**A/B Smartly** is the leading provider of state-of-the-art, on-premises, full-stack experimentation platforms for engineering and product teams that want to confidently deploy features as fast as they can develop them.
A/B Smartly's real-time analytics helps engineering and product teams ensure that new features will improve the customer experience without breaking or degrading performance and/or business metrics.

### Have a look at our growing list of clients and SDKs:
- [Java SDK](https://www.github.com/absmartly/java-sdk)
- [JavaScript SDK](https://www.github.com/absmartly/javascript-sdk)
- [PHP SDK](https://www.github.com/absmartly/php-sdk)
- [Swift SDK](https://www.github.com/absmartly/swift-sdk)
- [Vue2 SDK](https://www.github.com/absmartly/vue2-sdk)
