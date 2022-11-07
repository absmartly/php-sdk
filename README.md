## Variables To Replace

- PHP
- 7.4
- ContextConfig
- [SET_REFRESH_INTERVAL_METHOD]
- [REFRESH_METHOD]
- [GET_TREATMENT_METHOD]
- [SET_UNIT_METHOD]
- [SET_UNITS_METHOD]
- [PEEK_TREATMENT_METHOD]
- [OVERRIDE_METHOD]
- [OVERRIDES_METHOD]
- [ATTRIBUTE_METHOD]
- [ATTRIBUTES_METHOD]
- [CUSTOM_ASSIGNMENT_METHOD]
- [CUSTOM_ASSIGNMENTS_METHOD]
- [PUBLISH_METHOD]
- [PUBLISH_ASYNC_METHOD]
- [FINALIZE_METHOD]
- [FINALIZE_ASYNC_METHOD]

**_DELETE EVERYTHING FROM THIS LINE UPWARDS_**

# A/B Smartly SDK

A/B Smartly PHP SDK

## Compatibility

The A/B Smartly PHP SDK is compatible with PHP versions 7.4 and later.  For the best performance and code readability, PHP 8.1 or later is recommended. This SDK is being constantly tested with the nightly builds of PHP, to ensure it is compatible with the latest PHP version.

## Getting Started

### Install the SDK

A/B Smartly PHP SDK can be installed with [`composer`](https://getcomposer.org):

```bash  
composer require absmartly/php-sdk
```  

## Import and Initialize the SDK

Once the SDK is installed, it can be initialized in your project.

You can create an SDK instance using the API key, application name, environment, and the endpoint URL obtained from A/B Smartly.

```php  
use \Absmartly\SDK\SDK;

$sdk = SDK::createWithDefaults(  
  apiKey: $apiKey,
  application: $application,
  endpoint: $endpoint,
  environment: $environment
);  
```  

Note that the above example uses named parameters introduced in PHP 8.0. Although it is strongly recommended to use the latest PHP version, PHP 7.4 is supported as well. On PHP 7.4, parameters are only passed in their order, as named parameters are not supported.

Example:
```php
use \Absmartly\SDK\SDK;

$sdk = SDK::createWithDefaults(  
  $apiKey, $application, $endpoint, $environment
);  
```  

The above is a short-cut that creates an SDK instance quickly using default values. If you would like granular  choice of individual components (such as a custom event logger), it can be done as following:

```php  
use Absmartly\SDK\Client\ClientConfig;  
use Absmartly\SDK\Client\Client;  
use Absmartly\SDK\Config;  
use Absmartly\SDK\SDK;  
use Absmartly\SDK\Context\ContextConfig;
use Absmartly\SDK\Context\ContextEventLoggerCallback;
  
$clientConfig = new ClientConfig('', '', '', '');  
$client = new Client($clientConfig);  
$config = new Config($client);  
  
$sdk = new SDK($config);  
  
$contextConfig = new ContextConfig();
$contextConfig->setEventLogger(new ContextEventLoggerCallback(  
    function (string $event, ?object $data) {  
        // Custom callback
    }
));

$context = $sdk->createContext($contextConfig);  
```  

**SDK Options**

|     Config     |                Type                 | Required?  |                    Default                     |                                                                                  Description                                                                                   |  
| :---------- | :----------------------------------- | :-------: | :-------------------------------------: | :---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |  
|   `endpoint`   |                 string                | ✅ |                  _undefined_                   |                                                    The URL to your API endpoint. Most commonly "your-company.absmartly.io"                                                       
|    `apiKey`    |              `string`               | ✅ |                  _undefined_                   |                                                              Your API key which can be found on the Web Console.                                                               |  
| `environment`  |  `"production"` or `"development"`  | ✅ |                  _undefined_                   |  The environment of the platform where the SDK is installed. Environments are created on the Web Console and should match the available environments in your infrastructure.     
| `application`  |              `string`               | ✅ |                  _undefined_                   | The name of the application where the SDK is installed. Applications are created on the Web Console and should match the applications where your experiments will be running.    
|   `retries`    |                `int`                | ❌ |                       5                        |                                                         The number of retries before the SDK stops trying to connect.                                                          | |   `timeout`    |                `int`                | ❌ |                     `3000`                     |                                                An amount of time, in milliseconds, before the SDK will stop trying to connect.                                                 |  
| `eventLogger`  | `\Absmartly\SDK\Context\ContextEventLogger`  | ❌ | `null`, See Using a Custom Event Logger below  |                                                                A callback function which runs after SDK events.                                                                  

### Using a Custom Event Logger

The A/B Smartly SDK can be instantiated with an event logger used for all  contexts. In addition, an event logger can be specified when creating a  particular context in the `ContextConfig`.

```php  
use Absmartly\SDK\Client\ClientConfig;
use Absmartly\SDK\Context\ContextEventLoggerCallback;

$contextConfig = new ContextConfig();
$contextConfig->setEventLogger(new ContextEventLoggerCallback(  
    function (string $event, ?object $data) {  
        // Custom callback
    }
)); 
```  

Alternately, it is possible to implement `\Absmartly\SDK\Context\ContextEventLogger` interface with `handleEvent()` method that receives the `Context` object itself, along with a `ContextEventLoggerEvent` object as shown below:

```php  
use \Absmartly\SDK\Context\ContextEventLoggerCallback;  
  
class CustomLogger implements ContextEventLogger {
    public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {  
        // Process the log event
        // e.g
        // myLogFunction($event->getEvent(), $event->getData());
    }
}
  
$contextConfig = new ContextConfig();  
$contextConfig->setEventLogger(CustomLogger());  
```  

The data parameter depends on the type of event. Currently, the SDK logs the following events:

| eventName    | when                                                       | data                                                  |  
|--------------|------------------------------------------------------------|-------------------------------------------------------|  
| `"Error"`    | `Context` receives an error                                |`Exception` object thrown                              |  
| `"Ready"`    | `Context` turns ready                                      |`ContextData` object used to initialize the context    |  
| `"Refresh"`  | `Context->refresh()` method succeeds                       |`ContextData` used to refresh the context              |  
| `"Publish"`  | `Context->publish()` method succeeds                       |`PublishEvent` data sent to the A/B Smartly event collector |  
| `"Exposure"` | `Context->getTreatment()` method succeeds on first exposure|`Exposure` data enqueued for publishing                |  
| `"Goal"`     | `Context->Track()` method succeeds                         |`GoalAchivement` goal data enqueued for publishing     |  
| `"Close"`    | `Context->lose()` method succeeds the first time           |`null`                                                 |  

## Create a New Context Request

**Synchronously**

```php
$contextConfig = new ContextConfig(); 
$contextConfig->setUnit('session_id', 'session_id5ebf06d8cb5d8137290c4abb64155584fbdb64d8'); // a unique id identifying the user

$context = $sdk->createContext($contextConfig);
```  

**With Prefetched Data**

```php
$contextConfig = new ContextConfig(); 
$contextConfig->setUnit('session_id', 'session_id5ebf06d8cb5d8137290c4abb64155584fbdb64d8'); // a unique id identifying the user

$context = $sdk->createContext($contextConfig);

$anotherContextConfig = new ContextConfig();
$anotherContextConfig->setUnit('session_id', 'session_id5ebf06d8cb5d8137290c4abb64155584fbdb64d8'); // a unique id identifying the user

$anotherContext = $sdk->createContextWithData($anotherContextConfig, $context->getContextData());
```  

**Refreshing the Context with Fresh Experiment Data**

For long-running contexts, the context is usually created once when the  
application is first started. However, any experiments being tracked in your production code, but started after the context was created, will not be triggered.

To mitigate this, we can use the `Context->refresh()`  method on the `Context`.

```php  
$context->refresh();
```  
The `Context->refresh()` method pulls updated experiment data from the A/B  Smartly collector and will trigger recently started experiments when `Context->getTreatment` is called again.

**Setting Extra Units**

You can add additional units to a context by calling the `Context->setUnit` or `Context->setUnits` methods. These methods may be used, for example, when a user logs in to your application, and you want to use the new unit type in the context.

Please note, you cannot override an already set unit type as that would be a change of identity and would throw an exception. In this case, you must create a new context instead. The `Context->setUnit` and  
`Context->setUnits` methods can be called before the context is ready.

```php 
$context->setUnit('user_id', 143432);
```  

## Basic Usage

### Selecting A Treatment

```php
$treatment = $context->getTreatment('exp_test_experiment');

if ($treatment === 0) {
    // user is in control group (variant 0)
}
else {
    // user is in treatment group
}
```  

### Treatment Variables

```php
$defaultButtonColorValue = 'red';
$buttonColor = $context->getVariableValue('button.color');
```

### Peek at Treatment Variants

Although generally not recommended, it is sometimes necessary to peek at a treatment or variable without triggering an exposure. The A/B Smartly SDK provides a `Context->peekTreatment()` method for that.

```php
$treatment = $context->peekTreatment('exp_test_experiment');

if ($treatment === 0) {
    // user is in control group (variant 0)
}
else {
    // user is in treatment group
}
```  

#### Peeking at variables

```php  
$buttonColor = $context->peekVariableValue('button.color', 'red');
```  

### Overriding Treatment Variants

During development, for example, it is useful to force a treatment for an  
experiment. This can be achieved with the `Context->setOverride()` and/or `Context->setOverrides()`  methods. These methods can be called before the context is ready.

```php
$context->setOverride("exp_test_experiment", 1); // force variant 1 of treatment

$context->setOverrides(
    [
        'exp_test_experiment' => 1,
        'exp_another_experiment' => 0,
    ]
);
```  

## Advanced

### Context Attributes

Attributes are used to pass meta-data about the user and/or the request.  
They can be used later in the Web Console to create segments or audiences.  
They can be set using the `Context->setAttribute()` or `Context->setAttributes()`  methods, before or after the context is ready.

```php
$context->setAttribute('session_id', \session_id());
$context->setAttributes(
    [
        'customer_age' => 'new_customer'
    ]
);
```  

### Custom Assignments

Sometimes it may be necessary to override the automatic selection of a variant. For example, if you wish to have your variant chosen based on data from an API call. This can be accomplished using the `Context->setCustomAssignment()` method.

```php  
$chosenVariant = 1;
$context->setCustomAssignment("experiment_name", $chosenVariant);
```  

If you are running multiple experiments and need to choose different custom assignments for each one, you can do so using the `Context->setCustomAssignments()` method.

```php  
$assignments = [
    "experiment_name" => 1,
    "another_experiment_name" => 0,
    "a_third_experiment_name" => 2
];

$context->setCustomAssignments($assignments);  
```

### Publish

Sometimes it is necessary to ensure all events have been published to the A/B Smartly collector, before proceeding. You can explicitly call the `Context->publish()` method.

```php
$context->publish();
```  

### Finalize

The `close()` method will ensure all events have been published to the A/B Smartly collector, like `Context->publish()`, and will also "seal" the context, throwing an error if any method that could generate an event is called.

```php
$context->close();
```

### Tracking Goals

```php
$context->track(
    'payment',
    (object) ['item_count' => 1, 'total_amount' => 1999.99]
);
```
