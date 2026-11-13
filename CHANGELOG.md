# Changelog

## 2.0.0 (unreleased)

### BREAKING

- **Traffic eligibility uses the traffic seed.** Eligibility for experiments with a traffic split below 100% is
  now assigned with `trafficSeedHi`/`trafficSeedLo`, as in the other SDKs (old: the variant seed was reused).
  This re-buckets eligibility, and therefore exposures and treatments, for every running experiment with partial
  traffic. Restart or start a new iteration of affected experiments when upgrading.
- **`audienceStrict: false` is honoured.** Only an experiment whose `audienceStrict` is true forces variant 0 on an
  audience mismatch (old: the field being present, even as false, was enough). Users of non-strict experiments who
  failed the audience are now assigned normally.
- **Audience operators handle mismatched and null operands.** `in` keeps its `[haystack, needle]` order and the
  strict array membership of v1.0.3 (`"25"` is not in `[25, 30]`); a number needle against a string haystack now
  yields null (old: a PHP `TypeError`). Binary operators return null for null operands; deep collection
  comparison ignores object key order and compares nested values. These corrections can change targeting for rules
  that depended on the old PHP-only behavior.
- **Custom fields use the collector format.** `Experiment::$customFieldValues` is `?array`, containing
  `{name,type,value}` objects, rather than the former `?object`/keyed-map representation.
- **Typed attributes and numeric comparison.** Attribute values keep their JSON type and are compared
  numerically (e.g. `age >= 20` treats `age` as a number, and the published value is `21`, not `"21"`), matching
  the other SDKs. Rules that relied on the old string comparison can change which units match.
- **Audience is re-evaluated after `setAttribute`.** Setting an attribute after a treatment was computed now
  re-evaluates the audience, as in JS, so the treatment (and the exposures sent) can change.
- **Unit id `"0"`/`0` is now assigned.** The previous `?? null` truthiness treated `"0"` as missing; it is now a
  valid unit id, so experiments keyed on it move from control to their assigned variant.
- **Failed `close()` re-throws and leaves the context open.** When the final publish fails, `close()` now
  re-throws the collector error and the context stays open (`isClosed()` is false) with its events still queued; a
  later `close()` retries, and the `Close` event is emitted only once the close succeeds. `publish()`/`flush()`
  keep the v1.0.3 behaviour of logging and returning without throwing.
- **Events retained after a failed publish are bounded and `isFailed()` no longer flips on a publish failure.**
  A failed `publish()`/`close()` keeps its exposures and goals queued for a later retry instead of discarding them
  (v1.0.3 discarded every event after the first failure and marked the context failed). The retained queue is now
  capped at `Context::$maxRetainedEvents` (default 10,000): when exceeded, the oldest events are dropped and an
  `Error` event is logged, so a long-lived worker held through a collector outage cannot grow without limit.
  Because a publish failure no longer marks the context failed, code that relied on `isFailed()` flipping after a
  failed publish must change.
- **Shared variable keys resolve across experiments, lowest id first.** A variable key defined by more than one
  experiment is resolved by walking those experiments in ascending experiment-id order (JS
  `_resolveVariableValue`): the first assigned-or-overridden experiment whose variant contains the key wins, and
  each visited experiment with variables is exposed. `getVariableKeys()` keeps its single-name shape but now
  reports the lowest-id experiment for a shared key (old: the last experiment loaded).
- **`getVariableValue()` returns the default for unassigned units.** `getVariableValue()` and
  `peekVariableValue()` now agree: both return the caller's default when the unit is neither assigned nor
  overridden (old: `peekVariableValue()` returned the control variant's value).
- **`match` treats slashes literally.** `match` no longer parses a `"/pattern/flags"` form; leading/trailing
  slashes are stripped and the rest is matched literally, as in v1.0.3 and JS (`new RegExp("/^p/i")` is a
  literal-slash pattern). PHP-only flag parsing that was briefly introduced is removed.
- **Mutators throw after `close()`.** `setAttribute()`, `setAttributes()`, `setUnit()` and `setUnits()` now throw
  `ABSmartly Context is closed` on a closed context, as in the JS SDK (old: they were accepted and returned the
  context). Set attributes and units before closing.

### Compatibility retained

- Units are stored as strings: `setUnit()`/`setUnits()` accept numbers and store their string form, and
  `getUnit()`/`getUnits()` return strings. `ContextConfig::setUnit(string, string)` and `getUnit(): ?string`
  keep their signatures for subclasses.
- `track()` accepts any object (stdClass, DTOs, `ArrayObject`, `JsonSerializable`) or an associative array;
  lists and scalars raise `Goal '<name>' properties must be of type object.`
- Error messages are unchanged: `ABSmartly Context is not yet ready`, `ABSmartly Context is closed`,
  `Unit "<type>" UID is already set`, `Unit "<type>" UID must not be blank`.
- The entry class is now `ABSmartly\SDK`. `ABSmartly\SDK\SDK` remains available as an alias.
- ReactPHP is optional for synchronous use; install the suggested packages for async APIs.
- `ContextEventHandler` remains the base contract for legacy handlers, getters, and setters;
  `ContextPublisher` is an additive subclass.
- `PublishEvent::hashUnit(string $unit)` retains its signature for existing subclass overrides.
- Nonblank string unit IDs are not trimmed, avoiding reassignment of existing whitespace-bearing IDs.
- Existing non-exposing reads (`peekVariableValue`, `getVariableKeys`, `customFieldValue`, `getExperiments`)
  remain available after `close()`/`finalize()`.

### Changed

- The `timeout` option stays the connect timeout (`CURLOPT_CONNECTTIMEOUT_MS`) as in v1.0.3, bounding only
  connection establishment, not the whole request. An optional total budget for the entire request (including
  retries and back-off) is available opt-in via `HTTPClient::$totalTimeout`/`ReactHttpClient::$totalTimeout`
  (0, off, by default). GET requests retry transport errors and HTTP 5xx/408/429 up to the configured count; PUT
  and POST are sent exactly once.
- Context reads (`getTreatment`, `peekTreatment`, `getVariableValue`, `peekVariableValue`, `getVariableKeys`,
  `getExperiments`, `getCustomFieldKeys`, `customFieldValue`, `getCustomFieldValueType`) throw
  `ABSmartly Context is not yet ready` on a pending context created with `createContextPending()`.
- `createContextAsync()`/`createContextPending()` resolve a failed load with a context that is ready and failed,
  has empty data, stores `readyError()` and logs an `Error` event.

### Fixed

- A batch the collector has accepted is cleared from the queue before the `Publish` event is logged, so an event
  logger that throws on `Publish` no longer causes those exposures and goals to be retained and sent again, and is
  not reported as a publish failure (`close()` still completes).
- `createContextAsync()`/`createContextPending()` resolve a context that is ready and failed when the loaded data
  raises any `Throwable` (e.g. a `TypeError` from a mistyped field), not only an `Exception`.
- `ReactHttpClient::$timeout` of 0 or less now means no per-attempt limit, as documented (it previously made every
  request time out immediately).
- A failed context with nothing queued no longer logs `Discarding 0 exposures and 0 goals` on every `publish()`.
- `refresh()` on a context whose initial load failed is a no-op: it no longer fetches or applies data, stays
  failed, keeps `readyError()`, and emits no `Refresh` or `Error` event, matching the JS SDK's `_refresh`
  (`if (!this._failed)`). A failed context can no longer be recovered by `refresh()`.
- `Context::close()` no longer closes the SDK's shared HTTP client, so closing one context no longer breaks its
  sibling contexts. The SDK owner can still close the shared client with `SDK::close()`.

### Added

- `createSimple()` with application-before-environment ordering; the legacy factory keeps its ordering.
- Lifecycle aliases (`finalize()`, `isFinalized()`, `isFinalizing()`, `readyError()`) and the
  `ContextEventLoggerEvent::Finalize` constant for cross-SDK code, custom-field key/type APIs, and shared
  `Utils` helpers.
