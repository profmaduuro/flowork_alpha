<?php
// Module rights for a user row — shared by the web session
// (flowork_build_user_session in includes/auth.php) and the mobile API
// (api/config/response.php), so both apply the same rules.

if (!function_exists('flowork_resolve_rights')) {
    /**
     * @param array $user users row (needs role, rights, is_admin, is_master)
     * @return array<string,bool> right => true
     */
    function flowork_resolve_rights(array $user): array {
        $rights = [];
        if (!empty($user['rights'])) {
            $explicit_rights = is_array($user['rights']) ? $user['rights'] : @json_decode($user['rights'], true);
            if (is_array($explicit_rights)) {
                foreach ($explicit_rights as $rkey) {
                    $rights[$rkey] = true;
                }
            }
        }

        // Role-inherited rights
        $role_rights = [
            'admin'         => ['floor','balance','accounts','reports','stoporders','growers','dispatch','payments','admin'],
            'floor_manager' => ['floor','balance','accounts','reports','stoporders','growers','dispatch'],
            'clerk'         => ['floor','growers'],
            'accounts'      => ['accounts','reports','payments','stoporders'],
            'reports'       => ['reports'],
            'viewer'        => ['reports'],
        ];
        $role = $user['role'] ?? '';
        if (isset($role_rights[$role])) {
            foreach ($role_rights[$role] as $rkey) {
                $rights[$rkey] = true;
            }
        }

        // Admins and masters get every module
        $is_master = !empty($user['is_master']) || $role === 'master';
        $is_admin  = !empty($user['is_admin'])  || $role === 'admin';
        if ($is_admin || $is_master) {
            foreach (['admin','floor','balance','accounts','reports','stoporders','growers','dispatch','payments'] as $rkey) {
                $rights[$rkey] = true;
            }
        }

        return $rights;
    }
}
