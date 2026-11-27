# Changelog

## 1.3.0

### Added

- **Switchback experiments** (R33, cluster-defined), as in released Java 1.10.0 and JavaScript 1.14.1. An
  experiment with `randomizationType: "switchback"` is randomized over clusters and time: `Experiment` gains
  `$randomizationType`, `$switchbackClusters` (unit type => attribute path, in the served order, which is kept), `$switchbackClusterValues`, `$switchbackStartAt`, `$switchbackPeriodMs`,
  `$switchbackCarryoverDurationMs`, `$switchbackBurnInDurationMs`, `$switchbackBurnOutDurationMs`,
  `$switchbackDesignType` and `$switchbackEstimand`, all optional. The first context unit, in the
  declared order, whose attribute path gives a value serves the experiment, and that raw value (a non-empty string
  as-is, an integer as its decimal text; floats such as `3.0`, booleans, objects, arrays, `""` and null give none) is
  the cluster key of the platform's balanced-block draw for the period `floor((now - switchbackStartAt) /
  switchbackPeriodMs)`. Units that disagree, a value outside `switchbackClusterValues` or a missing value give a
  control that records no exposure; a pre-anchor read or an unservable schedule replaces only the period draw, after
  overrides, rules, strict audiences and full-on, so otherwise also a silent control. Overrides, assignment rules, strict
  audiences, full-on, traffic and custom assignments apply as for a unit experiment, with the serving unit's traffic
  and split-rule draws hashed with the platform murmur3 on every runtime. Holdouts never apply to a switchback. The
  serving unit and cluster key are read once per period, and each period exposes once, stamped with the clock reading
  of the exposing call. `getVariableValue()` exposes the winning switchback when it is assigned, overridden or
  rule-assigned, even when its arm lacks the key; a strict mismatch records nothing.
  Callers must set cluster attributes before a period's first read and keep them unchanged through its exposure and
  any re-queue: a change within a period is not supported, the cached variant is kept and the change takes effect
  from the next period (the same constraint as every SDK's switchbacks). A switchback exposure queued before a unit
  was added is published first, in its own batch with the units it was served with, since the collector resolves the
  serving unit again from a publish's units; that `publish()` calls the event handler once per batch. Exposures and
  the publish payload carry no new fields. Payloads without switchbacks keep the 1.2.0 read paths; cached
  variable reads (`getVariableValue()` and `peekVariableValue()`) measured about 3-8% slower than 1.2.0 with
  opcache (about 20-40 ns/call in synthetic warmed benchmarks; `getTreatment()` within about 1%). See the README's *Switchback Experiments*.
  Set cluster attributes as strings or integers: a float attribute is published as JSON (`3.0` as `3`), so the
  collector, which resolves the cluster again from the published attributes, could key it where the SDK did not.

### Changed

- `X-Agent` changes from `absmartly-php-sdk/1.2.0` to `absmartly-php-sdk/1.3.0` for all callers, including
  non-switchback contexts. Update exact-version agent filters in exports or dashboards if used.
- **Switchback experiments are served as switchbacks.** PHP 1.2.0 served `randomizationType: "switchback"`
  as sticky unit experiments. PHP 1.3.0 assigns them by cluster and period, so running switchbacks can change
  treatment and publish one exposure per period under the serving unit. Upgrade every SDK serving a switchback.
- With switchback data installed, publishing drains the current queue plus at most one pass of callback work.
  A nested `close()` requests completion from the outer publish; callback work left after the final pass remains
  queued and the context stays open. Check `isClosed()` and retry `close()` to finish. A failed top-level
  `close()` followed by `publish()` does not close the context, as for unit data.
- Non-switchback PHP 1.2.0 runtime, public model API and serialization are retained, including holdouts,
  assignment rules, shared variable lookup, and PHP 7.4 support.

## 1.2.0

### Added

- **Holdouts.** `ContextData::$holdouts` carries the collector's `holdouts` and `Experiment::$holdoutIds` (and
  `$holdoutType`) the ids that cover each experiment, as in the JavaScript and Java SDKs. A unit held out by any of
  an experiment's holdouts gets control (variant 0, not assigned) without an exposure of its own, ahead of the
  audience, full-on, traffic and custom assignment; an override or a matching assignment rule still wins. Arm 0 of
  a holdout holds the unit out; a 3-arm holdout's arm 1 holds out only experiments that are not full-on (the arm
  count comes from `split`, never `holdoutType`). Each holdout publishes one ordinary exposure (its `id`, `name`
  and arm as `variant`, hashed with the covered experiment's unit type) per unit type, on the first exposing
  `getTreatment()`/`getVariableValue()` of an experiment it covers; peeks publish nothing. The arm is kept until
  the holdout's iteration changes, so a same-iteration seed or split edit does not re-randomize or re-expose, and a
  unit set later (even by an attribute getter during an audience check) re-resolves a not-yet-exposed experiment,
  except a candidate an exposing `getVariableValue()` passed over: as without holdouts, it keeps the decision made
  without the unit (for that key and for `getTreatment()`) until a refresh rebuilds it. A decision already served
  keeps its treatment even if the retained-event cap drops its exposure; the exposure is just queued again.
  Unlike JS, which coerces them, malformed holdouts are
  ignored and their experiments assign normally: a holdout whose `id`, `iteration`, `seedHi` or `seedLo` is
  missing, null or not a JSON integer within the int64 range (integral floats such as `11.0` count; numeric
  strings such as `"11"` do not), whose `name` is not a string, or whose `split` is not a non-empty array of
  numbers; and `holdoutIds` entries that are not such integers. Ids that name no holdout and unknown fields are
  ignored too. `new ContextData($experiments)` keeps working (no holdouts). Unlike JS and Java, an overridden
  experiment covered by holdouts is rebuilt when a refresh changes its unit type, so its overridden exposure and
  holdout exposure are queued again for the new unit type. A holdout exposure that the retained-event cap drops is
  queued again by the next exposing read of an experiment whose cached assignment still pins that arm, provided the
  arm was still current when it was dropped. An arm already replaced by a new draw when the cap drops it is not
  queued again; the cap is otherwise lossy.
  - **Shared variable keys.** The 1.1.0 rule is unchanged: only the winner is exposed, and a winner whose variant
    lacks the key returns the caller default. A held-out candidate never wins (it is not assigned), so the walk
    moves on to the next one. Each held-out candidate `getVariableValue()` checks before the winner still queues its
    holdouts' exposures (never its own), so the holdout counts the unit. When no candidate wins, the first held-out
    candidate's control value is returned if its control variant defines the key, else the caller default.
  - **Cost.** Payloads without holdouts keep the 1.1.0 variable lookup results and exposures. On PHP 8.5 with no
    holdouts, context creation measured about 5-8% slower than 1.1.0 (extra holdout validation; the smaller
    figure with 50 experiments, the larger with 4), cached `getVariableValue()` about 3-5% slower with opcache and
    about 9-12% without (coverage is checked per candidate, since a logger refresh can install it mid-walk), and
    `getTreatment()` level with opcache and about 5% slower without.

### Changed

- Ready and Refresh event logger data (`ContextData`) now has a `holdouts` property, an empty array when there are
  none.
- No new declared properties or methods on the public model classes: a subclass of `ContextData`, `Experiment` or
  `Assignment` that already declares a property or method with one of the new holdout names (typed or untyped) still
  loads and works.
- With holdouts in the data, an exposure logger error on a held-out candidate during `getVariableValue()` no longer
  stops the walk over the experiments defining the key: the winner is still found and exposed, and the first error
  is re-thrown afterwards, as in the Java SDK. Once an event logger closes the context mid-read, whether or not it
  then throws, nothing more is queued from that read (`getTreatment()` or `getVariableValue()`), including the
  experiment's holdout exposures, since a closed context can't publish them. If any callback closes the context
  while a logger error is pending, `getVariableValue()` evaluates no later experiment and re-throws that first
  error. A context whose data has never covered an experiment with a holdout keeps the 1.1.0 exception and queueing
  behaviour.
- An audience attribute getter that reads an experiment while its decision is being made (a first decision, or a
  re-check that replaces the cached one) no longer queues that read's exposure twice: with or without holdouts, the
  outer read keeps a nested decision made on the currently installed data without evaluating its audience again.
  If data was installed after the nested decision, it validates that decision again, once: if a callback installs new
  data again during that, the outer read keeps the nested decision and the next read decides again (so a getter that
  refreshes on every read still finishes). So a getter that refreshes
  the experiment away and then reads it gives both reads the unknown-experiment control (1.1.0 gave the outer read
  the removed definition's variant and exposed both). A re-check whose cached decision still matches returns it as in
  1.1.0.
- If an event logger (or an audience attribute getter) refreshes the context during `getVariableValue()`, the rest
  of the lookup uses the refreshed data. An experiment the refresh removed, or that no longer defines the key,
  including the one being decided when the getter refreshed, is skipped unless it is overridden, even when an
  earlier peek cached its held-out assignment, so it queues no exposures, unlike JS, which still serves the cached
  assignment. A decision that a throwing audience getter aborted is discarded, so it can't supply holdout
  exposures later, and a read of the same experiment from inside that getter makes its own decision rather than
  serving the unfinished one.
- `refresh()` keeps the previous data and logs an `Error` event when fetching or installing the new data raises any
  `Throwable`, not only an `Exception`. A data provider that throws an `\Error`, or a mistyped field that raises a
  `TypeError` while installing, no longer escapes `refresh()` (which also left the experiment index cleared). Code
  that caught `\Error` around `refresh()` should listen for the `Error` event instead. A refresh installs its data
  only once all of it is processed, so an `Error` logger for a malformed experiment in it reads the previous data,
  and if that logger throws, the refresh is rejected with nothing from the new data left behind (on a pending
  context, nothing is installed). If such a logger itself refreshes, later decisions follow whichever data ends up
  installed. The initial load still installs experiments as it goes, as in 1.1.0; a `refresh()` called from a
  callback during it does nothing, since the rest of the load would replace the refreshed data.
- A `Refresh` event logger that throws (any `Throwable`) no longer undoes the refresh. The new data stays installed,
  so assignments the handler read from it can't outlive it, and the error is ignored rather than sent back to that
  logger as an `Error` event, as for `Publish` and as in JS, which swallows logger errors. 1.1.0 instead restored
  the previous data and sent an `Error` event for an `Exception`, and let an `\Error` escape `refresh()`; code that
  relied on that must catch errors inside its `Refresh` handler. A refresh that fails to load or install data still
  keeps the previous data and sends `Error`.

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
  behind a key even when the unit was not in it: on a strict audience mismatch, or, for an experiment that is not
  full-on, with no unit for its unit type (exposed as `assigned`), recording units in an experiment they never saw and returning its control value. With
  shared keys, the experiment picked could also be one the unit was not in while another one it was in was
  ignored. *Affects:* anyone with strict audiences or experiments on a unit type a context may lack, and anyone
  running two or more experiments whose variants define the same variable key. The rule now applied, as in the
  Java SDK:
  - The candidates are the experiments whose variants (any variant) define the key, by ascending experiment id.
  - The winner is the first candidate the unit is assigned to (including outside the traffic split, and for a
    full-on experiment even without its unit), overridden into or rule-assigned to. An override or a matching
    assignment rule makes a candidate win even on a strict audience mismatch or without its unit. If there is no
    winner, the caller default is returned and nothing is exposed.
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
