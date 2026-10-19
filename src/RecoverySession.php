<?php

namespace GlpiPlugin\Jmbahub;

use GLPIKey;
use Glpi\Exception\SessionExpiredException;
use Session;
use User;
use Profile;
use Profile_User;
use Dropdown;

/** REST session adapter. Never converts a REST session into a browser session. */
final class RecoverySession
{
    public static function open(string $token): void
    {
        $encrypted = self::majorVersion() === 11
            ? version_compare(GLPI_VERSION, '11.0.7', '>=')
            : version_compare(GLPI_VERSION, '10.0.25', '>=');
        $id = $token;
        if ($encrypted) {
            // GLPI REST wraps GLPIKey ciphertext in another Base64 layer.
            if (strlen($token) > 1024 || !preg_match('/^[A-Za-z0-9+\/]+={0,2}$/D', $token)) {
                throw new SessionExpiredException();
            }
            $ciphertext = base64_decode($token, true);
            if ($ciphertext === false || base64_encode($ciphertext) !== $token) {
                throw new SessionExpiredException();
            }
            $cipher = new GLPIKey();
            // Suppress core warning handlers before any key read/decryption; their
            // stack/scope dumps can include the supplied session credential.
            set_error_handler(static fn () => true, E_WARNING | E_USER_WARNING);
            try {
                // GLPIKey returns its input on a missing key: reject that fallback.
                $key = $cipher->get();
                if (!is_string($key) || strlen($key) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
                    throw new \RuntimeException('Recovery key unavailable.');
                }
                $id = $cipher->decrypt($ciphertext, $key);
            } finally {
                restore_error_handler();
            }
        }
        if (!is_string($id) || !preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $id)) {
            throw new SessionExpiredException();
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        $_SESSION = [];
        ini_set('session.use_cookies', '0');
        ini_set('session.use_only_cookies', '1');
        Session::setPath();
        session_id($id);
        Session::start();
        if (session_id() !== $id || !is_numeric($_SESSION['glpiID'] ?? null)
            || (int) $_SESSION['glpiID'] <= 0 || !is_string($_SESSION['glpiname'] ?? null)
            || $_SESSION['glpiname'] === ''
            || ($encrypted ? array_key_exists('valid_id', $_SESSION) : ($_SESSION['valid_id'] ?? null) !== $id)) {
            throw new SessionExpiredException();
        }
    }

    /**
     * Equivalent DB authorization to GLPI 11 Session::checkValidSessionId, without
     * its browser-only valid_id prerequisite. Keep the PHP lock until the caller
     * finishes so refreshed rights persist for the next REST operation.
     */
    public static function hasCurrentContext(): bool
    {
        global $DB, $CFG_GLPI;
        $major = self::majorVersion();
        $userId = Session::getLoginUserID();
        $profileId = $_SESSION['glpiactiveprofile']['id'] ?? null;
        $entityId = $_SESSION['glpiactive_entity'] ?? null;
        if (!is_numeric($userId) || !is_numeric($profileId) || !is_numeric($entityId)
            || (int) $profileId <= 0 || (int) $entityId < 0) {
            return false;
        }
        if (array_key_exists('glpi_remote_user', $_SESSION)) {
            $variable = Dropdown::getDropdownName('glpi_ssovariables', $CFG_GLPI['ssovariables_id']);
            if (!array_key_exists($variable, $_SERVER) || $_SERVER[$variable] !== $_SESSION['glpi_remote_user']) {
                throw new SessionExpiredException();
            }
        }
        $users = User::getTable();
        $memberships = Profile_User::getTable();
        $profiles = Profile::getTable();
        $where = [
            "$users.id" => (int) $userId,
            "$users.is_active" => 1,
            "$users.is_deleted" => 0,
            "$memberships.profiles_id" => (int) $profileId,
        ] + getEntitiesRestrictCriteria($memberships, 'entities_id', (int) $entityId, true);
        if (!empty($_SESSION['glpiactive_entity_recursive'])) {
            $where["$memberships.is_recursive"] = 1;
        }
        $query = [
            'COUNT' => 'count',
            'FROM' => $users,
            'LEFT JOIN' => [
                $memberships => ['FKEY' => [$memberships => 'users_id', $users => 'id']],
            ],
            'WHERE' => $where,
        ];
        if ($major === 11) {
            $query['SELECT'] = ["$profiles.last_rights_update"];
            $query['LEFT JOIN'][$profiles] = ['FKEY' => [$memberships => 'profiles_id', $profiles => 'id']];
            $query['GROUPBY'] = ["$profiles.id"];
        }
        $row = $DB->request($query)->current();
        if (!$row || (int) $row['count'] === 0) {
            return false;
        }
        if ($major === 10) {
            // GLPI 10 has neither last_rights_update nor reloadCurrentProfile().
            // Use the rights-loading portion of its changeProfile(), without
            // invoking that method's automatic entity selection or hooks.
            $profile = new Profile();
            if (!$profile->getFromDB((int) $profileId)) {
                return false;
            }
            $profile->cleanProfile();
            $fields = $profile->fields;
            $fields['entities'] = $_SESSION['glpiactiveprofile']['entities'] ?? [];
            $_SESSION['glpiactiveprofile'] = $fields;
        } elseif ($row['last_rights_update'] !== null
            && $row['last_rights_update'] > ($_SESSION['glpiactiveprofile']['last_rights_update'] ?? 0)) {
            Session::reloadCurrentProfile();
            $_SESSION['glpiactiveprofile']['last_rights_update'] = $row['last_rights_update'];
        }
        return true;
    }

    private static function majorVersion(): int
    {
        if (!preg_match('/^(10|11)\./', GLPI_VERSION, $matches)) {
            throw new \RuntimeException('Unsupported GLPI recovery version.');
        }
        return (int) $matches[1];
    }
}
