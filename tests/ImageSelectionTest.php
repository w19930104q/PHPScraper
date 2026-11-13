<?php

namespace Spekulatius\PHPScraper\Tests;

use PHPUnit\Framework\TestCase;
use Spekulatius\PHPScraper\PHPScraper;

/**
 * Tests for the responsive image selection (`selectImages`) used for offline
 * mirroring. All tests are offline: content is provided via `setContent`.
 */
class ImageSelectionTest extends TestCase
{
    /**
     * Builds a scraper with the given HTML snippet wrapped in a document.
     */
    protected function scraperWith(string $body, string $url = 'https://example.com/page.html', string $head = ''): PHPScraper
    {
        $web = new PHPScraper;
        $web->setContent(
            $url,
            "<!DOCTYPE html><html><head>{$head}</head><body>{$body}</body></html>"
        );

        return $web;
    }

    /**
     * @test
     */
    public function test_x_descriptors_select_smallest_meeting_dpr(): void
    {
        $web = $this->scraperWith(
            '<img src="fallback.png" srcset="a-1x.png 1x, a-2x.png 2x, a-3x.png 3x">'
        );

        $result = $web->selectImages(1280, 2.0);

        // The 2x candidate is the smallest meeting the target density.
        $this->assertSame('https://example.com/a-2x.png', $result[0]['selected']);

        // All candidates are reported, in document order.
        $this->assertCount(3, $result[0]['candidates']);
        $this->assertSame('https://example.com/a-1x.png', $result[0]['candidates'][0]['url']);
        $this->assertSame('https://example.com/a-2x.png', $result[0]['candidates'][1]['url']);
        $this->assertSame('https://example.com/a-3x.png', $result[0]['candidates'][2]['url']);

        // Selection flags and elimination reasons.
        $this->assertFalse($result[0]['candidates'][0]['selected']);
        $this->assertTrue($result[0]['candidates'][1]['selected']);
        $this->assertFalse($result[0]['candidates'][2]['selected']);

        $this->assertSame('density 1.00x is below the target 2.00x.', $result[0]['candidates'][0]['reason']);
        $this->assertNull($result[0]['candidates'][1]['reason']);
        $this->assertSame('density 3.00x exceeds the required 2.00x for the target 2.00x.', $result[0]['candidates'][2]['reason']);

        // No diagnostics for a clean srcset.
        $this->assertSame([], $result[0]['diagnostics']);
    }

    /**
     * @test
     */
    public function test_x_descriptors_fall_back_to_largest(): void
    {
        $web = $this->scraperWith(
            '<img srcset="a-1x.png 1x, a-2x.png 2x">'
        );

        // Nothing meets 3x: the largest candidate wins.
        $result = $web->selectImages(1280, 3.0);

        $this->assertSame('https://example.com/a-2x.png', $result[0]['selected']);
        $this->assertTrue($result[0]['candidates'][1]['selected']);
        $this->assertSame('density 1.00x is below the target 3.00x.', $result[0]['candidates'][0]['reason']);
    }

    /**
     * @test
     */
    public function test_w_descriptors_with_sizes(): void
    {
        $web = $this->scraperWith(
            '<img srcset="small.png 400w, medium.png 800w, large.png 1600w" sizes="(min-width: 800px) 50vw, 100vw">'
        );

        // Viewport 1000px: sizes -> 50vw = 500px slot.
        // Densities: 0.8, 1.6, 3.2 -> medium is the smallest meeting 1.0.
        $result = $web->selectImages(1000, 1.0);
        $this->assertSame('https://example.com/medium.png', $result[0]['selected']);
        $this->assertSame([], $result[0]['diagnostics']);

        // Viewport 600px: sizes -> 100vw = 600px slot.
        // Densities: 0.67, 1.33, 2.67 -> medium still the smallest meeting 1.0.
        $result = $web->selectImages(600, 1.0);
        $this->assertSame('https://example.com/medium.png', $result[0]['selected']);

        // Viewport 600px with DPR 2: densities 0.67, 1.33, 2.67 -> large.
        $result = $web->selectImages(600, 2.0);
        $this->assertSame('https://example.com/large.png', $result[0]['selected']);
    }

    /**
     * @test
     */
    public function test_missing_sizes_defaults_to_100vw(): void
    {
        $web = $this->scraperWith(
            '<img srcset="a.png 500w, b.png 1000w">'
        );

        // Viewport 500px, no sizes -> slot = 100vw = 500px.
        // Densities: 1.0, 2.0 -> a.png meets 1.0 exactly.
        $result = $web->selectImages(500, 1.0);

        $this->assertSame('https://example.com/a.png', $result[0]['selected']);
        $this->assertSame([], $result[0]['diagnostics']);
    }

    /**
     * @test
     */
    public function test_picture_source_type_and_media_selection(): void
    {
        $web = $this->scraperWith(
            '<picture>' .
            '<source type="image/avif" srcset="img.avif 1x, img@2x.avif 2x">' .
            '<source type="image/webp" media="(min-width: 600px)" srcset="img.webp 1x, img@2x.webp 2x">' .
            '<img src="img.jpg" srcset="img@2x.jpg 2x">' .
            '</picture>'
        );

        // AVIF unsupported -> skipped; WebP matches type and media -> selected.
        $result = $web->selectImages(1024, 2.0, ['image/webp', 'image/jpeg']);

        $this->assertSame('https://example.com/img@2x.webp', $result[0]['selected']);
        $this->assertSame('source #1 srcset', $result[0]['candidates'][0]['origin']);
        $this->assertSame(
            ['<source> #0 skipped: unsupported type "image/avif".'],
            $result[0]['diagnostics']
        );

        // Narrow viewport: the WebP media query doesn't match -> img srcset.
        $result = $web->selectImages(500, 2.0, ['image/webp', 'image/jpeg']);

        $this->assertSame('https://example.com/img@2x.jpg', $result[0]['selected']);
        $this->assertSame('img srcset', $result[0]['candidates'][0]['origin']);
        $this->assertContains(
            '<source> #1 skipped: media "(min-width: 600px)" does not match.',
            $result[0]['diagnostics']
        );

        // No supported types at all -> the img's own srcset is used.
        $result = $web->selectImages(1024, 1.0, ['image/jpeg']);

        $this->assertSame('https://example.com/img@2x.jpg', $result[0]['selected']);
        $this->assertSame('img srcset', $result[0]['candidates'][0]['origin']);
    }

    /**
     * @test
     */
    public function test_media_with_and_conjunction(): void
    {
        $web = $this->scraperWith(
            '<picture>' .
            '<source media="(min-width: 600px) and (max-width: 900px)" srcset="mid.png">' .
            '<img src="base.png">' .
            '</picture>'
        );

        // Within the range.
        $result = $web->selectImages(700, 1.0);
        $this->assertSame('https://example.com/mid.png', $result[0]['selected']);

        // Outside the range (both ends).
        $result = $web->selectImages(500, 1.0);
        $this->assertSame('https://example.com/base.png', $result[0]['selected']);

        $result = $web->selectImages(1000, 1.0);
        $this->assertSame('https://example.com/base.png', $result[0]['selected']);
    }

    /**
     * @test
     */
    public function test_unknown_media_expression_is_diagnosed_and_falls_back(): void
    {
        $web = $this->scraperWith(
            '<picture>' .
            '<source media="(min-width: 40em)" srcset="wide.png">' .
            '<img src="base.png">' .
            '</picture>'
        );

        // `em` units are unsupported: diagnosed, treated as matching.
        $result = $web->selectImages(1024, 1.0);

        $this->assertSame('https://example.com/wide.png', $result[0]['selected']);
        $this->assertSame(
            ['media "(min-width: 40em)" contains unsupported syntax; treating it as matching (fallback).'],
            $result[0]['diagnostics']
        );
    }

    /**
     * @test
     */
    public function test_unknown_sizes_expression_is_diagnosed_and_falls_back(): void
    {
        $web = $this->scraperWith(
            '<img srcset="a.png 500w, b.png 1000w" sizes="calc(50vw - 10px)">'
        );

        // calc() is unsupported: diagnosed, falls back to 100vw = 500px slot.
        $result = $web->selectImages(500, 1.0);

        $this->assertSame('https://example.com/a.png', $result[0]['selected']);
        $this->assertSame(
            ['sizes length "calc(50vw - 10px)" is not supported (px and vw only); falling back to 100vw.'],
            $result[0]['diagnostics']
        );
    }

    /**
     * @test
     */
    public function test_data_url_commas_are_preserved(): void
    {
        $dataUrl = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

        $web = $this->scraperWith(
            '<img srcset="' . $dataUrl . ' 1x, other.png 2x">'
        );

        $result = $web->selectImages(1280, 1.0);

        // The data-URL candidate is selected and passed through unchanged.
        $this->assertSame($dataUrl, $result[0]['selected']);
        $this->assertSame($dataUrl, $result[0]['candidates'][0]['url']);
        $this->assertSame([], $result[0]['diagnostics']);
    }

    /**
     * @test
     */
    public function test_bad_descriptors_are_isolated(): void
    {
        $web = $this->scraperWith(
            '<img srcset="good-1x.png 1x, bad.png 400h, good-2x.png 2x, broken.png 10q, good-3x.png 3x">'
        );

        $result = $web->selectImages(1280, 2.0);

        // The invalid candidates are dropped, the rest is unaffected.
        $this->assertSame('https://example.com/good-2x.png', $result[0]['selected']);
        $this->assertCount(3, $result[0]['candidates']);
        $this->assertSame(
            [
                'srcset candidate "bad.png" dropped: invalid descriptor "400h".',
                'srcset candidate "broken.png" dropped: invalid descriptor "10q".',
            ],
            $result[0]['diagnostics']
        );
    }

    /**
     * @test
     */
    public function test_mixed_descriptors(): void
    {
        // Mixing `w` and `x` is invalid per spec, but handled gracefully:
        // `w` candidates use the slot width, `x` candidates their density.
        $web = $this->scraperWith(
            '<img srcset="wide.png 800w, dense.png 2x" sizes="400px">'
        );

        // Slot = 400px -> wide.png has density 2.0, same as dense.png.
        // Tie -> document order decides.
        $result = $web->selectImages(1280, 2.0);

        $this->assertSame('https://example.com/wide.png', $result[0]['selected']);
        $this->assertSame(2.0, $result[0]['candidates'][0]['density']);
        $this->assertSame(2.0, $result[0]['candidates'][1]['density']);
        $this->assertSame(
            'same density as the selected candidate, but later in the document order.',
            $result[0]['candidates'][1]['reason']
        );
    }

    /**
     * @test
     */
    public function test_missing_src_and_empty_srcset(): void
    {
        $web = $this->scraperWith(
            '<img alt="nothing here">' .
            '<img src="" srcset="  ">'
        );

        $result = $web->selectImages(1280, 1.0);

        // No src, no srcset: no selection, diagnosed.
        $this->assertNull($result[0]['selected']);
        $this->assertSame([], $result[0]['candidates']);
        $this->assertSame(
            ['no src or usable srcset; no image could be selected.'],
            $result[0]['diagnostics']
        );

        // Empty src and empty srcset: no selection either.
        $this->assertNull($result[1]['selected']);
        $this->assertSame([], $result[1]['candidates']);
    }

    /**
     * @test
     */
    public function test_srcset_falls_back_to_src_when_unusable(): void
    {
        $web = $this->scraperWith(
            '<img src="fallback.png" srcset="broken.png 10q">'
        );

        $result = $web->selectImages(1280, 1.0);

        // The only srcset candidate is invalid -> src is used.
        $this->assertSame('https://example.com/fallback.png', $result[0]['selected']);
        $this->assertSame('img src', $result[0]['candidates'][0]['origin']);
        $this->assertSame(
            [
                'srcset candidate "broken.png" dropped: invalid descriptor "10q".',
                'srcset produced no usable candidates; falling back to src.',
            ],
            $result[0]['diagnostics']
        );
    }

    /**
     * @test
     */
    public function test_relative_urls_resolve_against_page_url(): void
    {
        $web = $this->scraperWith(
            '<img srcset="images/a.png 1x, /assets/b.png 2x">',
            'https://example.com/sub/dir/page.html'
        );

        $result = $web->selectImages(1280, 1.0);

        // Relative URLs resolve against the full page URL (including the path).
        $this->assertSame('https://example.com/sub/dir/images/a.png', $result[0]['candidates'][0]['url']);
        $this->assertSame('https://example.com/assets/b.png', $result[0]['candidates'][1]['url']);
    }

    /**
     * @test
     */
    public function test_relative_urls_resolve_against_base_href(): void
    {
        $web = $this->scraperWith(
            '<img srcset="images/a.png 1x">',
            'https://example.com/sub/page.html',
            '<base href="https://cdn.example.com/assets/">'
        );

        $result = $web->selectImages(1280, 1.0);

        // The base href takes precedence over the page URL.
        $this->assertSame('https://cdn.example.com/assets/images/a.png', $result[0]['selected']);
    }

    /**
     * @test
     */
    public function test_device_switching(): void
    {
        $html = '<picture>' .
            '<source media="(min-width: 1000px)" srcset="desktop.png 1x, desktop@2x.png 2x">' .
            '<img src="mobile.png" srcset="mobile.png 1x, mobile@2x.png 2x" sizes="100vw">' .
            '</picture>';

        $web = $this->scraperWith($html);

        // Desktop device: wide viewport, standard density.
        $desktop = $web->selectImages(1280, 1.0);
        $this->assertSame('https://example.com/desktop.png', $desktop[0]['selected']);

        // Mobile device: narrow viewport, high density.
        $mobile = $web->selectImages(360, 2.0);
        $this->assertSame('https://example.com/mobile@2x.png', $mobile[0]['selected']);
    }

    /**
     * @test
     */
    public function test_repeated_calls_are_stable_and_do_not_modify_the_dom(): void
    {
        $web = $this->scraperWith(
            '<img src="a.png" srcset="a.png 1x, a@2x.png 2x" alt="test" width="100">'
        );

        $first = $web->selectImages(1280, 2.0);
        $second = $web->selectImages(1280, 2.0);

        // Repeated calls with the same input return identical results.
        $this->assertSame($first, $second);

        // The DOM is untouched: the classic APIs still see the original page.
        $this->assertSame(['https://example.com/a.png'], $web->images);
        $this->assertSame(
            [
                [
                    'url' => 'https://example.com/a.png',
                    'alt' => 'test',
                    'width' => '100',
                    'height' => null,
                ],
            ],
            $web->imagesWithDetails
        );
    }

    /**
     * @test
     */
    public function test_multiple_images_are_processed_independently(): void
    {
        $web = $this->scraperWith(
            '<img src="one.png">' .
            '<picture><source type="image/webp" srcset="two.webp"><img src="two.png"></picture>'
        );

        $result = $web->selectImages(1280, 1.0, ['image/png']);

        $this->assertCount(2, $result);
        $this->assertSame(0, $result[0]['index']);
        $this->assertSame(1, $result[1]['index']);
        $this->assertSame('https://example.com/one.png', $result[0]['selected']);
        // WebP unsupported -> the img src is used.
        $this->assertSame('https://example.com/two.png', $result[1]['selected']);
    }
}
