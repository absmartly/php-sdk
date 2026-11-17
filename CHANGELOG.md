# Changelog

## 1.1.0

A non-breaking release of 1.0.3: code written against 1.0.3 compiles and runs unchanged (the entry class, method
signatures customers call or override, exception types and messages, the HTTP connect timeout and the
single-attempt GET are all kept). The behaviour changes below are the agreed cross-SDK contract, or fixes of
1.0.3 bugs; each says who is affected. Changes that would break 1.0.3 code wait for 2.0.

### Added

- **Assignment rules.** An experiment's `assignmentRules` are evaluated in order, and the first rule whose
  environments and conditions match decides the variant: `assign` rules pick a fixed variant and `split` rules
  split traffic with their own percentages (such as `20/30/50`, which must sum to 100 within 0.01), hashing the
  unit with the experiment seed. A matching rule wins over the audience (including `audienceStrict`), traffic,
  full-on and custom assignments; an override still wins over rules. Rule conditions are re-evaluated when an
  attribute changes; a split rule matched without its unit gives the full-on variant until the unit is set, unless
  it was already exposed. Invalid rules are skipped, and an object sent in place of the rules JSON string is
  ignored. Rules JSON is decoded once per data load, and experiments without rules or an audience do no attribute
  work.
- **SemVer operators.** `semver_eq`, `semver_gt`, `semver_gte`, `semver_lt` and `semver_lte` compare versions in
  audience and rule conditions, matching the JavaScript and Java SDKs.
- **Client metadata.** `Client::SDK_VERSION`, `Client::SDK_NAME` and `Client::getAgent()`;
  `ClientConfig::setApplicationVersion()` sets `X-Application-Version` (whole numbers only; other values are sent
  as `0`, the previous fixed value).
- **Async API (optional ReactPHP).** `ReactHttpClient`, `SDK::createContextAsync()`/`createContextPending()`,
  `AsyncContextDataProvider`, `AsyncContextEventHandler` and the `ClientInterface`/`HttpClientInterface`
  interfaces. ReactPHP stays optional: install the suggested packages to use them. Unlike `HTTPClient`,
  `ReactHttpClient` retries a failed GET by default (`$retries`, non-blocking back-off); set `$retries = 0` to
  disable it.
- **Opt-in HTTP retries.** `HTTPClient::$totalTimeout` sets a total budget for a request; when it is set, GET is
  retried up to `$retries` times on transport errors and HTTP 5xx/408/429. Without it a request is sent once, as
  in 1.0.3. PUT and POST are never retried.
- `SDK::createSimple()` (application before environment), `Config::setContextPublisher()` and
  `ContextPublisher`, `Config::setContextEventLogger()` (an SDK-wide event logger), and `SDK::getClient()`.
- Context: `getUnit()`/`getUnits()`, `customFieldValue()`/`getCustomFieldKeys()`/`getCustomFieldValueType()`,
  `pending()`, lifecycle aliases `finalize()`/`isFinalized()`/`isFinalizing()`/`readyError()`, and the
  `ContextEventLoggerEvent::Finalize` constant. `Context::$maxRetainedEvents` caps events kept for retry after
  failed publishes (default 10,000).
- `ABSmartly\SDK` is an alias of `ABSmartly\SDK\SDK`, the entry class name the 2.x line will use.
- `setUnits()` accepts int and float UIDs (stored as strings; `setUnit()` keeps its `string $uid` parameter, so
  non-strict callers' numbers convert as before), `setAttribute()` accepts non-string values, and `track()` accepts
  any object or an associative array of properties.

### Changed

- **`ruleOverride` on every exposure.** Each exposure in the publish payload (and a serialized `Exposure`) now
  carries `ruleOverride`, `false` unless an assignment rule decided the variant, as the JavaScript SDK sends it.
  *Affects:* consumers that validate the exported exposure schema strictly, and custom `ContextEventHandler`s that
  compare payloads byte for byte. `Exposure::create()` keeps its 1.0.3 signature.
- **Versioned `X-Agent`.** The header is now `absmartly-php-sdk/1.1.0` (1.0.3 sent `absmartly-php-sdk/v1.0.3`),
  the `<name>/<version>` form of the other SDKs. It is stored in the exported `agent` column. *Affects:* dashboards
  or exports filtering on the old `v`-prefixed value.
- **System attributes.** The client's `application` and `environment` names are available to audience and rule
  conditions without being set (a user attribute of the same name wins); they are not published. *Affects:* audiences
  with an `application`/`environment` condition, which used to see them as missing; `audienceStrict` experiments
  with such a condition no longer force variant 0. `app_version` is never injected.
- **Typed attributes.** Attribute values keep their JSON type and compare numerically (`age >= 20` treats `age` as
  a number). A string still compares as in 1.0.3. *Affects:* rules relying on 1.0.3's string comparison of numbers
  passed as strings by non-strict callers; the published value of a number is now a number.
- **Audience re-evaluation after `setAttribute()`.** As in JS, a treatment computed before an attribute changed is
  re-evaluated, so the treatment and exposures can change. *Affects:* code setting audience attributes after
  reading a treatment; strict audiences can move from variant 0 and send a second exposure.
- **Shared variable keys.** A key defined by several experiments resolves in ascending experiment-id order (JS
  `_resolveVariableValue`); `getVariableKeys()` keeps its shape and reports the lowest-id experiment. *Affects:*
  experiments sharing variable keys (1.0.3 used the last one loaded).

- **Traffic eligibility uses the traffic seed.** Eligibility for experiments with traffic below 100% is assigned
  with `trafficSeedHi`/`trafficSeedLo`, as in every other SDK and the backend (1.0.3 reused the variant seed).
  *Affects:* running experiments with partial traffic re-bucket eligibility (about a quarter of units move on a 50%
  experiment); restart or start a new iteration of affected experiments when upgrading.
- **`audienceStrict: false` is honoured.** Only a strict audience forces variant 0 on a mismatch (1.0.3 forced it
  when the field was present at all). *Affects:* non-strict experiments, whose mismatching units are now assigned.
- **Unit id `"0"` is assigned.** 1.0.3 treated it as missing and served control. *Affects:* units whose id is `"0"`.
- **A failed `close()` throws and leaves the context open.** It re-throws the collector error with the events still
  queued; a later `close()` retries, and `Close` is emitted once the close succeeds (1.0.3 logged the error, closed
  and lost the events). `publish()` keeps the 1.0.3 behaviour of logging and returning. *Affects:* code calling
  `close()` without a `try`/`catch` while the collector is unreachable; catch the exception or call `publish()`.
- **Mutators throw after `close()`.** `setAttribute()`, `setAttributes()`, `setUnit()` and `setUnits()` throw
  `ABSmartly Context is closed`, as `track()` already did, as the README's "seal the context" says, and as JS does.
  *Affects:* code that sets attributes or units on a closed context; set them before closing.
- **`refresh()` on a context whose first load failed is a no-op** (JS `_refresh`): it stays failed and serves
  control; create a new context to recover (1.0.3 re-fetched and recovered). A `refresh()` that fails now keeps the
  existing data instead of emptying the context. *Affects:* code that relied on `refresh()` to recover a context
  whose `createContext()` failed.
- **Wider `Context` parameter types.** `setAttribute(string $name, $value)` and `track(string $goalName,
  $properties = null)` accept typed attribute values and array properties (1.0.3: `string $value`, `?object
  $properties`). Callers are unaffected. A class extending `Context` that redeclares these two methods with the
  1.0.3 types no longer loads on PHP 8; `Context` has a private constructor and is only created by the SDK, so such a
  subclass could not be used with 1.0.3 either.
- `var_dump()` of a `ClientConfig` (`__debugInfo()`) also lists `applicationVersion`.

### Fixed

- Audience operators: a null operand gives null (no match) instead of comparing as equal; `in` with a number needle
  in a string haystack gives null instead of a PHP `TypeError`; deep equality ignores object key order and compares
  nested values; `match` uses a delimiter the pattern does not contain, so a pattern with `/` no longer errors.
  *Affects:* audiences that matched only because of these defects.
- A failed publish no longer discards every queued event and marks the context failed: events stay queued for the
  next `publish()`/`close()`, `publish()` still logs and returns, and `isFailed()` only reports a failed load.
- `Context::close()` no longer closes the SDK's shared HTTP client, so closing one context no longer breaks
  sibling contexts. `SDK::close()` still closes it.
- `getVariableValue()` and `peekVariableValue()` agree: both return the default when the unit is neither
  assigned nor overridden (1.0.3's peek returned the control variant's value).
- An experiment missing a required field no longer fails the whole context: it is left out (an `Error` event is
  logged) and the other experiments still load.
- The JSON decoder accepts payloads nested deeper than 16 levels (`Client::decode()`).
- A batch the collector accepted is cleared before the `Publish` event is logged, so a throwing event logger can
  no longer cause it to be sent again.
- `curl_close()` is no longer called on PHP 8+ (deprecated in 8.5), and `HTTPClient` no longer triggers the
  PHP 8.4 implicit-nullable deprecation.
