<?php

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
        // ['name' => 'Example Labs Blog', 'slug' => 'example-labs-blog', 'fetch_type' => 'html_crawl', 'url' => 'https://example.com/blog', 'reliability_score' => 90, 'media_reuse_permitted' => true],
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
        'fetch' => 'news-fetch',
        'parse' => 'news-parse',
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
    ],

    // How often the scheduler polls every active source (see routes/console.php).
    'fetch_interval_minutes' => 30,

    /*
    |--------------------------------------------------------------------------
    | Gemini article analysis
    |--------------------------------------------------------------------------
    |
    | Optional: one Gemini call per candidate article both cleans up its
    | title/content AND judges AI-relevance, replacing the free heuristic
    | path (ArticleContentExtractor + keyword AiRelevanceFilter) — see
    | GeminiArticleAnalyzer and docs/NEWS_FETCHING.md "Gemini analysis".
    |
    | Disabled automatically if GEMINI_API_KEY is empty, regardless of
    | `enabled` below. When enabled, any Gemini failure (network error,
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
    |     Gemini (~4 chars/token, so 12000 chars is roughly 3000 input
    |     tokens) — bounds cost on the input side regardless of article length.
    */
    'gemini' => [
        'enabled' => env('GEMINI_ANALYSIS_ENABLED', true),
        'max_output_tokens' => env('GEMINI_MAX_OUTPUT_TOKENS', 500),
        'max_input_chars' => env('GEMINI_MAX_INPUT_CHARS', 12000),
        'timeout_seconds' => env('GEMINI_TIMEOUT_SECONDS', 20),
    ],
];
