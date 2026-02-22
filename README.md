# A/B Smartly SDK

A/B Smartly PHP SDK

## Compatibility

The A/B Smartly PHP SDK is compatible with PHP versions 7.4 and later. For the best performance and code readability, PHP 8.1 or later is recommended. This SDK is being constantly tested with the nightly builds of PHP to ensure it is compatible with the latest PHP version.

### Backwards Compatibility Note

The main SDK class has been renamed from `SDK` to `ABsmartly` to standardize naming across all ABSmartly SDKs. The old `SDK` class name is still available as a deprecated alias for backwards compatibility, but it is recommended to migrate to the new `ABsmartly` class name in new projects.

## Getting Started

### Install the SDK

A/B Smartly PHP SDK can be installed with [`composer`](https://getcomposer.org):

```bash
composer require absmartly/php-sdk
```

### Import and Initialize the SDK

#### Recommended: Simple API

Once the SDK is installed, it can be initialized in your project using the simple API:

```php
use ABSmartly\SDK\ABsmartly;

$sdk = ABsmartly::createWithDefaults(
  endpoint: $endpoint,
  apiKey: $apiKey,
  environment: $environment,
  application: $application
);
```

Note that the above example uses named parameters introduced in PHP 8.0. Although it is strongly recommended to use the latest PHP version, PHP 7.4 is supported as well. On PHP 7.4, parameters are only passed in their order, as named parameters are not supported.

Example for PHP 7.4:
```php
use ABSmartly\SDK\ABsmartly;

$sdk = ABsmartly::createWithDefaults(
  $endpoint, $apiKey, $environment, $application
);
```

#### Advanced: Manual Client Configuration

The above is a shortcut that creates an SDK instance quickly using default values. For advanced use cases where you need custom HTTP clients or configurations, you can manually configure individual components:

```php
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextEventLoggerCallback;

$clientConfig = new ClientConfig($endpoint, $apiKey, $environment, $application);
$client = new Client($clientConfig);
$config = new Config($client);

$sdk = new ABsmartly($config);

$contextConfig = new ContextConfig();
$contextConfig->setEventLogger(new ContextEventLoggerCallback(
    function (string $event, ?object $data) {
        // Custom callback
    }
));

$context = $sdk->createContext($contextConfig);
```

#### Using Async HTTP Client

For non-blocking operations, you can use the ReactPHP-based async HTTP client:

```php
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Http\ReactHttpClient;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\ABsmartly;

$clientConfig = new ClientConfig($endpoint, $apiKey, $environment, $application);

$reactHttpClient = new ReactHttpClient();
$reactHttpClient->timeout = 3000;
$reactHttpClient->retries = 5;

$client = new Client($clientConfig, $reactHttpClient);
$config = new Config($client);

$sdk = new ABsmartly($config);
```

The async HTTP client uses ReactPHP promises and allows for non-blocking I/O operations.

**SDK Options**

| Config                  | Type                                           | Required? | Default                                       | Description                                                                                                                                                                   |
| :---------------------- | :--------------------------------------------- | :-------: | :-------------------------------------------: | :---------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `endpoint`              | `string`                                       | &#9989;   | `null`                                        | The URL to your API endpoint. Most commonly `"your-company.absmartly.io"`                                                                                                    |
| `apiKey`                | `string`                                       | &#9989;   | `null`                                        | Your API key which can be found on the Web Console.                                                                                                                           |
| `environment`           | `string`                                       | &#9989;   | `null`                                        | The environment of the platform where the SDK is installed. Environments are created on the Web Console and should match the available environments in your infrastructure.   |
| `application`           | `string`                                       | &#9989;   | `null`                                        | The name of the application where the SDK is installed. Applications are created on the Web Console and should match the applications where your experiments will be running. |
| `retries`               | `int`                                          | &#10060;  | `5`                                           | The number of retries before the SDK stops trying to connect.                                                                                                                 |
| `timeout`               | `int`                                          | &#10060;  | `3000`                                        | An amount of time, in milliseconds, before the SDK will stop trying to connect.                                                                                               |
| `eventLogger`           | `ContextEventLogger`                           | &#10060;  | `null`                                        | A callback function which runs after SDK events. See [Using a Custom Event Logger](#using-a-custom-event-logger) below.                                                      |
| `contextDataProvider`   | `ContextDataProvider`                          | &#10060;  | auto                                          | Custom provider for context data (advanced usage)                                                                                                                             |
| `contextEventHandler`   | `ContextEventHandler`                          | &#10060;  | auto                                          | Custom handler for publishing events (advanced usage)                                                                                                                         |

### Using a Custom Event Logger

The A/B Smartly SDK can be instantiated with an event logger used for all contexts. In addition, an event logger can be specified when creating a particular context in the `ContextConfig`.

#### Simple Callback Approach

```php
use ABSmartly\SDK\Context\ContextConfig;
use ABSmartly\SDK\Context\ContextEventLoggerCallback;

$contextConfig = new ContextConfig();
$contextConfig->setEventLogger(new ContextEventLoggerCallback(
    function (string $event, ?object $data) {
        // Custom callback
        if ($event === 'Error') {
            error_log('ABSmartly Error: ' . print_r($data, true));
        }
    }
));
```

#### Interface Implementation Approach

Alternatively, you can implement the `ContextEventLogger` interface with a `handleEvent()` method that receives the `Context` object itself, along with a `ContextEventLoggerEvent` object:

```php
use ABSmartly\SDK\Context\Context;
use ABSmartly\SDK\Context\ContextEventLogger;
use ABSmartly\SDK\Context\ContextEventLoggerEvent;

class CustomLogger implements ContextEventLogger {
    public function handleEvent(Context $context, ContextEventLoggerEvent $event): void {
        $eventName = $event->getEvent();
        $eventData = $event->getData();

        // Process the log event
        switch ($eventName) {
            case 'Exposure':
                // Log exposure event
                break;
            case 'Goal':
                // Log goal achievement
                break;
            case 'Error':
                error_log('ABSmartly Error: ' . print_r($eventData, true));
                break;
        }
    }
}

$contextConfig = new ContextConfig();
$contextConfig->setEventLogger(new CustomLogger());
```

**Event Types**

The data parameter depends on the type of event. Currently, the SDK logs the following events:

| Event      | When                                                        | Data                                                        |
| ---------- | ----------------------------------------------------------- | ----------------------------------------------------------- |
| `Error`    | `Context` receives an error                                 | `Exception` object thrown                                   |
| `Ready`    | `Context` turns ready                                       | `ContextData` object used to initialize the context         |
| `Refresh`  | `Context->refresh()` method succeeds                        | `ContextData` used to refresh the context                   |
| `Publish`  | `Context->publish()` method succeeds                        | `PublishEvent` data sent to the A/B Smartly event collector|
| `Exposure` | `Context->getTreatment()` method succeeds on first exposure | `Exposure` data enqueued for publishing                     |
| `Goal`     | `Context->track()` method succeeds                          | `GoalAchievement` goal data enqueued for publishing         |
| `Close`    | `Context->close()` method succeeds the first time           | `null`                                                      |  

## Create a New Context Request

### Synchronously

```php
use ABSmartly\SDK\Context\ContextConfig;

$contextConfig = new ContextConfig();
$contextConfig->setUnit('session_id', '5ebf06d8cb5d8137290c4abb64155584fbdb64d8');

$context = $sdk->createContext($contextConfig);
```

### Asynchronously (with ReactPHP)

When using the async HTTP client, context creation is non-blocking:

```php
use ABSmartly\SDK\Context\ContextConfig;
use React\Promise\PromiseInterface;

$contextConfig = new ContextConfig();
$contextConfig->setUnit('session_id', '5ebf06d8cb5d8137290c4abb64155584fbdb64d8');

$context = $sdk->createContext($contextConfig);

// Use promises for async operations
$context->ready()->then(
    function($context) {
        // Context is ready
        $treatment = $context->getTreatment('exp_test_experiment');
    },
    function($error) {
        // Handle error
        error_log('Context failed: ' . $error->getMessage());
    }
);
```

### With Prefetched Data

To avoid repeating the round-trip on the client-side, you can initialize a context with pre-fetched data from a previous context:

```php
use ABSmartly\SDK\Context\ContextConfig;

$contextConfig = new ContextConfig();
$contextConfig->setUnit('session_id', '5ebf06d8cb5d8137290c4abb64155584fbdb64d8');

$context = $sdk->createContext($contextConfig);

$anotherContextConfig = new ContextConfig();
$anotherContextConfig->setUnit('session_id', 'another-user-id');

$anotherContext = $sdk->createContextWithData($anotherContextConfig, $context->getContextData());
// No need to wait - context is immediately ready
```

### Refreshing the Context with Fresh Experiment Data

For long-running contexts, the context is usually created once when the application is first started. However, any experiments being tracked in your production code, but started after the context was created, will not be triggered.

To mitigate this, we can use the `Context->refresh()` method on the `Context`:

```php
$context->refresh();
```

The `Context->refresh()` method pulls updated experiment data from the A/B Smartly collector and will trigger recently started experiments when `Context->getTreatment()` is called again.

### Setting Extra Units

You can add additional units to a context by calling the `Context->setUnit()` or `Context->setUnits()` methods. These methods may be used, for example, when a user logs in to your application, and you want to use the new unit type in the context.

```php
$context->setUnit('user_id', 143432);

// Or set multiple units at once
$context->setUnits([
    'user_id' => 143432,
    'db_user_id' => 1000013
]);
```

> **Note:** You cannot override an already set unit type as that would be a change of identity and would throw an exception. In this case, you must create a new context instead. The `Context->setUnit()` and `Context->setUnits()` methods can be called before the context is ready.  

## Basic Usage

### Selecting a Treatment

```php
$treatment = $context->getTreatment('exp_test_experiment');

if ($treatment === 0) {
    // user is in control group (variant 0)
} else {
    // user is in treatment group
}
```

### Treatment Variables

```php
$defaultButtonColorValue = 'red';
$buttonColor = $context->getVariableValue('button.color', $defaultButtonColorValue);
```

### Peek at Treatment Variants

Although generally not recommended, it is sometimes necessary to peek at a treatment or variable without triggering an exposure. The A/B Smartly SDK provides a `Context->peekTreatment()` method for that.

```php
$treatment = $context->peekTreatment('exp_test_experiment');

if ($treatment === 0) {
    // user is in control group (variant 0)
} else {
    // user is in treatment group
}
```

#### Peeking at Variables

```php
$buttonColor = $context->peekVariableValue('button.color', 'red');
```

### Overriding Treatment Variants

During development, for example, it is useful to force a treatment for an experiment. This can be achieved with the `Context->setOverride()` and/or `Context->setOverrides()` methods. These methods can be called before the context is ready.

```php
$context->setOverride('exp_test_experiment', 1); // force variant 1 of treatment

$context->setOverrides([
    'exp_test_experiment' => 1,
    'exp_another_experiment' => 0,
]);
```  

## Platform-Specific Examples

### Using with Laravel

Laravel applications can integrate A/B Smartly using service providers and middleware for request-scoped context management.

```php
// config/absmartly.php
return [
    'endpoint' => env('ABSMARTLY_ENDPOINT'),
    'api_key' => env('ABSMARTLY_API_KEY'),
    'application' => env('ABSMARTLY_APPLICATION', 'website'),
    'environment' => env('APP_ENV'),
];

// app/Providers/ABSmartlyServiceProvider.php
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use ABSmartly\SDK\ABsmartly;

class ABSmartlyServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->app->singleton(ABsmartly::class, function ($app) {
            $config = config('absmartly');

            return ABsmartly::createWithDefaults(
                endpoint: $config['endpoint'],
                apiKey: $config['api_key'],
                environment: $config['environment'],
                application: $config['application']
            );
        });
    }
}

// app/Http/Middleware/ABSmartlyContext.php
<?php

namespace App\Http\Middleware;

use Closure;
use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Context\ContextConfig;

class ABSmartlyContext
{
    protected $sdk;

    public function __construct(ABsmartly $sdk)
    {
        $this->sdk = $sdk;
    }

    public function handle($request, Closure $next)
    {
        $contextConfig = new ContextConfig();
        $contextConfig->setUnit('session_id', $request->session()->getId());

        if (auth()->check()) {
            $contextConfig->setUnit('user_id', auth()->id());
        }

        $context = $this->sdk->createContext($contextConfig);
        $request->attributes->set('absmartly_context', $context);

        $response = $next($request);

        $context->close();

        return $response;
    }
}

// app/Http/Controllers/ProductController.php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function show(Request $request)
    {
        $context = $request->attributes->get('absmartly_context');
        $treatment = $context->getTreatment('exp_product_layout');

        if ($treatment === 0) {
            return view('product.show_control');
        } else {
            return view('product.show_treatment');
        }
    }
}
```

### Using with Symfony

Symfony applications can integrate A/B Smartly using dependency injection and event subscribers for request lifecycle management.

```php
// config/services.yaml
services:
    ABSmartly\SDK\ABsmartly:
        factory: ['ABSmartly\SDK\ABsmartly', 'createWithDefaults']
        arguments:
            $endpoint: '%env(ABSMARTLY_ENDPOINT)%'
            $apiKey: '%env(ABSMARTLY_API_KEY)%'
            $environment: '%env(APP_ENV)%'
            $application: 'website'

// src/EventSubscriber/ABSmartlySubscriber.php
<?php

namespace App\EventSubscriber;

use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Context\ContextConfig;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class ABSmartlySubscriber implements EventSubscriberInterface
{
    private $sdk;
    private $context;

    public function __construct(ABsmartly $sdk)
    {
        $this->sdk = $sdk;
    }

    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::REQUEST => 'onKernelRequest',
            KernelEvents::RESPONSE => 'onKernelResponse',
        ];
    }

    public function onKernelRequest(RequestEvent $event)
    {
        $request = $event->getRequest();
        $session = $request->getSession();

        $contextConfig = new ContextConfig();
        $contextConfig->setUnit('session_id', $session->getId());

        $this->context = $this->sdk->createContext($contextConfig);
        $request->attributes->set('absmartly_context', $this->context);
    }

    public function onKernelResponse(ResponseEvent $event)
    {
        if ($this->context) {
            $this->context->close();
        }
    }
}

// src/Controller/ProductController.php
<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductController extends AbstractController
{
    public function show(Request $request): Response
    {
        $context = $request->attributes->get('absmartly_context');
        $treatment = $context->getTreatment('exp_product_layout');

        if ($treatment === 0) {
            return $this->render('product/show_control.html.twig');
        } else {
            return $this->render('product/show_treatment.html.twig');
        }
    }
}
```

## Advanced Request Configuration

### Request Timeout Override

PHP supports per-request timeout configuration through HTTP client options:

```php
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Http\HTTPClient;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Context\ContextConfig;

$httpClient = new HTTPClient();
$httpClient->timeout = 1500;

$clientConfig = new ClientConfig($endpoint, $apiKey, $environment, $application);
$client = new Client($clientConfig, $httpClient);

$config = new Config($client);
$sdk = new ABsmartly($config);

$contextConfig = new ContextConfig();
$contextConfig->setUnit('session_id', 'abc123');

$context = $sdk->createContext($contextConfig);
```

### Async Request with ReactPHP

For non-blocking operations with cancellation support:

```php
use ABSmartly\SDK\Client\ClientConfig;
use ABSmartly\SDK\Client\Client;
use ABSmartly\SDK\Http\ReactHttpClient;
use ABSmartly\SDK\Config;
use ABSmartly\SDK\ABsmartly;
use ABSmartly\SDK\Context\ContextConfig;
use React\EventLoop\Loop;

$reactHttpClient = new ReactHttpClient();
$reactHttpClient->timeout = 1500;

$clientConfig = new ClientConfig($endpoint, $apiKey, $environment, $application);
$client = new Client($clientConfig, $reactHttpClient);

$config = new Config($client);
$sdk = new ABsmartly($config);

$contextConfig = new ContextConfig();
$contextConfig->setUnit('session_id', 'abc123');

$context = $sdk->createContext($contextConfig);

$timeout = Loop::addTimer(1.5, function() use ($context) {
    echo "Context creation timed out\n";
});

$context->ready()->then(
    function($ctx) use ($timeout) {
        Loop::cancelTimer($timeout);
        echo "Context ready!\n";
    },
    function($error) use ($timeout) {
        Loop::cancelTimer($timeout);
        echo "Context failed: " . $error->getMessage() . "\n";
    }
);
```

## Advanced

### Context Attributes

Attributes are used to pass meta-data about the user and/or the request. They can be used later in the Web Console to create segments or audiences. They can be set using the `Context->setAttribute()` or `Context->setAttributes()` methods, before or after the context is ready.

```php
$context->setAttribute('user_agent', $_SERVER['HTTP_USER_AGENT']);

$context->setAttributes([
    'customer_age' => 'new_customer',
    'session_id' => session_id()
]);
```

### Custom Assignments

Sometimes it may be necessary to override the automatic selection of a variant. For example, if you wish to have your variant chosen based on data from an API call. This can be accomplished using the `Context->setCustomAssignment()` method.

```php
$chosenVariant = 1;
$context->setCustomAssignment('experiment_name', $chosenVariant);
```

If you are running multiple experiments and need to choose different custom assignments for each one, you can do so using the `Context->setCustomAssignments()` method.

```php
$assignments = [
    'experiment_name' => 1,
    'another_experiment_name' => 0,
    'a_third_experiment_name' => 2
];

$context->setCustomAssignments($assignments);
```

### Tracking Goals

Goals are created in the A/B Smartly Web Console.

```php
$context->track('payment', (object) [
    'item_count' => 1,
    'total_amount' => 1999.99
]);
```

### Publish

Sometimes it is necessary to ensure all events have been published to the A/B Smartly collector before proceeding. You can explicitly call the `Context->publish()` method.

```php
$context->publish();
```

With async HTTP client:

```php
$context->publish()->then(function() {
    // All events published
    header('Location: https://www.absmartly.com');
});
```

### Finalize

The `close()` method will ensure all events have been published to the A/B Smartly collector, like `Context->publish()`, and will also "seal" the context, throwing an error if any method that could generate an event is called.

```php
$context->close();
```

With async HTTP client:

```php
$context->close()->then(function() {
    // Context closed and all events published
    header('Location: https://www.absmartly.com');
});
```

## About A/B Smartly

**A/B Smartly** is the leading provider of state-of-the-art, on-premises, full-stack experimentation platforms for engineering and product teams that want to confidently deploy features as fast as they can develop them.
A/B Smartly's real-time analytics helps engineering and product teams ensure that new features will improve the customer experience without breaking or degrading performance and/or business metrics.

### Have a look at our growing list of clients and SDKs:

- [JavaScript SDK](https://www.github.com/absmartly/javascript-sdk)
- [Java SDK](https://www.github.com/absmartly/java-sdk)
- [PHP SDK](https://www.github.com/absmartly/php-sdk)
- [Swift SDK](https://www.github.com/absmartly/swift-sdk)
- [Vue2 SDK](https://www.github.com/absmartly/vue2-sdk)
- [Vue3 SDK](https://www.github.com/absmartly/vue3-sdk)
- [React SDK](https://www.github.com/absmartly/react-sdk)
- [Python3 SDK](https://www.github.com/absmartly/python3-sdk)
- [Go SDK](https://www.github.com/absmartly/go-sdk)
- [Ruby SDK](https://www.github.com/absmartly/ruby-sdk)
- [.NET SDK](https://www.github.com/absmartly/dotnet-sdk)
- [Dart SDK](https://www.github.com/absmartly/dart-sdk)
- [Flutter SDK](https://www.github.com/absmartly/flutter-sdk)

## Documentation

- [Full Documentation](https://docs.absmartly.com/)

## License

MIT License - see [LICENSE](LICENSE) for details.
