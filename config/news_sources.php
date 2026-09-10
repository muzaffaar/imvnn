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
        // ['name' => 'Example AI Desk', 'slug' => 'example-ai-desk', 'fetch_type' => 'rss', 'url' => 'https://example.com/category/ai/feed', 'reliability_score' => 75, 'media_reuse_permitted' => false],
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
];
