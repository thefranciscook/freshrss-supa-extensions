# Reading Rhythm

Makes the stream more varied, so it holds your attention better. It does three things to every page of the normal and reader views:

- **Caps bursty feeds.** A feed shows at most *N* posts per day (default 3). The first posts it published that day are the ones shown. Posts over the cap are hidden from the stream but stay unread. You can still see them by opening the feed itself, and "mark all as read" covers them.
- **Mixes categories and feeds.** Posts from one category or one feed don't run in long stretches; they're spread across the page.
- **Spaces out image posts.** Posts with an image or video are placed evenly across the page, so the next one is never far away.

Settings (gear icon next to the extension): the daily cap (0 = off) and on/off switches for mixing and for image spacing.

## Scope

- Only applies to the normal and reader views sorted by date (received or published), whatever the direction. It doesn't change other sorts (title, feed name…).
- Opening a single feed shows all of its posts, in order.
- It reorders one page at a time, so paging and "load more" work as usual, with no gaps or repeats. Bigger pages give more room to mix: 40–60 posts per page (Settings → Reading) works well.
- Only affects the web UI. Mobile apps using the API get the normal order and all posts.
- The "Published today — …" dividers are hidden on reordered pages, because they'd repeat between every other post.

## How it works

| File | Role |
|---|---|
| `extension.php` | FreshRSS has no hook over the list of entries, only per entry. So on `index/normal` and `index/reader`, the `ActionExecute` hook runs the action itself and wraps the view's two callbacks. `callbackBeforeEntries` buffers the page and drops posts over the cap. If that leaves the page short, it loads the following posts (up to 5 more batches) until the page is full, then reorders it. The extra "next page" entry the core loads stays last, because the core discards it and pages from it. `callbackBeforePagination` still reports a next page when hidden posts made the page shorter. |
| `lib/Scheduler.php` | The reordering, in pure PHP. It fills the page slot by slot. For each slot it picks the candidate with the lowest cost: the cost keeps the original order roughly, penalises repeating a recent feed or category, favours feeds and categories behind their fair share of the page, and keeps image posts at an even gap. |
| `tests/scheduler_test.php` | Scheduler checks, including 300 random pages scored against the best possible arrangement: `docker compose exec freshrss php extensions/xExtension-ReadingRhythm/tests/scheduler_test.php` |

The cap is decided per (feed, local day) with one small query: the first *N* posts by publish date, read or not. So the same posts always make the cut, and new arrivals never replace posts you've already seen.

Because the `ActionExecute` hook returns `false` after running the action, other extensions' `ActionExecute` hooks don't run for those two actions.
