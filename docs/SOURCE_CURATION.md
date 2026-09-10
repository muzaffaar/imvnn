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
independent reporting. The existing MIT and MIT Technology Review sources
continue to provide independent/editorial coverage.

Revalidate sources after a publisher redesign, sustained job failure, or at
least quarterly. A 200 response alone is not enough: confirm the listing still
contains article links, a recent item has a current publication date, and the
article body parser returns a meaningful title and content.
