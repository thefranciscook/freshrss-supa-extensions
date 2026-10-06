# Minimal UI

A distraction-free layout for FreshRSS. It's designed for the Origine theme and the reader view; mockups are in `docs/minimal ui/`.

- **Opens in the reader view.** Enabling the extension sets FreshRSS's default view (Settings → Reading → Default view) to the reader view, once. Pick another view there and it sticks. Disabling the extension puts back the view you had before, unless you've changed it since.
- **No header bar.** The search box, refresh and settings (gear) move to the top of the sidebar.
- **The toolbar folds into the sidebar.** Mark as read and the view switcher live there too. The top bar keeps only the ☰ menu button on the left, with filter and sort on the right. On phones (≤ 840px), filter and sort also go into the sidebar.
- **One filter menu.** The read, unread, starred and non-starred toggles share a single dropdown with your user queries, so the user-query shortcut still works.
- **Cleaner articles.** The icon rows above and below each article are hidden. Like a Reddit feed, each title has a line above it with the feed's icon, its category (click to open that category) and the date as "x ago"; hover the date to see it in full. Long feed names are cut off with an ellipsis, tags are faded, and a line separates articles.
- **Easier-to-read article text:** 17px text with 1.6 line spacing (16px on phones), a full line between paragraphs, brighter links with a faint underline, bigger headings inside articles with more space above them, and images that fit the column.
- **Fonts:** articles use the device's own system font, the same one Reddit and many Substacks use (SF Pro on Apple devices, Segoe UI on Windows, Roboto on Android). The rest of the interface uses Fira Sans (bundled). There's also `#131314` as the dark background and 1.5× side padding on phones.
- **A soft colour glow at the top of the page** that changes with the day of the week (Sun violet, Mon slate, Tue sage, Wed clay, Thu teal, Fri wine, Sat olive).

The colours only apply in dark mode (Display → dark mode "auto", with a dark OS theme). The layout changes apply in both modes.

## How it works

| File | Role |
|---|---|
| `extension.php` | Sets the reader view as the default view once and remembers the previous one (`reader_default` in the extension's user settings), so disabling the extension can restore it. |
| `static/minimal.css` | Most of the work. It targets reading pages with `body:has(#aside_feed.aside_feed)`. |
| `static/minimal.js` | Moves existing elements around instead of copying them, so FreshRSS's handlers, ids and shortcuts keep working. Also renders relative dates (including articles loaded later) and sets `data-mui-day` on `<html>`. |
| `static/fonts.css`, `fonts/`, `Controllers/MinimalUIController.php` | FreshRSS sends `Content-Security-Policy: default-src 'self'`, and `ext.php` won't serve `.woff2` files. So the fonts are served by `?c=MinimalUI&a=font&f=…`. Fira Sans is under the OFL (`fonts/OFL.txt`). |

Icons such as the ☰ menu and the filter lines are drawn with CSS gradients, because the CSP also blocks `data:` images.
