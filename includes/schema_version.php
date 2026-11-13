<?php
// Schema version markers, so a module's CREATE TABLE / ALTER checks run once
// per version instead of on every page load. A module bumps its version number
// whenever its ensure function gains a table or column.

if (!function_exists('flowork_schema_is_current')) {
    /** True when $module's tables were already set up at $version or later. */
    function flowork_schema_is_current(mysqli $c, string $module, int $version): bool {
        $stmt = $c->prepare("SELECT version FROM flowork_schema_versions WHERE module = ?");
        if (!$stmt) {
            if ($c->errno === 1146) {
                $c->query("CREATE TABLE IF NOT EXISTS flowork_schema_versions (
                    module VARCHAR(60) NOT NULL PRIMARY KEY,
                    version INT NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
            }
            return false;
        }
        $stmt->bind_param('s', $module);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row && (int)$row['version'] >= $version;
    }

    /** Record that $module's tables are set up at $version. */
    function flowork_schema_mark(mysqli $c, string $module, int $version): void {
        $stmt = $c->prepare("INSERT INTO flowork_schema_versions (module, version) VALUES (?, ?)
                             ON DUPLICATE KEY UPDATE version = VALUES(version)");
        if (!$stmt) return;
        $stmt->bind_param('si', $module, $version);
        $stmt->execute();
        $stmt->close();
    }
}
