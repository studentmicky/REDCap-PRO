<?php

namespace YaleREDCap\REDCapPRO;

require_once("ProjectSettings.php");

class LoginHelper
{

    public static $module;
    public static $SETTINGS;
    function __construct($module)
    {
        self::$module = $module;
        self::$SETTINGS = new ProjectSettings($module);
    }


    /**
     * Increments the number of failed attempts at login for the provided id
     * 
     * @param int $rcpro_participant_id ID key for participant
     * @param null|string $rcpro_username RCPRO Username    
     * 
     * @return BOOL|NULL whether increment succeeded
     */
    public function incrementFailedLogin(int $rcpro_participant_id, ?string $rcpro_username = NULL)
    {
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value = value+1 WHERE log_id = ? AND name = 'failed_attempts'";
        try {
            $res = self::$module->query($SQL, [$rcpro_participant_id]);

            // Lockout username if necessary
            $this->lockoutLogin($rcpro_participant_id, $rcpro_username);
            return $res;
        } catch (\Exception $e) {
            self::$module->logError("Error incrementing failed login", $e);
        }
    }

    /**
     * This both tests whether a user should be locked out based on the number
     * of failed login attempts and does the locking out.
     * 
     * @param int $rcpro_participant_id id key for participant
     * @param string|NULL $rcpro_username RCPRO Username
     * 
     * @return BOOL|NULL
     */
    private function lockoutLogin(int $rcpro_participant_id, ?string $rcpro_username = NULL)
    {
        try {
            $attempts = $this->checkUsernameAttempts($rcpro_participant_id);
            if ($attempts >= self::$SETTINGS->getLoginAttempts()) {
                $lockout_ts = time() + self::$SETTINGS->getLockoutDurationSeconds();
                $SQL = "UPDATE redcap_external_modules_log_parameters SET value = ? WHERE log_id = ? AND name = 'lockout_ts';";
                $res = self::$module->query($SQL, [$lockout_ts, $rcpro_participant_id]);
                $status = $res ? "Successful" : "Failed";
                self::$module->log("Login Lockout ${status}", [
                    "rcpro_participant_id" => $rcpro_participant_id,
                    "rcpro_username"       => $rcpro_username
                ]);
                return $res;
            } else {
                return TRUE;
            }
        } catch (\Exception $e) {
            self::$module->logError("Error doing login lockout", $e);
            return FALSE;
        }
    }

    /**
     * Resets the count of failed login attempts for the given id
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return BOOL|NULL
     */
    public function resetFailedLogin(int $rcpro_participant_id)
    {
        $SQL = "UPDATE redcap_external_modules_log_parameters SET value=0 WHERE log_id=? AND name='failed_attempts';";
        try {
            return self::$module->query($SQL, [$rcpro_participant_id]);
        } catch (\Exception $e) {
            self::$module->logError("Error resetting failed login count", $e);
            return NULL;
        }
    }

    /**
     * Gets the IP address in an easy way
     * 
     * @return string - the ip address
     */
    public function getIPAddress()
    {
        return $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'];
    }

    /**
     * Increments the number of failed login attempts for the given ip address
     * 
     * It also detects whether the ip should be locked out based on the number
     * of failed attempts and then does the locking.
     * 
     * @param string $ip Client IP address
     * 
     * @return int number of attempts INCLUDING current attempt
     */
    public function incrementFailedIp(string $ip)
    {
        if (self::$module->getSystemSetting('ip_lockouts') === null) {
            self::$module->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode(self::$module->getSystemSetting('ip_lockouts'), true);
        if (isset($ipLockouts[$ip])) {
            $ipStat = $ipLockouts[$ip];
        } else {
            $ipStat = array();
        }
        if (isset($ipStat["attempts"])) {
            $ipStat["attempts"]++;
            if ($ipStat["attempts"] >= self::$SETTINGS->getLoginAttempts()) {
                $ipStat["lockout_ts"] = time() + self::$SETTINGS->getLockoutDurationSeconds();
                self::$module->log("Locked out IP address", [
                    "rcpro_ip"   => $ip,
                    "lockout_ts" => $ipStat["lockout_ts"]
                ]);
            }
        } else {
            $ipStat["attempts"] = 1;
        }
        $ipLockouts[$ip] = $ipStat;
        self::$module->setSystemSetting('ip_lockouts', json_encode($ipLockouts));
        return $ipStat["attempts"];
    }

    /**
     * Resets the failed login attempt count for the given ip address
     * 
     * @param string $ip Client IP address
     * 
     * @return bool Whether or not the reset succeeded
     */
    public function resetFailedIp(string $ip)
    {
        try {
            if (self::$module->getSystemSetting('ip_lockouts') === null) {
                self::$module->setSystemSetting('ip_lockouts', json_encode(array()));
            }
            $ipLockouts = json_decode(self::$module->getSystemSetting('ip_lockouts'), true);
            if (isset($ipLockouts[$ip])) {
                $ipStat = $ipLockouts[$ip];
            } else {
                $ipStat = array();
            }
            $ipStat["attempts"] = 0;
            $ipStat["lockout_ts"] = NULL;
            $ipLockouts[$ip] = $ipStat;
            self::$module->setSystemSetting('ip_lockouts', json_encode($ipLockouts));
            return TRUE;
        } catch (\Exception $e) {
            self::$module->logError("IP Login Attempt Reset Failed", $e);
            return FALSE;
        }
    }

    /**
     * Checks the number of failed login attempts for the given ip address
     * 
     * @param string $ip Client IP address
     * 
     * @return int number of failed login attempts for the given ip
     */
    private function checkIpAttempts(string $ip)
    {
        if (self::$module->getSystemSetting('ip_lockouts') === null) {
            self::$module->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode(self::$module->getSystemSetting('ip_lockouts'), true);
        if (isset($ipLockouts[$ip])) {
            $ipStat = $ipLockouts[$ip];
            if (isset($ipStat["attempts"])) {
                return $ipStat["attempts"];
            }
        }
        return 0;
    }

    /**
     * Determines whether given ip is currently locked out
     * 
     * @param string $ip Client IP address
     * 
     * @return bool whether ip is locked out
     */
    public function checkIpLockedOut(string $ip)
    {
        if (self::$module->getSystemSetting('ip_lockouts') === null) {
            self::$module->setSystemSetting('ip_lockouts', json_encode(array()));
        }
        $ipLockouts = json_decode(self::$module->getSystemSetting('ip_lockouts'), true);
        $ipStat = $ipLockouts[$ip];

        if (isset($ipStat) && $ipStat["lockout_ts"] !== null && $ipStat["lockout_ts"] >= time()) {
            return $ipStat["lockout_ts"];
        }
        return FALSE;
    }

    /**
     * Gets number of failed login attempts for the given user by id
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return int number of failed login attempts
     */
    private function checkUsernameAttempts(int $rcpro_participant_id)
    {
        $SQL = "SELECT failed_attempts WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL)";
        try {
            $res = self::$module->queryLogs($SQL, [$rcpro_participant_id]);
            return $res->fetch_assoc()["failed_attempts"];
        } catch (\Exception $e) {
            self::$module->logError("Failed to check username attempts", $e);
            return 0;
        }
    }

    /**
     * Checks whether given user (by id) is locked out
     * 
     * Returns the remaining lockout time in seconds for this participant
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return int number of seconds of lockout left
     */
    public function getUsernameLockoutDuration(int $rcpro_participant_id)
    {
        $SQL = "SELECT lockout_ts WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL);";
        try {
            $res = self::$module->queryLogs($SQL, [$rcpro_participant_id]);
            $lockout_ts = intval($res->fetch_assoc()["lockout_ts"]);
            $time_remaining = $lockout_ts - time();
            if ($time_remaining > 0) {
                return $time_remaining;
            }
        } catch (\Exception $e) {
            self::$module->logError("Failed to check username lockout", $e);
        }
    }

    /**
     * Returns number of consecutive failed login attempts
     * 
     * This checks both by username and by ip, and returns the larger
     * 
     * @param int|null $rcpro_participant_id
     * @param mixed $ip
     * 
     * @return int number of consecutive attempts
     */
    public function checkAttempts($rcpro_participant_id, $ip)
    {
        if ($rcpro_participant_id === null) {
            $usernameAttempts = 0;
        } else {
            $usernameAttempts = $this->checkUsernameAttempts($rcpro_participant_id);
        }
        $ipAttempts = $this->checkIpAttempts($ip);
        return max($usernameAttempts, $ipAttempts);
    }

    /**
     * Get hashed password for participant.
     * 
     * @param int $rcpro_participant_id - id key for participant
     * 
     * @return string|NULL hashed password or null
     */
    public function getHash(int $rcpro_participant_id)
    {
        try {
            $SQL = "SELECT pw WHERE message = 'PARTICIPANT' AND log_id = ? AND (project_id IS NULL OR project_id IS NOT NULL);";
            $res = self::$module->queryLogs($SQL, [$rcpro_participant_id]);
            return $res->fetch_assoc()['pw'];
        } catch (\Exception $e) {
            self::$module->logError("Error fetching password hash", $e);
        }
    }
}
