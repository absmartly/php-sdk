# Changelog

## 2.0.0 (unreleased)

### BREAKING

- **Units keep their type.** `setUnit`/`setUnits` (Context and ContextConfig) accept strings or numbers and
  `getUnit`/`getUnits` return the value as given (old: numbers were cast to strings). A float unit is hashed as
  its value rounded to an integer, like the JavaScript SDK. String UIDs are trimmed.
  Unit error messages now match the other SDKs: `Unit '<type>' UID already set.`,
  `Unit '<type>' UID must not be blank.`, `Unit '<type>' must be a string or a number.`
- **`track()` validates goal properties.** Anything other than null, a plain object or an associative array
  raises `Goal '<name>' properties must be of type object.` (old: PHP `TypeError` or silently accepted).
- **A failed context load no longer rejects.** `createContextAsync()`/`createContextPending()` resolve with a
  context that is ready and failed, has empty data, stores `readyError()` and logs an `Error` event.
- **`close()` rethrows a failed final publish.** The events stay queued and the context stays open so `close()`
  can be retried (old: the error was swallowed and the context closed).
- New `ABSmartly\SDK\Utils` helpers (`hashUnit`, `base64UrlNoPadding`, `utf8Bytes`, `isObject`, `isNumeric`,
  `isPromise`).

- **Context reads throw before ready and after finalize** (parity with the JavaScript SDK `_checkReady`).
  The following methods now throw `ABSmartly\SDK\Exception\LogicException`:
  `getTreatment`, `peekTreatment`, `getVariableValue`, `peekVariableValue`, `getVariableKeys`,
  `getExperiments`, `getCustomFieldKeys`, `customFieldValue`, `getCustomFieldValueType`.
  - Before ready (e.g. a context from `createContextPending()` whose data has not arrived):
    message `ABsmartly Context is not yet ready.`
    Old: `peekVariableValue`/`getVariableKeys`/`getExperiments` returned defaults or empty arrays.
  - After `close()`/`finalize()`: message `ABsmartly Context is finalized.`
    Old: `peekVariableValue` and `getVariableKeys` kept returning values.
    `getExperiments()` is still allowed after finalize (as in JS).
  - The not-ready message changed from `ABSmartly Context is not yet ready` to
    `ABsmartly Context is not yet ready.` Code matching on the message text must be updated.
