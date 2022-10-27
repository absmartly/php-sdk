# A/B Smartly PHP SDK [![Version](https://poser.pugx.org/absmartly/php-sdk/version)](https://packagist.org/packages/absmartly/php-sdk)

## Compatibility
[![PHP Version Require](http://poser.pugx.org/absmartly/php-sdk/require/php)](https://packagist.org/packages/absmartly/php-sdk)

A/B Smartly PHP SDK is compatible with PHP 7.4, PHP 8.0, and later. It also requires the Curl extension, which is commonly
available in most PHP setups.

Although the SDK works on PHP 7.4, it is highly recommended to use PHP 8.1 or later, as PHP provides faster native 
implementations for MurMurHash, supports named parameters, and a few more features of which this SDK takes advantage.

HTTP requests made to A/B Smartly servers are synchronous, which means the application will only continue once the
HTTP request is completed. It is adviced that the results are cached, depending on the context.

## Installation

A/B Smartly can be installed via [`composer`](https://getcomposer.org):

```bash
composer require absmartly/php-sdk
```

## Getting Started

### Initialize the SDK instance.

You can create an SDK instance using the API key, application name, environment, and the endpoint URL obtained from A/B Smartly.

```php
$sdk = new \Absmartly\SDK\SDK::createWithDefaults(
    apiKey: $apiKey,
    application: $application,
    endpoint: $endpoint,
    environment: $environment
);
```

Note that the above example uses named parameters introduced in PHP 8.0. Although it is strongly recommended to use
the latest PHP version, PHP 7.4 is supported as well. On PHP 7.4, parameters are only passed in their order, as named
parameters are not supported.

Example:
```php
$sdk = new \Absmartly\SDK\SDK::createWithDefaults(
    $apiKey,
    $application,
    $endpoint,
    $environment
);
```

The above is a short-cut that creates an SDK instance quickly using default values. If you would like to granuarly
select the individual components (such as the HTTP Client), it can be done as following:


```php
$clientConfig = new \Absmartly\SDK\Client\ClientConfig(
    $apiKey,
    $application,
    $endpoint,
    $environment
);

$client = new \Absmartly\SDK\Client\Client($clientConfig, new \Absmartly\SDK\Http\HTTPClient());
$sdkConfig = new \Absmartly\SDK\Config($client);
$sdk = new \Absmartly\SDK\SDK($sdkConfig);
```


```php
$contextConfig = new \Absmartly\SDK\Context\ContextConfig();
$context = $sdk->createContext($params, $options, $requestOptions);
```

### Create a new Context

```php
$contextConfig = new \Absmartly\SDK\Context\ContextConfig();
$contextConfig->setUnit('session_id', '5ebf06d8cb5d8137290c4abb64155584fbdb64d8'); // a unique id identifying the user

$context = $sdk->createContext($contextConfig);
```




### Full Example

```php
$sdk = new \Absmartly\SDK\SDK::createWithDefaults(
    apiKey: $apiKey,
    application: $application,
    endpoint: $endpoint,
    environment: $environment
);


$contextConfig = new \Absmartly\SDK\Context\ContextConfig();
$contextConfig->setUnit('session_id', '5ebf06d8cb5d8137290c4abb64155584fbdb64d8');
$contextConfig->setUnit('user_id', '123456');

$context = $sdk->createContext($contextConfig);

$treatment = $context->getTreatment('exp_test_ab');
echo $treatment;

$properties = [
    'value' => 125,
    'fee' => 42,
];

$context->track("payments", $properties);

$context->close();
$sdk->close();
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
