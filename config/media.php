<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Media bytes live in object storage, never in PostgreSQL. Point 'disk' at
    | any Laravel filesystem disk backed by an S3-compatible driver: AWS S3,
    | Cloudflare R2, MinIO, or Backblaze B2 all work unmodified.
    */
    'disk' => env('MEDIA_DISK', 's3'),

    /*
    |--------------------------------------------------------------------------
    | Queues
    |--------------------------------------------------------------------------
    |
    | Every stage of the pipeline gets its own named queue so worker pools
    | (see docs/MEDIA_ARCHITECTURE.md "Queues & concurrency") can be sized
    | and isolated independently — e.g. `php artisan queue:work --queue=...`
    | per pool, kept separate at the process level.
    | Nothing here shares a queue with the app's default HTTP-triggered jobs.
    */
    'queues' => [
        'extraction' => 'media-extraction',
        'download' => 'media-download',
        'processing' => 'media-processing',
        'image_analysis' => 'image-analysis',
        'video_processing' => 'video-processing',
        'optimization' => 'media-optimization',
        'selection' => 'media-selection',
        'publishing' => 'telegram-publishing',
    ],

    /*
    |--------------------------------------------------------------------------
    | Ingestion limits
    |--------------------------------------------------------------------------
    */
    'limits' => [
        // Anything above this is referenced externally, never downloaded — see
        // MediaIngestionPolicy and docs/MEDIA_ARCHITECTURE.md "Download decision".
        'max_image_download_bytes' => env('MEDIA_MAX_IMAGE_BYTES', 15 * 1024 * 1024),
        'max_video_download_bytes' => env('MEDIA_MAX_VIDEO_BYTES', 200 * 1024 * 1024),
        'max_document_download_bytes' => env('MEDIA_MAX_DOCUMENT_BYTES', 25 * 1024 * 1024),

        'min_image_width' => 200,
        'min_image_height' => 200,

        'download_timeout_seconds' => 20,
        'download_connect_timeout_seconds' => 5,

        // A self-identifying bot UA gets flat-out 403'd by several real
        // sites with no real anti-bot challenge behind it — see
        // BoundedHttpFetcher::browserHeaders(). Pin an exact Chrome version
        // rather than chasing "latest" — consistency matters more than
        // currency here.
        'user_agent' => env(
            'HTTP_FETCH_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ),

        'allowed_image_mime_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        'allowed_video_mime_types' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'allowed_document_mime_types' => ['application/pdf'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deduplication
    |--------------------------------------------------------------------------
    */
    'deduplication' => [
        // Hamming distance (out of 64 bits) below which two dHashes are considered
        // the same image. 0 = identical, 5 tolerates recompression/light resizing,
        // 10 starts risking false positives between genuinely different photos.
        'perceptual_hash_hamming_threshold' => 8,

        // Only run the (comparatively expensive) perceptual hash pass against
        // assets seen within this window — comparing against years of history
        // for every new image doesn't pay for itself.
        'perceptual_hash_lookback_days' => 30,

        // Semantic embedding similarity (Level 4) is opt-in per docs/MEDIA_ARCHITECTURE.md.
        'embedding_similarity_enabled' => env('MEDIA_EMBEDDING_DEDUP_ENABLED', false),
        'embedding_similarity_threshold' => 0.92,
    ],

    /*
    |--------------------------------------------------------------------------
    | Quality scoring weights
    |--------------------------------------------------------------------------
    |
    | Must sum to 1.0. See MediaQualityScorer and docs/MEDIA_ARCHITECTURE.md
    | "Quality formula" for the rationale behind each weight and the penalty terms.
    */
    'quality_weights' => [
        'relevance' => 0.25,
        'resolution' => 0.15,
        'visual_quality' => 0.15,
        'source_reliability' => 0.15,
        'telegram_compatibility' => 0.10,
        'uniqueness' => 0.10,
        'aspect_ratio' => 0.10,
    ],

    'ideal_aspect_ratio' => 1.91, // Telegram/OpenGraph landscape ratio (1200x630)

    /*
    |--------------------------------------------------------------------------
    | Relevance analysis
    |--------------------------------------------------------------------------
    */
    'relevance' => [
        // Below this, don't bother calling a vision/multimodal model — the image
        // is already disqualified on cheap signals (too small, banner-shaped ad, etc).
        'min_score_for_ai_analysis' => 0.35,

        // Only send the top N surviving candidates per news item to a multimodal
        // model — never the full extracted set.
        'max_candidates_for_ai_analysis' => 3,

        'keyword_overlap_weight' => 0.4,
        'position_weight' => 0.3,
        'extractor_source_weight' => 0.3,
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram compatibility
    |--------------------------------------------------------------------------
    | https://core.telegram.org/bots/api#sendphoto / #sendvideo
    */
    'telegram' => [
        'max_photo_bytes' => 10 * 1024 * 1024,
        'max_video_bytes' => 50 * 1024 * 1024,
        'max_media_group_items' => 10,
        'photo_max_dimension_sum' => 10000, // width + height
        'photo_min_aspect_ratio' => 0.2,
        'photo_max_aspect_ratio' => 20,
    ],

    /*
    |--------------------------------------------------------------------------
    | Video
    |--------------------------------------------------------------------------
    */
    'video' => [
        'ffmpeg_binary' => env('FFMPEG_BINARY', 'ffmpeg'),
        'ffprobe_binary' => env('FFPROBE_BINARY', 'ffprobe'),
        'process_timeout_seconds' => 600,

        // Providers we only ever reference (never download bytes for), per
        // docs/MEDIA_ARCHITECTURE.md "Video download decision" and platform ToS.
        'reference_only_providers' => ['youtube', 'twitter', 'x', 'instagram', 'tiktok', 'facebook'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telegram caption generation
    |--------------------------------------------------------------------------
    |
    | Optional: GeminiCaptionComposer writes the actual post text — a short,
    | strictly Uzbek summary in whatever tone fits the story,
    | no links — instead of the free PlainCaptionComposer (title + excerpt,
    | original language, no translation). Disabled automatically if
    | GEMINI_API_KEY is empty. Any Gemini failure falls back to
    | PlainCaptionComposer for that post rather than failing the job — see
    | FallbackCaptionComposer.
    |
    | Cost bounded the same way as the news-analysis Gemini call (per-call
    | only, no cross-request budget tracking) — see
    | docs/NEWS_FETCHING.md "Token/cost limits".
    */
    'telegram_caption' => [
        // Timezone the post's date/time header is rendered in — the channel's
        // audience's local time, not the app's UTC storage timezone.
        'display_timezone' => env('TELEGRAM_DISPLAY_TIMEZONE', 'Asia/Tashkent'),

        // Languages each post is written in, in order. Drives the Gemini
        // response schema and the assembled caption, so adding or removing a
        // language is a config change rather than a code change. `flag` is
        // optional and only worth setting when there's more than one language
        // to tell apart. Note each extra language competes for the same
        // 1024-character caption budget (see CaptionBudget).
        // `script` is a Unicode script name used to verify the model actually
        // answered in the requested language rather than drifting to English
        // (see CaptionText::matchesScript).
        'languages' => [
            ['key' => 'uzbek', 'name' => 'Uzbek', 'flag' => null, 'script' => 'Latin'],
        ],

        // One short witty line reacting to the story, under the summary.
        'humor_line' => env('TELEGRAM_CAPTION_HUMOR', true),

        // When Gemini can't produce a caption, the only fallback is the
        // article's own words — i.e. the source's language, usually English.
        // Off by default: for a channel that publishes in specific languages,
        // not posting beats posting untranslated. Turn on only if getting
        // something out matters more than the language it's in.
        'fallback_to_original_language' => env('TELEGRAM_CAPTION_FALLBACK_ORIGINAL', false),

        'gemini_enabled' => env('GEMINI_CAPTION_ENABLED', true),
        'max_output_tokens' => env('GEMINI_CAPTION_MAX_OUTPUT_TOKENS', 400),
        'max_input_chars' => env('GEMINI_CAPTION_MAX_INPUT_CHARS', 4000),
        'timeout_seconds' => env('GEMINI_CAPTION_TIMEOUT_SECONDS', 20),
    ],
];
