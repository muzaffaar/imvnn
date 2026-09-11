<?php

namespace App\Enums;

/**
 * What a candidate image actually *is* on the page, as distinct from how good
 * it looks. Quality scoring ranks pictures against each other; this says
 * whether something is a picture of the story at all.
 *
 * The distinction is load-bearing. A 200x200 author avatar is a sharp,
 * well-compressed, perfectly Telegram-compatible image, so every quality
 * signal rates it highly — it scored 0.69 against a 0.35 floor while the
 * article's own figures scored 0.61. No amount of weight-tuning fixes that,
 * because the avatar is not low-quality. It is the wrong subject.
 */
enum ImageRole: string
{
    /** A genuine article illustration: photo, figure, chart, diagram, screenshot. */
    case Content = 'content';

    /** A person's profile picture: author byline, commenter, contributor list. */
    case Avatar = 'avatar';

    /** Site furniture: logo, wordmark, UI icon, sprite, badge, social button. */
    case Chrome = 'chrome';

    /** A 1x1 or near-zero tracking/spacer image. */
    case Pixel = 'pixel';

    /** Ad creative, newsletter promo, or sponsor unit. */
    case Promo = 'promo';

    public function isPublishable(): bool
    {
        return $this === self::Content;
    }
}
