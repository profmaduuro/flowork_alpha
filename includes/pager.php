<?php
// Shared page bar for server-paged lists:
//   "Showing a–b of n" · Rows [25|50|100|200] · « ‹ 1 … 4 5 6 … 90 › »
// Links keep every other GET parameter (search, filters, tab), so paging one
// list never resets another. Styles: .fw-pager in includes/layout.css.

if (!function_exists('fw_pager')) {
    /** Rows per page from $_GET[$param], limited to the offered sizes. */
    function fw_per_page(string $param, int $default = 50, array $sizes = [25, 50, 100, 200]): int {
        $v = (int)($_GET[$param] ?? 0);
        return in_array($v, $sizes, true) ? $v : $default;
    }

    /** Page window for a total: returns [page, pages, offset]. */
    function fw_page_window(int $found, int $per, string $param): array {
        $pages = max(1, (int)ceil($found / max(1, $per)));
        $page  = min(max(1, (int)($_GET[$param] ?? 1)), $pages);
        return [$page, $pages, ($page - 1) * $per];
    }

    /** Current URL with one parameter changed. */
    function fw_page_url(string $param, int $value): string {
        $q = $_GET; $q[$param] = $value; unset($q['ajax'], $q['export']);
        return strtok($_SERVER['REQUEST_URI'] ?? '', '?') . '?' . http_build_query($q);
    }

    /**
     * $o: page, pages, offset, shown, found, per, page_param, size_param,
     *     keep (GET keys the rows-per-page form carries), noun, filtered, where ('top'|'bottom')
     */
    function fw_pager(array $o): string {
        $page = (int)$o['page']; $pages = (int)$o['pages']; $off = (int)$o['offset'];
        $found = (int)$o['found']; $shown = (int)$o['shown']; $per = (int)$o['per'];
        $pp = $o['page_param']; $sp = $o['size_param']; $noun = $o['noun'] ?? 'rows';
        $where = $o['where'] ?? 'top';
        $h = '<nav class="fw-pager fw-pager-' . $where . '" aria-label="' . htmlspecialchars(ucfirst($noun)) . ' pages (' . $where . ')">';
        $h .= '<span class="fw-pager-info">' . ($found
            ? 'Showing <strong>' . number_format($off + 1) . '–' . number_format($off + $shown) . '</strong> of <strong>' . number_format($found) . '</strong>' . (!empty($o['filtered']) ? ' matching' : '')
            : 'No ' . htmlspecialchars($noun)) . '</span>';
        $h .= '<form method="GET" class="fw-pager-size">';
        foreach (($o['keep'] ?? []) as $k) {
            if (isset($_GET[$k]) && $_GET[$k] !== '') $h .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$_GET[$k]) . '">';
        }
        $h .= '<label>Rows <select name="' . htmlspecialchars($sp) . '" class="form-control" onchange="this.form.submit()">';
        foreach (($o['sizes'] ?? [25, 50, 100, 200]) as $n) $h .= '<option value="' . $n . '"' . ($n === $per ? ' selected' : '') . '>' . $n . '</option>';
        $h .= '</select></label></form>';
        if ($pages > 1) {
            $btn = function ($p, $label, $aria, $cur = false, $dis = false) use ($pp) {
                if ($dis) return '<span class="fw-pg is-dis" aria-hidden="true">' . $label . '</span>';
                return '<a class="fw-pg' . ($cur ? ' is-cur' : '') . '" href="' . htmlspecialchars(fw_page_url($pp, $p)) . '" aria-label="' . $aria . '"' . ($cur ? ' aria-current="page"' : '') . '>' . $label . '</a>';
            };
            $h .= '<span class="fw-pager-pages">';
            $h .= $btn(1, '«', 'First page', false, $page <= 1);
            $h .= $btn($page - 1, '‹', 'Previous page', false, $page <= 1);
            $win = [1, $pages];
            for ($i = $page - 2; $i <= $page + 2; $i++) if ($i >= 1 && $i <= $pages) $win[] = $i;
            $win = array_values(array_unique($win)); sort($win);
            $prev = 0;
            foreach ($win as $p) {
                if ($prev && $p > $prev + 1) $h .= '<span class="fw-pg-gap" aria-hidden="true">…</span>';
                $h .= $btn($p, number_format($p), 'Page ' . $p, $p === $page);
                $prev = $p;
            }
            $h .= $btn($page + 1, '›', 'Next page', false, $page >= $pages);
            $h .= $btn($pages, '»', 'Last page', false, $page >= $pages);
            $h .= '</span>';
        }
        return $h . '</nav>';
    }
}
