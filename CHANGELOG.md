# Changelog

## 2.0.0 (unreleased)

### BREAKING

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
