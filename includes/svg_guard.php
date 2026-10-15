<?php
// SVG upload check for the company logo (settings.php).
//
// An SVG is a document that can run script when opened directly from
// uploads/. The old check only looked for "<script", "onload" and
// "<foreignObject", so onerror/onmouseover handlers and javascript: links got
// through. This refuses anything that can run script or pull in other content.

if (!function_exists('flowork_svg_is_safe')) {
    function flowork_svg_is_safe(string $svg): bool {
        $bad = [
            '/<\s*(script|foreignObject|iframe|embed|object|handler|listener)\b/i', // active / embedding elements
            '/\bon[a-z]+\s*=/i',                                  // any event handler attribute
            '/(java|vb)script\s*:|data\s*:\s*text\/html/i',       // script URLs
            '/<!\s*(ENTITY|DOCTYPE)|&#/i',                        // entity tricks, encoded payloads
            '/\bhref\s*=\s*["\']?+\s*+(?!#|data:image\/(png|jpe?g|gif|webp);)/i', // links other than in-file refs / raster images
        ];
        foreach ($bad as $re) {
            if (preg_match($re, $svg)) return false;
        }
        return true;
    }
}
