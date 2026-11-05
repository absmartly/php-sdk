# Changelog

## 2.0.0 (unreleased)

### BREAKING

- **Units keep their type.** `setUnit`/`setUnits` (Context and ContextConfig) accept strings or numbers and
  `getUnit`/`getUnits` return the value as given (old: numbers were cast to strings). A float unit is hashed as
  its value rounded to an integer, like the JavaScript SDK. Nonblank string UIDs keep their original bytes
  to preserve existing assignments (they are not trimmed).
  Unit error messages now match the other SDKs: `Unit '<type>' UID already set.`,
  `Unit '<type>' UID must not be blank.`, `Unit '<type>' must be a string or a number.`
- **`track()` validates goal properties.** Anything other than null, a plain object or an associative array
  raises `Goal '<name>' properties must be of type object.` (old: PHP `TypeError` or silently accepted).
- **A failed context load no longer rejects.** `createContextAsync()`/`createContextPending()` resolve with a
  context that is ready and failed, has empty data, stores `readyError()` and logs an `Error` event.
- **`close()` rethrows a failed final publish.** The events stay queued and the context stays open so `close()`
  can be retried (old: the error was swallowed and the context closed).

- **Audience operators match the other SDKs.** `in` takes `[haystack, needle]`, not the old PHP
  `[needle, haystack]`; membership uses the shared evaluator's comparison and string-conversion rules,
  matching JavaScript and Python rather than PHP-specific loose/strict membership. Binary operators return null for
  null operands; deep collection comparison ignores object key order and compares nested values.
  These corrections can change targeting for rules that depended on the old PHP-only behavior.
- **Custom fields use the collector format.** `Experiment::$customFieldValues` is `?array`, containing
  `{name,type,value}` objects, rather than the former `?object`/keyed-map representation.
- **Context reads throw before ready.** `getTreatment`, `peekTreatment`, `getVariableValue`,
  `peekVariableValue`, `getVariableKeys`, `getExperiments`, `getCustomFieldKeys`, `customFieldValue`,
  and `getCustomFieldValueType` throw `ABSmartly\SDK\Exception\LogicException` with message
  `ABsmartly Context is not yet ready.` Previously some returned defaults or empty arrays.
  The message capitalization and punctuation also changed; do not match the old text.
- After finalization, existing treatment/exposing reads still throw `ABsmartly Context is finalized.`
  Legacy non-exposing reads (`peekVariableValue`, `getVariableKeys`, `customFieldValue`,
  `getExperiments`) remain usable. The new custom-field key/type APIs require an open context.

### Compatibility retained

- The entry class is now `ABSmartly\SDK`. `ABSmartly\SDK\SDK` remains available as an alias.
- ReactPHP is optional for synchronous use; install the suggested packages for async APIs.
- `ContextEventHandler` remains the base contract for legacy handlers, getters, and setters;
  `ContextPublisher` is an additive subclass.
- `PublishEvent::hashUnit(string $unit)` retains its signature for existing subclass overrides.
  Numeric units are canonicalized by `setUnits()` before that hook is called.
- Nonblank string unit IDs are not trimmed, avoiding reassignment of existing whitespace-bearing IDs.
- Existing non-exposing reads remain available after `close()`/`finalize()`.

### Added

- `createSimple()` with application-before-environment ordering; the legacy factory keeps its ordering.
- Lifecycle aliases and inspection APIs, custom-field key/type APIs, and shared `Utils` helpers.

The numeric-unit return types, pending-read errors, goal validation, and failed-final-publish behavior
are required by cross-SDK conformance. They prevent this release from being strictly backwards-compatible;
2.0 remains the release target even though avoidable compatibility breaks have been removed.
