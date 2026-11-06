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

### Compatibility retained

- `publish()` and `close()` do not throw when the collector fails: the error is logged and the events stay
  queued, so a later `publish()`/`close()` retries them (v1.0.3 discarded every event after the first failure).
  `close()` closes the context and emits the `Close` event, as before.
- `refresh()` on a context whose initial load failed fetches again, and a successful refresh clears `isFailed()`.
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

- Context reads (`getTreatment`, `peekTreatment`, `getVariableValue`, `peekVariableValue`, `getVariableKeys`,
  `getExperiments`, `getCustomFieldKeys`, `customFieldValue`, `getCustomFieldValueType`) throw
  `ABSmartly Context is not yet ready` on a pending context created with `createContextPending()`.
- `createContextAsync()`/`createContextPending()` resolve a failed load with a context that is ready and failed,
  has empty data, stores `readyError()` and logs an `Error` event.

### Added

- `createSimple()` with application-before-environment ordering; the legacy factory keeps its ordering.
- Lifecycle aliases (`finalize()`, `isFinalized()`, `isFinalizing()`, `readyError()`) and the
  `ContextEventLoggerEvent::Finalize` constant for cross-SDK code, custom-field key/type APIs, and shared
  `Utils` helpers.
