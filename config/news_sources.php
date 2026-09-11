<?php

use App\Enums\QueueName;

return [

    /*
    |--------------------------------------------------------------------------
    | Sources
    |--------------------------------------------------------------------------
    |
    | *** Paste your ~10 source links here. ***
    |
    | `fetch_type: 'rss'` — `url` is a feed URL (e.g. https://example.com/feed).
    | The feed is polled directly; no separate crawl step is needed.
    |
    | `fetch_type: 'html_crawl'` — `url` is a page (homepage, section/category
    | page) to scan for article links. Each discovered link is then visited
    | individually to fetch and parse the actual article.
    |
    | Optional `fetch_options` makes a difficult HTML source configurable
    | without changing PHP. Supported keys:
    | - allowed_hosts: additional exact hosts permitted for article/pagination URLs
    | - include_url_patterns / exclude_url_patterns: case-sensitive `*` globs
    |   matched against full URLs
    | - excluded_path_segments: extra lowercase path segments to reject
    | - article_link_xpath: XPath selecting only desired <a> elements
    | - next_page_xpath and max_pages (1-10): bounded listing pagination
    | - max_links (1-100): candidate cap for this source
    | - skip_prefilter: only for trusted AI-only sources whose anchor text
    |   does not reliably contain an AI keyword
    | - use_feed_content_when_article_unavailable: RSS only; use a feed's
    |   dated summary when a verified source blocks its article page
    | - headers: source-specific HTTP headers, e.g. ['User-Agent' => '...'];
    |   these override defaults only and cannot change TLS, timeouts,
    |   redirect policy, or byte caps
    |
    | Run `php artisan news-sources:sync` after editing this file to write
    | these into the `sources` table (upserted by `slug`, so editing an
    | existing entry and re-running is safe).
    |
    | `reliability_score` (0-100) and `media_reuse_permitted` feed directly
    | into the media pipeline's quality scoring and copyright/reuse policy —
    | see docs/MEDIA_ARCHITECTURE.md.
    */
    'sources' => [
        ['name' => 'MIT News — Robotics', 'slug' => 'mit-news-robotics', 'fetch_type' => 'rss', 'url' => 'https://news.mit.edu/topic/mitrobotics-rss.xml', 'reliability_score' => 90, 'media_reuse_permitted' => false],
        ['name' => 'Google Blog', 'slug' => 'google-blog', 'fetch_type' => 'rss', 'url' => 'https://blog.google/rss/', 'reliability_score' => 85, 'media_reuse_permitted' => false],

        // Anthropic has no discoverable RSS feed — crawled instead.
        ['name' => 'Anthropic News', 'slug' => 'anthropic-news', 'fetch_type' => 'html_crawl', 'url' => 'https://www.anthropic.com/news', 'reliability_score' => 95, 'media_reuse_permitted' => false],
        ['name' => 'Hugging Face Blog', 'slug' => 'huggingface-blog', 'fetch_type' => 'rss', 'url' => 'https://huggingface.co/blog/feed.xml', 'reliability_score' => 80, 'media_reuse_permitted' => false],
        ['name' => 'Mistral AI News', 'slug' => 'mistral-ai-news', 'fetch_type' => 'rss', 'url' => 'https://mistral.ai/rss.xml', 'reliability_score' => 90, 'media_reuse_permitted' => false],
        ['name' => 'MIT News — Artificial Intelligence', 'slug' => 'mit-news-ai', 'fetch_type' => 'rss', 'url' => 'https://news.mit.edu/rss/topic/artificial-intelligence2', 'reliability_score' => 90, 'media_reuse_permitted' => false],
        ['name' => 'MIT News — All', 'slug' => 'mit-news-all', 'fetch_type' => 'rss', 'url' => 'https://news.mit.edu/rss/feed', 'reliability_score' => 90, 'media_reuse_permitted' => false],
        ['name' => 'Google Research Blog', 'slug' => 'google-research-blog', 'fetch_type' => 'rss', 'url' => 'https://research.google/blog/rss/', 'reliability_score' => 90, 'media_reuse_permitted' => false],
        ['name' => 'MIT Technology Review — AI', 'slug' => 'mit-technology-review-ai', 'fetch_type' => 'rss', 'url' => 'https://www.technologyreview.com/topic/artificial-intelligence/feed/', 'reliability_score' => 85, 'media_reuse_permitted' => false],
        // Official primary sources, verified September 2026. Prefer feeds
        // whenever available: they are cheaper, carry dates, and avoid brittle
        // browser-like crawling of each vendor's listing page.
        ['name' => 'Google DeepMind News', 'slug' => 'google-deepmind-news', 'fetch_type' => 'rss', 'url' => 'https://deepmind.google/blog/rss.xml', 'reliability_score' => 95, 'media_reuse_permitted' => false,
            // Headlines such as "AlphaGenome Atlas" need article-page analysis
            // even when the short RSS entry does not literally say "AI".
            'fetch_options' => ['skip_prefilter' => true],
        ],
        ['name' => 'AWS Machine Learning Blog', 'slug' => 'aws-machine-learning-blog', 'fetch_type' => 'rss', 'url' => 'https://aws.amazon.com/blogs/machine-learning/feed/', 'reliability_score' => 85, 'media_reuse_permitted' => false,
            'fetch_options' => ['skip_prefilter' => true],
        ],
        ['name' => 'NVIDIA AI Blog', 'slug' => 'nvidia-ai-blog', 'fetch_type' => 'rss', 'url' => 'https://blogs.nvidia.com/blog/tag/artificial-intelligence/feed/', 'reliability_score' => 85, 'media_reuse_permitted' => false,
            'fetch_options' => ['skip_prefilter' => true],
        ],
        ['name' => 'Meta AI Blog', 'slug' => 'meta-ai-blog', 'fetch_type' => 'html_crawl', 'url' => 'https://ai.meta.com/blog/', 'reliability_score' => 90, 'media_reuse_permitted' => false,
            'fetch_options' => [
                // Meta's server-rendered page currently has no <main> node;
                // include_url_patterns below keeps this broader selector
                // restricted to canonical Meta AI article URLs.
                'article_link_xpath' => '//a[contains(@href, "/blog/")]',
                'include_url_patterns' => ['https://ai.meta.com/blog/*'],
                'skip_prefilter' => true,
            ],
        ],
        ['name' => 'Cohere Blog', 'slug' => 'cohere-blog', 'fetch_type' => 'html_crawl', 'url' => 'https://cohere.com/blog/', 'reliability_score' => 85, 'media_reuse_permitted' => false,
            'fetch_options' => [
                'article_link_xpath' => '//main//a[contains(@href, "/blog/")]',
                'include_url_patterns' => ['https://cohere.com/blog/*'],
                'skip_prefilter' => true,
            ],
        ],
        ['name' => 'Stability AI News', 'slug' => 'stability-ai-news', 'fetch_type' => 'html_crawl', 'url' => 'https://stability.ai/news-updates', 'reliability_score' => 80, 'media_reuse_permitted' => false,
            'fetch_options' => [
                'article_link_xpath' => '//main//a[contains(@href, "/news-updates/")]',
                'include_url_patterns' => ['https://stability.ai/news-updates/*'],
                'skip_prefilter' => true,
            ],
        ],
        // ['name' => 'Example Labs Blog', 'slug' => 'example-labs-blog', 'fetch_type' => 'html_crawl', 'url' => 'https://example.com/blog/', 'reliability_score' => 90, 'media_reuse_permitted' => true,
        //     'fetch_options' => [
        //         'article_link_xpath' => '//main//article//a[@href]',
        //         'include_url_patterns' => ['https://example.com/blog/*'],
        //         'exclude_url_patterns' => ['*/tag/*'],
        //         'next_page_xpath' => '//a[@rel="next"]',
        //         'max_pages' => 3,
        //         'max_links' => 40,
        //     ],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | AI relevance keyword filter
    |--------------------------------------------------------------------------
    |
    | Case-insensitive, word-boundary matched against an article's title +
    | summary/content (see AiRelevanceFilter). A candidate needs only one
    | match to be considered AI-related. Tune freely — this list is not
    | exhaustive, just a reasonable starting point.
    */
    'ai_keywords' => [
        'ai',
        'artificial intelligence', 'machine learning', 'deep learning', 'neural network',
        'large language model', 'llm', 'llms', 'generative ai', 'genai',
        'chatbot', 'foundation model', 'multimodal model', 'transformer model',
        'ai model', 'ai agent', 'ai system', 'ai startup', 'ai chip', 'ai regulation',
        'gpt', 'chatgpt', 'openai', 'anthropic', 'claude', 'gemini', 'copilot',
        'midjourney', 'stable diffusion', 'hugging face', 'deepmind', 'nvidia ai',
    ],

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    */
    'queues' => [
        'fetch' => QueueName::NewsFetch->value,
        'parse' => QueueName::NewsParse->value,
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        'max_feed_bytes' => 5 * 1024 * 1024,
        'max_page_bytes' => 5 * 1024 * 1024,
        'max_links_per_crawl' => 20,
        'download_timeout_seconds' => 15,
        'download_connect_timeout_seconds' => 5,

        // Some real feeds carry a large historical backlog (confirmed:
        // huggingface.co/blog/feed.xml has 860 items, research.google's has
        // 100) — capped per fetch so the first sync of a new source doesn't
        // trigger hundreds of AI calls in one burst. Feeds list newest
        // first, so this deliberately means old backlog items already past
        // the cap at first sync are never retroactively processed — the goal
        // is to catch ongoing new content, not backfill a blog's full history.
        'max_items_per_feed_fetch' => 20,
    ],

    // How often the scheduler polls every active source (see routes/console.php).
    'fetch_interval_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Freshness — only today's news
    |--------------------------------------------------------------------------
    |
    | Feeds carry far more history than they look like they do: the newest 20
    | items of a low-volume company blog can still stretch back months, which
    | is how a May article once got posted in September. With `only_today`
    | on, an article is ingested and published only on the calendar day it
    | was published, in the audience's timezone — anything not posted before
    | midnight is abandoned rather than carried over. Articles whose date
    | can't be determined at all count as not fresh.
    |
    | See App\Services\News\FreshnessPolicy.
    */
    'freshness' => [
        'only_today' => env('NEWS_ONLY_TODAY', true),
        'timezone' => env('NEWS_DAY_TIMEZONE', 'Asia/Tashkent'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI article analysis
    |--------------------------------------------------------------------------
    |
    | Optional: one AI-provider call per candidate article both cleans up its
    | title/content AND judges AI-relevance, replacing the free heuristic
    | path (ArticleContentExtractor + keyword AiRelevanceFilter) — see
    | AiArticleAnalyzer and docs/NEWS_FETCHING.md "AI analysis".
    |
    | Disabled automatically if AI_API_KEY is empty, regardless of
    | `enabled` below. When enabled, any provider failure (network error,
    | rate limit, quota, malformed response) falls back to the heuristic
    | path for that candidate rather than failing the job — see
    | FallbackArticleAnalyzer.
    |
    | Cost control, both per the "per-call output cap + input truncation"
    | approach (no cross-request budget tracking):
    |   - `max_output_tokens` caps generationConfig.maxOutputTokens on every
    |     call — the response is just {is_ai_related, title, content}, so
    |     this can stay small.
    |   - `max_input_chars` truncates the plain-text article body sent to
    |     the model (~4 chars/token, so 12000 chars is roughly 3000 input
    |     tokens) — bounds cost on the input side regardless of article length.
    */
    'ai' => [
        'enabled' => env('AI_ANALYSIS_ENABLED', env('GEMINI_ANALYSIS_ENABLED', true)),
        'max_output_tokens' => env('AI_ANALYSIS_MAX_OUTPUT_TOKENS', env('GEMINI_MAX_OUTPUT_TOKENS', 500)),
        'max_input_chars' => env('AI_ANALYSIS_MAX_INPUT_CHARS', env('GEMINI_MAX_INPUT_CHARS', 12000)),
        'timeout_seconds' => env('AI_ANALYSIS_TIMEOUT_SECONDS', env('GEMINI_TIMEOUT_SECONDS', 20)),
    ],
];
