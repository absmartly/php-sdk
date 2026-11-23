# Changelog

## 1.1.0

This release retains the 1.0.3 entry class and legacy method signatures, including subclass overrides. It also
changes some runtime behavior, including experiment bucketing, exposure payloads and error handling; review the
changes below before upgrading. It is not a guarantee that every 1.0.3 workload behaves unchanged.

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
- `setAttributeValue()`/`setAttributeValues()` and `trackWithProperties()` reject a non-string value or property
  that cannot be JSON-encoded (`INF`, `NaN`, a resource, an array or object containing invalid UTF-8, or a
  `JsonSerializable` whose `jsonSerialize()` throws, or a value nested more than 509 levels deep, which the stock
  publisher can't encode within json_encode's default depth of 512) with
  `InvalidArgumentException` at the time of the call. Strings, and the 1.0.3 `setAttribute()`/`setAttributes()`/
  `track()` and `ContextConfig` paths, are accepted unchecked as before. The check doesn't hold a snapshot:
  - A plain `stdClass` passed to `trackWithProperties()` is copied (one level), so later changes to its top-level
    properties don't reach the goal. `track()` keeps the object itself, as in 1.0.3.
  - Other objects are kept as given and encoded again at publish. An object changed afterwards so that it can't be
    encoded fails that publish, and the batch stays queued for retry. `Context::$maxRetainedEvents` caps the exposures
    and goals kept after a publish fails with an `Exception` (default 10,000; 0 or less means no cap). A PHP `Error`
    thrown while encoding, such as a `TypeError` from `jsonSerialize()`, isn't caught: it escapes `publish()`, and
    that attempt doesn't trim the queue, as with `track()` in 1.0.3.
- `setUnits()` accepts int and float UIDs (stored as strings; `setUnit()` keeps its `string $uid` parameter, so
  non-strict callers' numbers convert as before). `setAttributeValue()`/`setAttributeValues()` retain typed values;
  `trackWithProperties()` accepts objects or associative arrays. Legacy `setAttribute()` and `track()` retain their
  1.0.3 signatures.

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
- **Typed attributes are opt-in.** Values set through the new `setAttributeValue()` and `setAttributeValues()`
  keep their JSON types for evaluation and publication. `setAttribute()`, `setAttributes()` and `ContextConfig`
  attributes still convert scalar values to strings, as in 1.0.3 (or reject them under strict typing).
- **Audience re-evaluation after `setAttribute()`.** As in JS, a treatment computed before an attribute changed is
  re-evaluated, so the treatment and exposures can change. *Affects:* code setting audience attributes after
  reading a treatment; strict audiences can move from variant 0 and send a second exposure.
- **Shared variable keys.** A key defined by several experiments resolves to the lowest-id experiment the unit is
  in (1.0.3 used the last one loaded); see Fixed for the full rule. `getVariableKeys()` keeps its shape and reports
  the lowest-id experiment. *Affects:* anyone running two or more experiments whose variants define the same
  variable key.

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
- **Refresh failures retain existing data.** A failed refresh no longer empties the context. As in 1.0.3,
  refresh still fetches after an initial load failure and applies recovered data, but the failed flag remains true;
  a failed context drops queued events on flush. Create a new context for full recovery.
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
- **`getVariableValue()` exposes only the experiment whose value it uses.** 1.0.3 exposed the experiment
  behind a key even when the unit was not in it: on a strict audience mismatch, or with no unit for its unit type
  (exposed as `assigned`), recording units in an experiment they never saw and returning its control value. With
  shared keys, the experiment picked could also be one the unit was not in while another one it was in was
  ignored. *Affects:* anyone with strict audiences or experiments on a unit type a context may lack, and anyone
  running two or more experiments whose variants define the same variable key. The rule now applied, as in the
  Java SDK:
  - The candidates are the experiments whose variants (any variant) define the key, by ascending experiment id.
  - The winner is the first candidate the unit is assigned to (including outside the traffic split), overridden
    into or rule-assigned to. If there is none, the caller default is returned and nothing is exposed.
  - If the winner's variant does not define the key, the caller default is returned; later candidates are not
    consulted, so the arm a unit got never decides which experiment it reads. A key present with a `null`,
    `false`, `0` or empty value is returned as is.
  - `getVariableValue()` exposes only the winner; candidates that were checked but did not win are not exposed.
    `peekVariableValue()` exposes nothing. `getTreatment()` still exposes the experiment it is asked about.
- `getVariableValue()` and `peekVariableValue()` agree: both return the default when the unit is neither
  assigned nor overridden (1.0.3's peek returned the control variant's value).
- An experiment missing a required field, with a field of the wrong type, or with malformed audience JSON no
  longer fails the whole context (1.0.3 failed it for the last two): it is left out (an `Error` event is logged)
  and the other experiments still load, on the first load and on `refresh()`.
- The JSON decoder accepts payloads nested deeper than 16 levels (`Client::decode()`).
- **Published events are no longer sent again on every later publish.** 1.0.3 kept every exposure and goal after
  a successful `publish()`, so each later publish re-sent all earlier events (goals per PUT: `g1`, then `g1,g2`,
  then `g1,g2,g3`); now a successful publish clears the events it sent. A publish that fails keeps its events for
  the next attempt, so if the collector stored a batch but its response was lost, that batch can be sent again.
  *Affects:* contexts that publish more than once, whose exported
  exposure and goal counts were inflated by the duplicates.
- **An override to variant 0 is exposed once.** 1.0.3 read an override of `0` as "no override", rebuilt the
  assignment and queued a new exposure on every `getTreatment()`. *Affects:* contexts that override an experiment
  to variant 0, whose exported exposure counts were inflated.
- A batch the collector accepted is cleared before the `Publish` event is logged, so a throwing event logger can
  no longer cause it to be sent again.
- `curl_close()` is no longer called on PHP 8+ (deprecated in 8.5), and `HTTPClient` no longer triggers the
  PHP 8.4 implicit-nullable deprecation.
