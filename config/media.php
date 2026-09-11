<?php

use App\Enums\QueueName;

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
        'extraction' => QueueName::MediaExtraction->value,
        'download' => QueueName::MediaDownload->value,
        'processing' => QueueName::MediaProcessing->value,
        'image_analysis' => QueueName::ImageAnalysis->value,
        'video_processing' => QueueName::VideoProcessing->value,
        'optimization' => QueueName::MediaOptimization->value,
        'selection' => QueueName::MediaSelection->value,
        'publishing' => QueueName::TelegramPublishing->value,
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

        // A clear server-side default. Source fetch_options.headers can
        // override this per source when an upstream explicitly requires a
        // different User-Agent; it cannot alter HTTP safety limits.
        'user_agent' => env(
            'HTTP_FETCH_USER_AGENT',
            'imvnn-news-fetcher/1.0',
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
    | Image roles — what an image IS, not how good it looks
    |--------------------------------------------------------------------------
    |
    | Quality scoring ranks pictures against each other; it cannot tell an
    | author's avatar from the article's photograph, because an avatar is a
    | genuinely high-quality image. Observed on a Hugging Face article: ten of
    | sixteen candidates were contributor avatars scoring 0.57-0.69 against a
    | 0.35 quality floor, out-ranking the article's own figures at 0.61.
    |
    | Substring matching, case-insensitive. URL markers are matched against the
    | path and query only, never the host — `avatar_hosts` covers hostnames, so
    | a stray "profile" in a query parameter cannot condemn a real photo.
    |
    | See App\Services\Media\Scoring\ImageRoleClassifier.
    */
    'image_roles' => [
        // Dedicated avatar CDNs. Decisive on their own: these hostnames serve
        // nothing but profile pictures.
        'avatar_hosts' => [
            'cdn-avatars.huggingface.co',
            'avatars.githubusercontent.com',
            'secure.gravatar.com',
            'gravatar.com',
            'pbs.twimg.com/profile_images',
        ],

        'avatar_url_markers' => [
            'avatar', 'gravatar', 'profile-pic', 'profile_pic', 'profile-photo',
            'profile_photo', 'profilephoto', 'headshot', 'userpic', 'user-pic',
            '/author/', '/authors/', '/byline', '/contributor', '/people/',
        ],

        // Alt text is written for screen readers, so it names the subject
        // plainly: "Alejo Lopez Avila's avatar", "Photo of Jane Doe".
        'avatar_alt_markers' => [
            'avatar', 'profile photo', 'profile picture', 'headshot',
            'portrait of', 'photo of ', 'picture of ',
        ],

        'chrome_url_markers' => [
            'logo', 'wordmark', 'favicon', '/icons/', 'icon-', '-icon',
            '_icon', 'sprite', 'badge', 'spacer', 'placeholder',
            'social-', 'share-', 'button', 'arrow', 'chevron', 'bullet',
        ],

        'chrome_alt_markers' => [
            'logo', 'icon', 'wordmark',
        ],

        'promo_url_markers' => [
            '/ads/', '/ad-', 'advert', 'sponsor', 'promo', 'banner',
            'newsletter', 'subscribe', 'cta-',
        ],

        // A square image this small or smaller is an avatar or an icon in
        // practically every case: article photography and charts are landscape
        // or portrait, and a square illustration worth publishing is bigger
        // than a profile thumbnail. This is the backstop that catches avatar
        // CDNs nobody has added a pattern for yet — 96x96 author headshots and
        // 200x200 contributor pictures both land here.
        'square_icon_max_edge' => 400,
        'square_tolerance' => 0.12,

        // Tracking and spacer images.
        'pixel_max_edge' => 2,
    ],

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
    | Optional: AiCaptionComposer writes the actual post text — a short,
    | strictly Uzbek summary in simple language. A source link is appended by
    | the application to both AI and plain captions. Disabled automatically if
    | AI_API_KEY is empty. Any provider failure falls back to
    | PlainCaptionComposer for that post rather than failing the job — see
    | FallbackCaptionComposer.
    |
    | Cost bounded the same way as the news-analysis AI call (per-call
    | only, no cross-request budget tracking) — see
    | docs/NEWS_FETCHING.md "Token/cost limits".
    */
    'telegram_caption' => [
        // Languages each post is written in, in order. Drives the AI-provider
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

        // When the AI provider can't produce a caption, the only fallback is the
        // article's own words — i.e. the source's language, usually English.
        // Off by default: for a channel that publishes in specific languages,
        // not posting beats posting untranslated. Turn on only if getting
        // something out matters more than the language it's in.
        'fallback_to_original_language' => env('TELEGRAM_CAPTION_FALLBACK_ORIGINAL', false),

        'ai_enabled' => env('AI_CAPTION_ENABLED', env('GEMINI_CAPTION_ENABLED', true)),
        'max_output_tokens' => env('AI_CAPTION_MAX_OUTPUT_TOKENS', env('GEMINI_CAPTION_MAX_OUTPUT_TOKENS', 400)),
        'max_input_chars' => env('AI_CAPTION_MAX_INPUT_CHARS', env('GEMINI_CAPTION_MAX_INPUT_CHARS', 4000)),
        'timeout_seconds' => env('AI_CAPTION_TIMEOUT_SECONDS', env('GEMINI_CAPTION_TIMEOUT_SECONDS', 20)),
    ],
];
