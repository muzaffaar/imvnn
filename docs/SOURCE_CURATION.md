# Source Curation

Validated on 10 September 2026 with the same server-style HTTP behavior the
application uses: browser User-Agent, redirects enabled, bounded response,
and a representative article request where applicable. A source is only added
when its discovery surface and article pages are both usable by the backend.

| Source | Discovery surface | Observed publishing shape | Decision |
|---|---|---|---|
| Google DeepMind News | `https://deepmind.google/blog/rss.xml` | RSS feed (100 entries observed); individual article returned HTML 200 | Enabled as RSS |
| AWS Machine Learning Blog | `https://aws.amazon.com/blogs/machine-learning/feed/` | RSS feed (20 entries observed); individual article returned HTML 200 | Enabled as RSS |
| NVIDIA AI Blog | `https://blogs.nvidia.com/blog/tag/artificial-intelligence/feed/` | Tag-filtered RSS; representative article returned HTML 200 | Enabled as RSS |
| Meta AI Blog | `https://ai.meta.com/blog/` | Static listing cards with absolute `/blog/...` links; representative article returned HTML 200 | Enabled as HTML crawl with XPath and URL allowlist |
| Cohere Blog | `https://cohere.com/blog/` | Static listing cards with relative `/blog/...` links; representative article returned HTML 200 | Enabled as HTML crawl with XPath and URL allowlist |
| Stability AI News | `https://stability.ai/news-updates` | Static listing with `/news-updates/...` links; representative article returned HTML 200 | Enabled as HTML crawl with XPath and URL allowlist |
| OpenAI News | `https://openai.com/news/rss.xml` | Valid RSS, but a representative article request returned HTTP 403 and the current feed entry did not contain a usable article summary | Not enabled; revalidate before adding |

These are official publisher sources, which makes them strong for announcements,
research, model releases and platform changes. They are not substitutes for
independent reporting.

## Independent journalism sources (added September 2026)

Added for broader economy/policy-relevant coverage than the vendor blogs
above, once the AI analysis prompt was retargeted for a Ministry of Economy
and Finance audience (see `AiArticleAnalyzer`). Validated the same way: feed
returns 200 with an RSS/Atom content type, and a representative article link
from the feed also returns 200.

| Source | Discovery surface | Observed publishing shape | Decision |
|---|---|---|---|
| TechCrunch — AI | `https://techcrunch.com/category/artificial-intelligence/feed/` | Category-specific RSS feed; representative article returned HTTP 200 | Enabled as RSS |
| Ars Technica — AI | `https://arstechnica.com/ai/feed/` | Category-specific RSS feed (`atom:link rel="self"` confirms canonical); representative article returned HTTP 200 | Enabled as RSS |
| The Verge — AI | `https://www.theverge.com/rss/ai-artificial-intelligence/index.xml` | Section-specific Atom feed (narrower than the site-wide `theverge.com/rss/index.xml`); representative article returned HTTP 200 | Enabled as RSS |
| Financial Times — AI | `https://www.ft.com/artificial-intelligence?format=rss` | Topic-specific RSS feed with title + one-sentence description per item; article pages return HTTP 200 but are subscription-paywalled beyond the opening paragraph | Enabled as RSS — feed text carries enough signal for headline-level coverage even though full article bodies are not accessible |
| Reuters — Technology/AI | `https://www.reuters.com/technology/artificial-intelligence` | No public RSS feed (discontinued); direct page fetch returns HTTP 401 behind a DataDome bot-challenge even with a browser User-Agent, and guessed REST/RSS endpoints 404/401 | Not enabled — no usable discovery surface for this backend's plain HTTP fetcher |

## Retired sources (September 2026)

`news.mit.edu` (all three feeds: robotics, AI topic, and the all-news feed)
and `technologyreview.com`'s AI topic feed were removed in favor of the
independent journalism sources above, which carry more economy/policy-
relevant coverage for this channel's Ministry of Economy and Finance
audience. Removing a source from `config/news_sources.php` and re-running
`php artisan news-sources:sync` also deactivates (`is_active = false`) its
row in the `sources` table — see `SyncNewsSourcesCommand` — so a retired
source stops being fetched rather than continuing to run unconfigured.

Revalidate sources after a publisher redesign, sustained job failure, or at
least quarterly. A 200 response alone is not enough: confirm the listing still
contains article links, a recent item has a current publication date, and the
article body parser returns a meaningful title and content.
