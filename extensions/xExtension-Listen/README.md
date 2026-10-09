# Listen

Press the headphones button and FreshRSS reads the current view aloud, like a podcast queue: the articles in feed order, each in a different AI voice (OpenAI), marked read when finished.

- **No picking.** Open a view (main stream, a category, a feed), press play. It starts from the first article on screen (or the open one) and keeps going, loading more articles when it reaches the end of the page.
- **Only articles worth listening to.** Full text only: articles with a “Read more” / “Continue reading” link or a WordPress `[…]` excerpt are skipped, and so are short ones (fewer than 3 paragraphs of 25+ words, or under 250 words) and picture posts. Captions, code, tables, footnote marks and the WordPress “The post … appeared first on …” line aren't read. Articles that qualify get a 🎧 badge; click it to start there.
- **A voice per article**, from OpenAI's 11, never the same twice in a row. The same article keeps its voice.
- **Works in the list and the reader view.** In the reader view (or with an article opened in the list) it reads along: the paragraphs being read are highlighted and scrolled to. Scroll away to read something else and the page stays put; scroll back to the highlight and it follows again.
- **Player bar** at the bottom: previous / play-pause / next, title and feed, speed (0.8×–2×), stop. Lock-screen and headphone controls work through the Media Session API. The player says “AI voice”, as OpenAI's usage policy asks.
- **Marks read** when an article has been read to the end (can be turned off).

## Cost

About $0.015 per minute of audio with `gpt-4o-mini-tts`, so roughly **$0.90 per hour of listening** (a little more when pieces have to be generated again). Only what you listen to is generated, plus the next piece; skipping wastes at most one piece. Generated audio is cached on the server, so going back or replaying is free. A **daily cap** (default $2, estimated from the characters sent) stops playback when reached.

## Reliability

`gpt-4o-mini-tts` sometimes drops the last sentences of a request, and gets unstable (long silences, repeats) on inputs longer than about a minute and a half of speech. So:

- Articles are read in **short pieces** of at most ~700 characters (about 45 seconds), cut between paragraphs; the first piece is just the title, so playback starts right away.
- Each piece is **generated in full and checked before it's played**: its length is compared with how long that voice usually takes for that much text. Audio that comes out much shorter (cut off) or much longer (silence, repeats) is generated again, up to 3 tries; the closest one is kept. The usual pace of each voice is learnt as you listen (`pace.json` in the cache folder). Retries are logged in FreshRSS's log.
- **One generation per piece**, however many requests ask for it at once (a prefetch and the player, or a browser's range requests).
- **One audio element** plays everything, so two voices can never overlap. A watchdog restarts a piece that stalls, and skips the article if it stalls again, instead of going quiet.
- A short silent clip is played on your tap, so phones allow the page to keep playing the following pieces.

## Setup

The OpenAI API key stays on the server. Either:

- **Environment variable (preferred):** set `OPENAI_API_KEY` for the FreshRSS container, e.g. in `docker-compose.yml` under `environment:`, and recreate the container. Locally: `OPENAI_API_KEY=sk-… make up`.
- **Settings:** paste it in the extension's settings (stored in the user's FreshRSS configuration).

Optional: `LISTEN_OPENAI_BASE_URL` points it at another OpenAI-compatible speech server instead of `https://api.openai.com/v1`.

## How it works

| File | Role |
|---|---|
| `lib/Article.php` | Turns an article's HTML into readable blocks, decides whether it's worth listening to, and cuts the text into pieces: a short first one (≤ 300 characters, usually just the title), then ≤ 700 characters, between paragraphs or sentences. |
| `Controllers/ListenController.php` | `plan` (POST): which of the given articles qualify and how many pieces each has. `audio` (GET, with the CSRF token in the URL so other sites can't spend your money): one piece as a complete MP3, from the cache or generated and checked first (one generation at a time per piece). `status`: key set? cap reached? `silence`: the unlocking clip. |
| `lib/Speech.php` | The `POST /audio/speech` request (model, voice, speaking style). |
| `lib/Mp3.php`, `lib/Pace.php` | The audio's length (by walking the MP3 frames), and each voice's usual pace, to catch cut-off or padded audio. |
| `lib/AudioCache.php` | MP3s on disk under `data/cache/listen/<user>/` (deleted after 7 days unplayed, on feed refresh), the per-piece locks and today's spending. |
| `static/listen.js` | The player: one `<audio>` element; the next piece is requested while the current one plays, so it's ready when needed. Read-along finds each piece's paragraph by its first words (sent by `plan`). |

Tests: `php tests/article_test.php` and `php tests/quality_test.php` (inside the container: `docker compose exec freshrss php extensions/xExtension-Listen/tests/…`).
