<?php

namespace Spekulatius\PHPScraper;

/**
 * Responsive image selection for offline mirroring.
 *
 * Picks the best-suited URL for every `<img>` on the page - following the
 * `<picture>`/`<source>` order, `type` and `media` selection as well as
 * `srcset` (`w`/`x` descriptors) and `sizes` - for a given viewport width,
 * device pixel ratio (DPR) and set of supported MIME types.
 *
 * Nothing is downloaded and the DOM is never modified. The classic `images`
 * and `imagesWithDetails` methods are not affected by this trait.
 *
 * @phpstan-type ImageCandidate array{url: string|null, rawUrl: string, origin: string, descriptor: string|null, width: int|null, density: float, selected: bool, reason: string|null}
 * @phpstan-type ImageSelection array{index: int, src: string|null, selected: string|null, candidates: array<int, ImageCandidate>, diagnostics: array<string>}
 */
trait UsesImageSelection
{
    /**
     * Selects the best-suited image URL for every `<img>` on the page.
     *
     * The selection mirrors the browser behavior as closely as needed for
     * offline mirroring:
     *
     *  - `<source>` elements of a surrounding `<picture>` are evaluated in
     *    document order. The first source with a supported `type`, a matching
     *    `media` and a non-empty `srcset` wins.
     *  - `media` supports `min-width`/`max-width` in `px` combined with `and`.
     *    Unknown expressions are diagnosed and treated as matching (fallback).
     *  - `srcset` supports `w` and `x` descriptors. Candidates with invalid
     *    descriptors are isolated (dropped with a diagnostic). Commas within
     *    data-URLs are preserved.
     *  - `sizes` supports `px` and `vw` lengths. Missing `sizes` defaults to
     *    `100vw` as per the HTML standard. Unknown expressions are diagnosed
     *    and fall back to `100vw`.
     *  - From the remaining candidates the smallest one meeting the target
     *    density (DPR) is selected. If none qualifies, the largest one is
     *    selected. Equal densities are decided by document order.
     *  - Missing `src`/`sizes` are handled per HTML rules. Relative URLs are
     *    resolved against the `<base>` href or the full page URL.
     *
     * Each entry contains the selected (absolute) URL, all candidates with
     * their elimination reasons and any diagnostics collected along the way.
     *
     * @param  int  $viewportWidth  Viewport width in CSS pixels.
     * @param  float  $dpr  Device pixel ratio (1.0 = standard density).
     * @param  array<string>  $supportedMimeTypes  MIME types the target device supports. An empty list accepts all types.
     * @return array<int, ImageSelection>
     */
    public function selectImages(int $viewportWidth = 1280, float $dpr = 1.0, array $supportedMimeTypes = []): array
    {
        // Normalize the supported MIME types once for all comparisons.
        $supportedMimeTypes = array_map(
            fn (string $type): string => $this->normalizeMimeType($type),
            $supportedMimeTypes
        );

        $results = [];

        $index = 0;

        /** @var \DOMElement $image */
        foreach ($this->filter('//img') as $image) {
            $results[] = $this->selectImageForElement($image, $index++, $viewportWidth, $dpr, $supportedMimeTypes);
        }

        return $results;
    }

    /**
     * Runs the candidate collection and selection for a single `<img>`.
     *
     * @param  array<string>  $supportedMimeTypes
     * @return ImageSelection
     */
    protected function selectImageForElement(\DOMElement $image, int $index, int $viewportWidth, float $dpr, array $supportedMimeTypes): array
    {
        $diagnostics = [];

        // Collect the candidates: the first matching `<source>` of a surrounding
        // `<picture>` wins. Otherwise the `<img>` itself provides the candidates.
        $srcset = null;
        $sizes = null;
        $origin = null;

        $picture = $image->parentNode;
        if ($picture instanceof \DOMElement && strtolower($picture->tagName) === 'picture') {
            $sourceIndex = 0;

            foreach ($picture->childNodes as $child) {
                // Only `<source>` elements before the `<img>` are considered.
                if ($child === $image) {
                    break;
                }

                if (! $child instanceof \DOMElement || strtolower($child->tagName) !== 'source') {
                    continue;
                }

                // type: skip sources with an unsupported MIME type.
                $type = trim($child->getAttribute('type'));
                if ($type !== '' && $supportedMimeTypes !== [] && ! \in_array($this->normalizeMimeType($type), $supportedMimeTypes, true)) {
                    $diagnostics[] = \sprintf('<source> #%d skipped: unsupported type "%s".', $sourceIndex++, $type);

                    continue;
                }

                // media: skip sources with a non-matching media query.
                $media = trim($child->getAttribute('media'));
                if ($media !== '' && ! $this->matchesMedia($media, $viewportWidth, $diagnostics)) {
                    $diagnostics[] = \sprintf('<source> #%d skipped: media "%s" does not match.', $sourceIndex++, $media);

                    continue;
                }

                // srcset: sources without a srcset are skipped.
                $sourceSrcset = trim($child->getAttribute('srcset'));
                if ($sourceSrcset === '') {
                    $diagnostics[] = \sprintf('<source> #%d skipped: no srcset attribute.', $sourceIndex++);

                    continue;
                }

                // The first matching source wins.
                $srcset = $sourceSrcset;
                $sizes = $child->hasAttribute('sizes') ? $child->getAttribute('sizes') : null;
                $origin = \sprintf('source #%d srcset', $sourceIndex);

                break;
            }
        }

        // Fall back to the `<img>` srcset, if no `<source>` was selected.
        if ($srcset === null) {
            $imgSrcset = trim($image->getAttribute('srcset'));
            if ($imgSrcset !== '') {
                $srcset = $imgSrcset;
                $sizes = $image->hasAttribute('sizes') ? $image->getAttribute('sizes') : null;
                $origin = 'img srcset';
            }
        }

        // Parse the chosen srcset into candidates. Bad descriptors are isolated.
        $candidates = [];
        if ($srcset !== null) {
            $candidates = $this->parseSrcset($srcset, (string) $origin, $diagnostics);

            if ($candidates === []) {
                $diagnostics[] = 'srcset produced no usable candidates; falling back to src.';
            }
        }

        // Final fallback: the `src` attribute as a 1x candidate (HTML rules).
        $src = trim($image->getAttribute('src'));
        if ($candidates === [] && $src !== '') {
            $candidates[] = [
                'rawUrl' => $src,
                'descriptor' => null,
                'width' => null,
                'density' => 1.0,
                'origin' => 'img src',
            ];
        }

        // Width descriptors require the slot width from `sizes` to compute
        // the effective density of each candidate.
        $usesWidthDescriptors = false;
        foreach ($candidates as $candidate) {
            if ($candidate['width'] !== null) {
                $usesWidthDescriptors = true;

                break;
            }
        }

        if ($usesWidthDescriptors) {
            $slotWidth = $this->evaluateSizes($sizes, $viewportWidth, $diagnostics);

            if ($slotWidth <= 0.0) {
                $diagnostics[] = 'sizes evaluated to a zero or negative width; falling back to 100vw.';
                $slotWidth = (float) $viewportWidth;
            }

            foreach ($candidates as $i => $candidate) {
                if ($candidate['width'] !== null) {
                    $candidates[$i]['density'] = $candidate['width'] / $slotWidth;
                }
            }
        }

        // Select the smallest candidate meeting the target density. If none
        // qualifies, select the largest. Ties are decided by document order.
        $selectedIndex = null;
        foreach ($candidates as $i => $candidate) {
            if ($candidate['density'] >= $dpr && ($selectedIndex === null || $candidate['density'] < $candidates[$selectedIndex]['density'])) {
                $selectedIndex = $i;
            }
        }

        if ($selectedIndex === null) {
            foreach ($candidates as $i => $candidate) {
                if ($selectedIndex === null || $candidate['density'] > $candidates[$selectedIndex]['density']) {
                    $selectedIndex = $i;
                }
            }
        }

        // Resolve the URLs and prepare the candidate output with reasons.
        $selectedUrl = null;
        $preparedCandidates = [];

        foreach ($candidates as $i => $candidate) {
            $url = $this->resolveImageUrl($candidate['rawUrl']);
            $isSelected = ($i === $selectedIndex);

            if ($isSelected) {
                $selectedUrl = $url;
            }

            $preparedCandidates[] = [
                'url' => $url,
                'rawUrl' => $candidate['rawUrl'],
                'origin' => $candidate['origin'],
                'descriptor' => $candidate['descriptor'],
                'width' => $candidate['width'],
                'density' => $candidate['density'],
                'selected' => $isSelected,
                'reason' => ! $isSelected && $selectedIndex !== null
                    ? $this->eliminationReason($candidate, $candidates[$selectedIndex], $dpr)
                    : null,
            ];
        }

        if ($candidates === []) {
            $diagnostics[] = 'no src or usable srcset; no image could be selected.';
        }

        return [
            'index' => $index,
            'src' => $image->getAttribute('src') === '' ? null : $image->getAttribute('src'),
            'selected' => $selectedUrl,
            'candidates' => $preparedCandidates,
            'diagnostics' => $diagnostics,
        ];
    }

    /**
     * Parses a srcset attribute into candidates.
     *
     * Tokenizes on whitespace, so commas within data-URLs are preserved.
     * Candidates with invalid descriptors are isolated (dropped with a
     * diagnostic) without affecting the remaining candidates.
     *
     * @param  array<string>  $diagnostics
     * @return array<int, array{rawUrl: string, descriptor: string|null, width: int|null, density: float, origin: string}>
     */
    protected function parseSrcset(string $srcset, string $origin, array &$diagnostics): array
    {
        $candidates = [];

        $tokens = \preg_split('/\s+/', trim($srcset)) ?: [];
        $count = \count($tokens);
        $i = 0;

        while ($i < $count) {
            // A candidate starts with the URL. Commas only act as separators
            // at the start or end of a token - commas inside (data-URLs) stay.
            $url = ltrim($tokens[$i++], ',');
            $urlEndsCandidate = str_ends_with($url, ',');
            $url = rtrim($url, ',');

            if ($url === '') {
                continue;
            }

            // Collect the descriptors until a token ends with a comma.
            $descriptors = [];
            if (! $urlEndsCandidate) {
                while ($i < $count) {
                    $token = $tokens[$i++];
                    $endsCandidate = str_ends_with($token, ',');
                    $token = rtrim($token, ',');

                    if ($token !== '') {
                        $descriptors[] = $token;
                    }

                    if ($endsCandidate) {
                        break;
                    }
                }
            }

            // Validate the descriptors: none, a single `w` or a single `x`.
            if (\count($descriptors) > 1) {
                $diagnostics[] = \sprintf('srcset candidate "%s" dropped: multiple descriptors ("%s").', $url, implode(' ', $descriptors));

                continue;
            }

            $descriptor = $descriptors[0] ?? null;
            $width = null;
            $density = 1.0;

            if ($descriptor !== null) {
                if (\preg_match('/^([1-9]\d*)w$/', $descriptor, $matches) === 1) {
                    $width = (int) $matches[1];
                } elseif (\preg_match('/^(\d+(?:\.\d+)?)x$/', $descriptor, $matches) === 1 && (float) $matches[1] > 0) {
                    $density = (float) $matches[1];
                } else {
                    $diagnostics[] = \sprintf('srcset candidate "%s" dropped: invalid descriptor "%s".', $url, $descriptor);

                    continue;
                }
            }

            $candidates[] = [
                'rawUrl' => $url,
                'descriptor' => $descriptor,
                'width' => $width,
                'density' => $density,
                'origin' => $origin,
            ];
        }

        return $candidates;
    }

    /**
     * Evaluates a media query list against the viewport width.
     *
     * Supports `min-width`/`max-width` in `px` combined with `and`. Unknown
     * expressions are diagnosed and treated as matching (fallback).
     *
     * @param  array<string>  $diagnostics
     */
    protected function matchesMedia(string $media, int $viewportWidth, array &$diagnostics): bool
    {
        $unknown = false;

        foreach ($this->splitTopLevel($media) as $query) {
            $query = trim($query);

            if ($query === '') {
                continue;
            }

            $result = $this->evaluateMediaQuery($query, $viewportWidth);

            // A single matching query suffices (comma-separated list = OR).
            if ($result === true) {
                return true;
            }

            if ($result === null) {
                $unknown = true;
            }
        }

        // Fallback: unknown expressions are diagnosed and treated as matching.
        if ($unknown) {
            $diagnostics[] = \sprintf('media "%s" contains unsupported syntax; treating it as matching (fallback).', $media);

            return true;
        }

        return false;
    }

    /**
     * Evaluates a single media query. Returns null for unknown expressions.
     */
    protected function evaluateMediaQuery(string $query, int $viewportWidth): ?bool
    {
        $query = strtolower(trim($query));

        // `only` is a no-op for modern browsers.
        if (str_starts_with($query, 'only ')) {
            $query = trim(substr($query, 5));
        }

        // Split the query into its `and`-joined parts.
        $parts = \preg_split('/\s+and\s+/', $query) ?: [];

        foreach ($parts as $i => $part) {
            $part = trim($part);

            if ($part === '') {
                return null;
            }

            // The first part may be a media type.
            if ($i === 0 && ! str_starts_with($part, '(')) {
                if (\in_array($part, ['all', 'screen'], true)) {
                    continue;
                }

                // Known non-screen media types never match a screen viewport.
                if (\in_array($part, ['print', 'speech', 'tv', 'projection', 'tty', 'braille', 'embossed', 'handheld', 'aural'], true)) {
                    return false;
                }

                // Unknown media type or modifier (e.g. `not`).
                return null;
            }

            // Supported feature: (min-width: Npx) / (max-width: Npx).
            if (\preg_match('/^\(\s*(min|max)-width\s*:\s*(\d+(?:\.\d+)?)px\s*\)$/', $part, $matches) === 1) {
                $value = (float) $matches[2];
                $conditionMatches = $matches[1] === 'min'
                    ? $viewportWidth >= $value
                    : $viewportWidth <= $value;

                if (! $conditionMatches) {
                    return false;
                }

                continue;
            }

            // Unsupported feature, unit or syntax (e.g. `em`, ranges, `not`).
            return null;
        }

        return true;
    }

    /**
     * Evaluates a sizes attribute to the slot width in CSS pixels.
     *
     * Supports `px` and `vw` lengths. Missing sizes default to `100vw` as per
     * the HTML standard. Unknown expressions are diagnosed and fall back to
     * `100vw` as well.
     *
     * @param  array<string>  $diagnostics
     */
    protected function evaluateSizes(?string $sizes, int $viewportWidth, array &$diagnostics): float
    {
        // HTML default for a missing sizes attribute: 100vw.
        $fallback = (float) $viewportWidth;

        if ($sizes === null) {
            return $fallback;
        }

        if (trim($sizes) === '') {
            $diagnostics[] = 'empty sizes attribute; falling back to 100vw.';

            return $fallback;
        }

        foreach ($this->splitTopLevel($sizes) as $entry) {
            $entry = trim($entry);

            if ($entry === '') {
                continue;
            }

            // Split an optional leading media condition from the length.
            $condition = null;
            $length = $entry;

            if (str_starts_with($entry, '(')) {
                $close = strrpos($entry, ')');

                if ($close === false) {
                    $diagnostics[] = \sprintf('sizes entry "%s" is not parseable; falling back to 100vw.', $entry);

                    return $fallback;
                }

                $condition = substr($entry, 0, $close + 1);
                $length = trim(substr($entry, $close + 1));
            }

            // Evaluate the condition, if any.
            if ($condition !== null) {
                $result = $this->evaluateMediaQuery($condition, $viewportWidth);

                if ($result === null) {
                    $diagnostics[] = \sprintf('sizes condition "%s" contains unsupported syntax; falling back to 100vw.', $condition);

                    return $fallback;
                }

                if ($result === false) {
                    continue;
                }
            }

            // The first matching entry wins - parse its length.
            $parsed = $this->parseLength($length, $viewportWidth);

            if ($parsed === null) {
                $diagnostics[] = \sprintf('sizes length "%s" is not supported (px and vw only); falling back to 100vw.', $length);

                return $fallback;
            }

            return $parsed;
        }

        $diagnostics[] = 'no sizes entry matched; falling back to 100vw.';

        return $fallback;
    }

    /**
     * Parses a sizes length. Supports `px` and `vw` only.
     */
    protected function parseLength(string $length, int $viewportWidth): ?float
    {
        if (\preg_match('/^(\d+(?:\.\d+)?)px$/', $length, $matches) === 1) {
            return (float) $matches[1];
        }

        if (\preg_match('/^(\d+(?:\.\d+)?)vw$/', $length, $matches) === 1) {
            return (float) $matches[1] * $viewportWidth / 100;
        }

        return null;
    }

    /**
     * Splits a comma-separated list, ignoring commas within parentheses.
     *
     * @return array<string>
     */
    protected function splitTopLevel(string $value): array
    {
        $parts = [];
        $depth = 0;
        $current = '';

        foreach (str_split($value) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth = max(0, $depth - 1);
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = $current;
                $current = '';

                continue;
            }

            $current .= $char;
        }

        $parts[] = $current;

        return $parts;
    }

    /**
     * Builds the elimination reason for a non-selected candidate.
     *
     * @param  array{rawUrl: string, descriptor: string|null, width: int|null, density: float, origin: string}  $candidate
     * @param  array{rawUrl: string, descriptor: string|null, width: int|null, density: float, origin: string}  $selected
     */
    protected function eliminationReason(array $candidate, array $selected, float $dpr): string
    {
        if ($candidate['density'] < $dpr) {
            return \sprintf('density %.2fx is below the target %.2fx.', $candidate['density'], $dpr);
        }

        if ($candidate['density'] === $selected['density']) {
            return 'same density as the selected candidate, but later in the document order.';
        }

        return \sprintf('density %.2fx exceeds the required %.2fx for the target %.2fx.', $candidate['density'], $selected['density'], $dpr);
    }

    /**
     * Resolves a candidate URL against the `<base>` href or the full page URL.
     *
     * Absolute URLs - including data-URLs - are passed through unchanged to
     * avoid any normalization (e.g. of commas within data-URLs).
     */
    protected function resolveImageUrl(string $url): ?string
    {
        // Already absolute (has a scheme): keep as-is.
        if (\preg_match('/^[a-zA-Z][a-zA-Z0-9+.-]*:/', $url) === 1) {
            return $url;
        }

        return $this->makeUrlAbsolute($url, $this->baseHref() ?? $this->currentUrl());
    }

    /**
     * Normalizes a MIME type for comparison: lowercase, no parameters.
     */
    protected function normalizeMimeType(string $type): string
    {
        return strtolower(trim(explode(';', $type)[0]));
    }
}
