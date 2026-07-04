<?php

namespace Ekumanov\EdgeCache;

use Flarum\Foundation\Config;
use Flarum\Frontend\Document;
use Flarum\Http\RequestUtil;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PROTOTYPE — makes the server-rendered discussion content paint immediately.
 *
 * On /d/* the LCP element is the first post's text, but the SPA paints it only
 * after forum.js downloads + evaluates + boots (the render-delay tail). Core
 * ALREADY renders the title + posts server-side into the document content —
 * it just hides it inside <noscript id="flarum-content">, so JS-enabled
 * browsers never paint it.
 *
 * This callback (priority 0 = after core's route content at 100) swaps the
 * document's content wrapper view for one that emits the same markup as a
 * VISIBLE block instead, plus a MutationObserver that removes the block in
 * the same rendering frame in which Mithril's first render fills #content.
 * The pre-paint block then paints at ≈FCP, and because the hydrated first
 * post is the same text at the same width/typography (equal, not larger,
 * paint), Chrome keeps the EARLY paint as the LCP: LCP collapses to ≈FCP.
 *
 * Guests only for now: a member's hydrated page differs from the guest render
 * (read markers, controls, member-only content), so pre-painting the guest
 * markup would show them briefly-wrong content. Guest pages are also exactly
 * the cohort served cookieless/edge-cached (the GSC-flagged 580 URLs).
 *
 * LCP/CLS contract (do not break):
 * - The pre-paint block must be equal-or-larger than the app's first-post
 *   render: same text, same .Post-body typography (flat selector in
 *   forum.css), same effective column width, no truncation/clipping.
 * - Removal happens on the first childList mutation of #content — a
 *   microtask, which runs before the browser paints that frame — so the
 *   swap is atomic: no duplicate content is ever painted, no gap frame.
 * - Removed elements remain LCP candidates (Chrome ≥88), so removing the
 *   block does not reset LCP.
 */
class PrePaintDiscussion
{
    public function __construct(
        protected Config $config,
    ) {
    }

    public function __invoke(Document $document, Request $request): void
    {
        if (! RequestUtil::getActor($request)->isGuest()) {
            return;
        }

        $path = ForumPath::relative($request->getUri()->getPath(), $this->config->url()->getPath());

        if (! str_starts_with($path, '/d/')) {
            return;
        }

        // Core's Discussion content (priority 100) must have produced the
        // server-side render; bail on anything else (404 handler, etc.).
        if (empty($document->content)) {
            return;
        }

        $document->contentView = 'ekumanov-edge-cache::prepaint';
        $document->head[] = '<style>'.$this->css().'</style>';
    }

    /**
     * Styles mapping core's noscript markup (.container > h1 + article >
     * .PostUser + .Post-body) onto the geometry of the hydrated
     * DiscussionPage, phone-first (the field-LCP cohort is mobile).
     *
     * .Post-body and .PostUser-name are flat selectors in forum.css, so the
     * post text already gets the app's exact typography (14px/1.7, 1em p
     * margins) for free; what's left is the hero band, post spacing, and
     * hiding the boot loader (core's inline script force-shows it).
     */
    private function css(): string
    {
        return <<<'CSS'
#edge-prepaint ~ #flarum-loading{display:none !important}
#edge-prepaint h1{background:var(--hero-bg);color:var(--contrast-color, var(--hero-color));text-align:center;font-size:16px;font-weight:normal;line-height:1.5em;margin:0 -15px;padding:30px 15px}
#edge-prepaint article{padding:12px 0 0}
#edge-prepaint .PostUser{display:block;min-height:32px;padding-top:4px}
#edge-prepaint hr{border:0;border-top:1px solid var(--control-bg);margin:12px 0 0}
#edge-prepaint > .container > div > a{display:inline-block;margin:10px 0;font-weight:600}
@media (min-width:768px){#edge-prepaint h1{font-size:22px;padding:50px 15px 40px;margin:0 -15px}}
CSS;
    }
}
