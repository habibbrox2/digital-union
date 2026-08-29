<?php
/**
 * Role Level & Ranking System Configuration
 * Defines role levels for hierarchy-based permission and access control
 * 
 * IMPORTANT: Lower role_level = Higher privilege
 * This ensures numerical comparison works intuitively
 */

// =====================================================================
// ROLE LEVEL CONSTANTS
// =====================================================================

// Highest privilege to lowest
if (!defined('ROLE_LEVEL_SUPERADMIN')) define('ROLE_LEVEL_SUPERADMIN', 1);        // Superadmin (System-wide)
if (!defined('ROLE_LEVEL_SYSTEM_ADMIN')) define('ROLE_LEVEL_SYSTEM_ADMIN', 2);    // System Admin (Union+)
if (!defined('ROLE_LEVEL_SECRETARY')) define('ROLE_LEVEL_SECRETARY', 3);          // Secretary (Union specific)
if (!defined('ROLE_LEVEL_CHAIRMAN')) define('ROLE_LEVEL_CHAIRMAN', 4);            // Chairman (Union specific)
if (!defined('ROLE_LEVEL_MEMBER')) define('ROLE_LEVEL_MEMBER', 5);                // Member (Ward specific)
if (!defined('ROLE_LEVEL_OPERATOR')) define('ROLE_LEVEL_OPERATOR', 6);            // Computer Operator
if (!defined('ROLE_LEVEL_POLICE')) define('ROLE_LEVEL_POLICE', 7);                // Gram Police
if (!defined('ROLE_LEVEL_ASSISTANT')) define('ROLE_LEVEL_ASSISTANT', 8);          // Office Assistant (Lowest)
if (!defined('ROLE_LEVEL_GUEST')) define('ROLE_LEVEL_GUEST', 9);                  // Guest/No role (Lowest privilege)

// =====================================================================
// ROLE LEVEL DESCRIPTIONS (বাংলা ও English)
// =====================================================================

if (!function_exists('getRoleLevelName')) {
    function getRoleLevelName($roleLevel, $language = 'bn') {
        $levels = [
            ROLE_LEVEL_SUPERADMIN => [
                'bn' => 'সুপারঅ্যাডমিন',
                'en' => 'Superadmin'
            ],
            ROLE_LEVEL_SYSTEM_ADMIN => [
                'bn' => 'সিস্টেম অ্যাডমিন',
                'en' => 'System Admin'
            ],
            ROLE_LEVEL_SECRETARY => [
                'bn' => 'সচিব',
                'en' => 'Secretary'
            ],
            ROLE_LEVEL_CHAIRMAN => [
                'bn' => 'চেয়ারম্যান',
                'en' => 'Chairman'
            ],
            ROLE_LEVEL_MEMBER => [
                'bn' => 'সদস্য',
                'en' => 'Member'
            ],
            ROLE_LEVEL_OPERATOR => [
                'bn' => 'কম্পিউটার অপারেটর',
                'en' => 'Computer Operator'
            ],
            ROLE_LEVEL_POLICE => [
                'bn' => 'গ্রাম পুলিশ',
                'en' => 'Gram Police'
            ],
            ROLE_LEVEL_ASSISTANT => [
                'bn' => 'অফিস সহকারী',
                'en' => 'Office Assistant'
            ],
            ROLE_LEVEL_GUEST => [
                'bn' => 'অতিথি',
                'en' => 'Guest'
            ]
        ];
        
        return $levels[$roleLevel][$language] ?? 'Unknown';
    }
}

// =====================================================================
// ROLE LEVEL HIERARCHIES
// =====================================================================

/**
 * Get role level from role ID
 * Maps old role_id system to new role_level system
 */
if (!function_exists('getRoleLevelFromId')) {
    function getRoleLevelFromId($roleId) {
        $map = [
            1 => ROLE_LEVEL_SUPERADMIN,
            2 => ROLE_LEVEL_SECRETARY,
            3 => ROLE_LEVEL_CHAIRMAN,
            4 => ROLE_LEVEL_MEMBER,
            5 => ROLE_LEVEL_OPERATOR,
            6 => ROLE_LEVEL_POLICE,
            7 => ROLE_LEVEL_ASSISTANT,
        ];
        
        return $map[$roleId] ?? ROLE_LEVEL_GUEST;
    }
}

/**
 * Check if role1 has higher or equal privilege than role2
 * Returns true if role1_level <= role2_level (lower number = higher privilege)
 */
if (!function_exists('isRoleHigherThan')) {
    function isRoleHigherThan($role1Level, $role2Level) {
        return $role1Level <= $role2Level;
    }
}

/**
 * Check if role1 has strictly higher privilege than role2
 */
if (!function_exists('isRoleStrictlyHigherThan')) {
    function isRoleStrictlyHigherThan($role1Level, $role2Level) {
        return $role1Level < $role2Level;
    }
}

/**
 * Get role privilege gap
 * Returns how many levels of difference there are
 */
if (!function_exists('getRolePrivilegeDifference')) {
    function getRolePrivilegeDifference($role1Level, $role2Level) {
        return abs($role2Level - $role1Level);
    }
}

// =====================================================================
// ROLE CLASSIFICATION FUNCTIONS
// =====================================================================

/**
 * Check if role is admin-level or above
 * Superadmin and System Admin are considered admin levels
 */
if (!function_exists('isAdminLevel')) {
    function isAdminLevel($roleLevel) {
        return $roleLevel <= ROLE_LEVEL_SYSTEM_ADMIN;
    }
}

/**
 * Check if role is management-level
 * Admin + Secretary + Chairman
 */
if (!function_exists('isManagementLevel')) {
    function isManagementLevel($roleLevel) {
        return $roleLevel <= ROLE_LEVEL_CHAIRMAN;
    }
}

/**
 * Check if role is operational-level
 * Operator, Police, Assistant, etc.
 */
if (!function_exists('isOperationalLevel')) {
    function isOperationalLevel($roleLevel) {
        return $roleLevel >= ROLE_LEVEL_OPERATOR;
    }
}

/**
 * Check if role is member-level or below
 */
if (!function_exists('isMemberLevel')) {
    function isMemberLevel($roleLevel) {
        return $roleLevel >= ROLE_LEVEL_MEMBER;
    }
}

// =====================================================================
// ROLE CAPABILITIES BASED ON LEVEL
// =====================================================================

/**
 * Get what roles a user can manage based on their role level
 */
if (!function_exists('getManageableRoleLevels')) {
    function getManageableRoleLevels($userRoleLevel) {
        // Superadmin can manage everyone except other superadmins
        if ($userRoleLevel === ROLE_LEVEL_SUPERADMIN) {
            return [
                ROLE_LEVEL_SYSTEM_ADMIN,
                ROLE_LEVEL_SECRETARY,
                ROLE_LEVEL_CHAIRMAN,
                ROLE_LEVEL_MEMBER,
                ROLE_LEVEL_OPERATOR,
                ROLE_LEVEL_POLICE,
                ROLE_LEVEL_ASSISTANT,
                ROLE_LEVEL_GUEST
            ];
        }
        
        // System Admin can manage everyone below them
        if ($userRoleLevel === ROLE_LEVEL_SYSTEM_ADMIN) {
            return [
                ROLE_LEVEL_SECRETARY,
                ROLE_LEVEL_CHAIRMAN,
                ROLE_LEVEL_MEMBER,
                ROLE_LEVEL_OPERATOR,
                ROLE_LEVEL_POLICE,
                ROLE_LEVEL_ASSISTANT,
                ROLE_LEVEL_GUEST
            ];
        }
        
        // Secretary can manage Chair, Member, Operators, etc.
        if ($userRoleLevel === ROLE_LEVEL_SECRETARY) {
            return [
                ROLE_LEVEL_CHAIRMAN,
                ROLE_LEVEL_MEMBER,
                ROLE_LEVEL_OPERATOR,
                ROLE_LEVEL_POLICE,
                ROLE_LEVEL_ASSISTANT,
                ROLE_LEVEL_GUEST
            ];
        }
        
        // Chairman can manage Members and Operators
        if ($userRoleLevel === ROLE_LEVEL_CHAIRMAN) {
            return [
                ROLE_LEVEL_MEMBER,
                ROLE_LEVEL_OPERATOR,
                ROLE_LEVEL_POLICE,
                ROLE_LEVEL_ASSISTANT,
                ROLE_LEVEL_GUEST
            ];
        }
        
        // Lower roles can manage very few or none
        return [];
    }
}

/**
 * Get what permissions a role can grant based on their level
 */
if (!function_exists('getGrantablePermissionLevels')) {
    function getGrantablePermissionLevels($userRoleLevel) {
        // Superadmin can grant all permissions
        if ($userRoleLevel === ROLE_LEVEL_SUPERADMIN) {
            return ['all'];
        }
        
        // System Admin can grant most permissions except superadmin-only
        if ($userRoleLevel === ROLE_LEVEL_SYSTEM_ADMIN) {
            return ['system', 'union', 'general'];
        }
        
        // Secretary can grant union-level permissions
        if ($userRoleLevel === ROLE_LEVEL_SECRETARY) {
            return ['union', 'general'];
        }
        
        // Chairman can grant general/low-level permissions
        if ($userRoleLevel === ROLE_LEVEL_CHAIRMAN) {
            return ['general'];
        }
        
        // Others can't grant permissions
        return [];
    }
}

// =====================================================================
// ROLE SCOPE/CONTEXT FUNCTIONS
// =====================================================================

/**
 * Determine if a role has system-wide scope
 */
if (!function_exists('isSystemWideScopeRole')) {
    function isSystemWideScopeRole($roleLevel) {
        return $roleLevel <= ROLE_LEVEL_SYSTEM_ADMIN;
    }
}

/**
 * Determine if a role has union-specific scope
 */
if (!function_exists('isUnionSpecificScopeRole')) {
    function isUnionSpecificScopeRole($roleLevel) {
        return $roleLevel >= ROLE_LEVEL_SECRETARY && $roleLevel <= ROLE_LEVEL_CHAIRMAN;
    }
}

/**
 * Determine if a role has ward-specific scope
 */
if (!function_exists('isWardSpecificScopeRole')) {
    function isWardSpecificScopeRole($roleLevel) {
        return $roleLevel == ROLE_LEVEL_MEMBER;
    }
}

