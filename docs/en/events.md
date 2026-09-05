# Events

## Purpose

**Events** is a first-class campaign settings section for browser-side engagement and real-user performance measurements. Each collector has its own **Off/On** switch. AmareloTDS injects only enabled collectors and only into the root `index.html` or `index.php` of each step. Nested pages, iframes, and other landing files do not receive trackers.

![Events settings](../assets/screenshots/events-settings-overview.png)

## Scroll Depth

Enable scroll tracking and add up to 32 page-depth thresholds as whole percentages from 1 to 100. Each threshold appears as a removable tag; press Enter or comma after a number to add it. For example, the `50` threshold produces the `scroll_50` event when the visitor first reaches half of the page.

AmareloTDS keeps the first occurrence for each clickid and step. The stored value is the elapsed time in milliseconds from tracker initialization on that step. Repeated crossings of the same threshold do not replace it.

## Visible Time

Enable visible-time tracking and add up to 32 whole-second thresholds from 1 to 86400. Each threshold appears as a removable tag; press Enter or comma after a number to add it. For example, the `60` threshold produces `stay_60s` after the page has been visible for 60 seconds.

The timer measures visible page time rather than merely keeping a timer running in a hidden tab. As with scroll events, AmareloTDS stores the elapsed milliseconds of the first occurrence for each clickid and step.

## Performance Measurement

**Measure landing performance** enables Real User Monitoring (RUM) through browser performance APIs. AmareloTDS collects at most one performance sample for each clickid and step. The settings use a compact switch; hover or focus its information icon for the metric names. A sample can contain:

| Metric | Meaning | Unit |
| --- | --- | --- |
| **LCP** | Largest Contentful Paint: when the largest visible content element finishes rendering | milliseconds |
| **INP** | Interaction to Next Paint: how quickly the page responds to a user interaction | milliseconds |
| **CLS** | Cumulative Layout Shift: how much visible content unexpectedly moves | unitless score |
| **TTFB** | Time to First Byte: time until the first response byte arrives | milliseconds |
| **FCP** | First Contentful Paint: when the first visible content is rendered | milliseconds |

The collector keeps the latest available values and sends one snapshot 10 seconds after the document started. If a regular link navigation or form submission starts earlier, AmareloTDS starts a keepalive delivery immediately without delaying or replacing the browser's native navigation. The document becoming `hidden` is an emergency fallback for Back, closing the tab, or entering another address.

Metric availability depends on browser support and what happens during the visit. The packet contains only values actually received by send time. For example, a visit with no interaction may have no INP; AmareloTDS leaves it missing rather than substituting zero. Enabled performance metrics become available for campaign reporting but are not added to existing reports automatically.

Performance is measured only in the root index of an HTML/PHP landing into which AmareloTDS can inject the collector. Internal pages and documents inside iframes are deliberately not measured. It is unavailable for external redirects and not injected by JS Connect, where navigation timings would describe the host document rather than a AmareloTDS-delivered landing. PHP Connect and regular HTML delivery are supported.

## Offer Revealed

**Track offer revealed** records `offer_revealed` when the offer block actually becomes visible. It does not read a VSL player, playback speed, or a configured pitch timestamp. Instead, it observes the rendered page, so a delay script that adjusts itself to 1.05x or another playback speed is followed automatically.

Mark the offer container with `data-ytds-offer`, for example `<div class="delay-hidden" data-ytds-offer>`. The attribute may be placed on any element whose visibility represents the reveal. Explicit markers have priority and may be used more than once. For compatibility with common VSL pages, the collector also accepts exactly one unmarked `.delay-hidden` element. If there are multiple unmarked `.delay-hidden` elements, it does not guess. The Events screen reports each landing as explicit, automatic, ambiguous, or missing before you save.

Visibility is checked on initial render, DOM/class/style changes, resize, and CSS transition or animation completion. An offer already visible when tracking starts is recorded immediately. An element hidden by itself or an ancestor is not recorded until it becomes visible.

## Checkout Click

**Track checkout click** records `checkout_click` from a real, non-cancelled click on either a resolved `{link:N}` checkout URL used by the rendered landing or an element marked `data-ytds-checkout`. Hash fragments are ignored when comparing URLs, and delegated listeners also cover CTA elements added later by page scripts.

Use `{link:N}` when the CTA is managed by Destinations or Checkout Routes. Add `data-ytds-checkout` to a button or link when the destination is literal, assembled by JavaScript, or otherwise cannot be derived from a rendered `{link:N}`. This event measures click intent on the landing; it does not prove that CartPanda or another checkout loaded successfully.

## Custom Events

The **Custom events** list is an allowlist of up to 64 names. Add each event name that the campaign is allowed to accept. The **Copy** button on the right of each row copies a ready-to-use call with the current name, for example:

```javascript
ytdsEvent('cta_click');
```

Calls for names outside the campaign allowlist are rejected. Custom events remain available when either standard helper is enabled. A custom entry may even use `offer_revealed` or `checkout_click`; the effective allowlist is deduplicated, and first-write-wins storage prevents the standard collector and a manual call from counting twice for the same clickid and step. AmareloTDS stores only the first occurrence for each clickid and step, as elapsed milliseconds from tracker initialization on that step.

## Interpreting Event Values

Scroll, visible-time, standard helper, and custom event values answer “how long after tracking started on this step did the event first happen?” A lower value means the first occurrence happened earlier; an absent value means the event was not recorded for that clickid and step. Every request also carries the exact landing variant recorded for the step; a mismatched variant is rejected.

Performance values are the browser measurements themselves: timing metrics are expressed in milliseconds, while CLS is a unitless stability score. Missing performance values remain missing so that they do not distort reporting.
