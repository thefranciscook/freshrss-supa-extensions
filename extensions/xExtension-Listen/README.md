# Listen

Press the headphones button and FreshRSS reads the current view aloud, like a podcast queue: the articles in feed order, each in a different AI voice (OpenAI), marked read when finished.

- **No picking.** Open a view (main stream, a category, a feed), press play. It starts from the first article on screen (or the open one) and keeps going, loading more articles when it reaches the end of the page.
- **Only articles worth listening to.** Full text only: articles with a “Read more” / “Continue reading” link or a WordPress `[…]` excerpt are skipped, and so are short ones (fewer than 3 paragraphs of 25+ words, or under 250 words) and picture posts. Captions, code, tables, footnote marks and the WordPress “The post … appeared first on …” line aren't read. Articles that qualify get a 🎧 badge; click it to start there.
- **A voice per article**, from OpenAI's 11, never the same twice in a row. The same article keeps its voice.
- **Player bar** at the bottom: previous / play-pause / next, title and feed, speed (0.8×–2×), stop. Lock-screen and headphone controls work through the Media Session API. The player says “AI voice”, as OpenAI's usage policy asks.
- **Marks read** when an article has been read to the end (can be turned off).

## Cost

About $0.015 per minute of audio with `gpt-4o-mini-tts`, so roughly **$0.90 per hour of listening**. Only what you listen to is generated, plus the next piece; skipping wastes at most one piece. Generated audio is cached on the server, so going back or replaying is free. A **daily cap** (default $2, estimated from the characters sent) stops playback when reached.

## Setup

The OpenAI API key stays on the server. Either:

- **Environment variable (preferred):** set `OPENAI_API_KEY` for the FreshRSS container, e.g. in `docker-compose.yml` under `environment:`, and recreate the container. Locally: `OPENAI_API_KEY=sk-… make up`.
- **Settings:** paste it in the extension's settings (stored in the user's FreshRSS configuration).

Optional: `LISTEN_OPENAI_BASE_URL` points it at another OpenAI-compatible speech server instead of `https://api.openai.com/v1`.

If a reverse proxy sits in front of FreshRSS, it should pass responses through without buffering (nginx honours the `X-Accel-Buffering: no` header the extension sends; Caddy and Traefik stream by default), or playback waits for whole pieces.

## How it works

| File | Role |
|---|---|
| `lib/Article.php` | Turns an article's HTML into readable blocks, decides whether it's worth listening to, and cuts the text into pieces: a short first one (≤ 600 characters) so playback starts within a second or two, then ≤ 1500 characters, between paragraphs or sentences. |
| `Controllers/ListenController.php` | `plan` (POST): which of the given articles qualify and how many pieces each has. `audio` (GET, with the CSRF token in the URL so other sites can't spend your money): one piece as MP3, from the cache or streamed from OpenAI as it's generated. `status`: key set? cap reached? |
| `lib/Speech.php` | The streamed `POST /audio/speech` request (model, voice, speaking style). |
| `lib/AudioCache.php` | MP3s on disk under `data/cache/listen/<user>/` (deleted after 7 days unplayed, on feed refresh) and today's spending. |
| `static/listen.js` | The player: two `<audio>` elements take turns, so the next piece loads while the current one plays. |

Tests: `php tests/article_test.php` (inside the container: `docker compose exec freshrss php extensions/xExtension-Listen/tests/article_test.php`).
