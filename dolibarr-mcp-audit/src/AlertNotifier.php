<?php
declare(strict_types=1);
namespace DolibarrMcpAudit;

/* Copyright (C) 2026 E-dem
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

use Throwable;

/**
 * \file    src/AlertNotifier.php
 * \ingroup dolibarr-mcp-audit
 * \brief   Warns an administrator when one user's MCP usage stands out.
 *
 * The alert exists because the interesting case is the one nobody is watching:
 * an employee with legitimate access reading far more than their work requires.
 * The rate limit stops it; this makes sure somebody hears about it.
 *
 * The hard part is not sending the mail, it is not sending a thousand. A busy
 * agent crosses the threshold on one call and stays above it for every call
 * that follows, so a naive implementation mails the administrator on each one
 * and gets itself filtered within the hour. Two guards:
 *
 *  - a cooldown per user, so one noisy afternoon produces one mail;
 *  - the send is recorded before the mail leaves, so two concurrent requests
 *    crossing the threshold together cannot both decide they are first.
 */
class AlertNotifier
{
    private const DEFAULT_COOLDOWN_MINUTES = 720;

    /** @var \DoliDB */
    private $db;

    private CallLog $log;

    private AuditConfig $config;

    /**
     * @param \DoliDB $db Database handler
     */
    public function __construct($db, CallLog $log, AuditConfig $config)
    {
        $this->db = $db;
        $this->log = $log;
        $this->config = $config;
    }

    /**
     * Send an alert if this user just crossed the threshold and none was sent
     * recently. Never raises: a failed alert must not fail the call it watches.
     *
     * @param  int    $userId Dolibarr user the agent acted as
     * @param  string $login  That user's login, for the message
     * @param  int    $window Window in minutes the count applies to
     * @return bool   True when a mail was actually sent
     */
    public function notifyIfUnusual(int $userId, string $login, int $window): bool
    {
        $threshold = \getDolGlobalInt($this->config->const('ALERT_THRESHOLD'));
        if ($threshold <= 0) {
            return false;
        }

        try {
            $used = $this->log->countRecent($userId, $window);
            if ($used < $threshold) {
                return false;
            }

            if (!$this->claimCooldown($userId)) {
                return false;
            }

            return $this->send($login, $used, $window, $threshold);
        } catch (Throwable $e) {
            \dol_syslog($this->config->logPrefix() . ' MCP alert failed: ' . $e->getMessage(), LOG_WARNING);

            return false;
        }
    }

    /**
     * Take the right to alert about this user, or report that someone else has it.
     *
     * Written before the mail is sent rather than after: if sending is slow and
     * a second request arrives meanwhile, the constant already says "handled".
     * The cost of that ordering is losing an alert when the mail then fails —
     * preferable to mailing the same warning fifty times.
     */
    private function claimCooldown(int $userId): bool
    {
        global $conf;

        // dolibarr_set_const() lives in admin.lib.php, which the MCP entry
        // points do not load — they are API surfaces, not admin pages. Without
        // this require the call raised, the catch in notifyIfUnusual swallowed
        // it, and alerting silently never happened.
        require_once DOL_DOCUMENT_ROOT . '/core/lib/admin.lib.php';

        $key = $this->config->const('ALERT_LAST_' . $userId);
        $last = (int) \getDolGlobalInt($key);
        $cooldown = \getDolGlobalInt($this->config->const('ALERT_COOLDOWN'));
        if ($cooldown <= 0) {
            $cooldown = self::DEFAULT_COOLDOWN_MINUTES;
        }

        $now = dol_now();
        if ($last > 0 && ($now - $last) < ($cooldown * 60)) {
            return false;
        }

        \dolibarr_set_const($this->db, $key, (string) $now, 'chaine', 0, '', $conf->entity ?? 1);

        return true;
    }

    private function send(string $login, int $used, int $window, int $threshold): bool
    {
        global $conf, $langs;

        $to = \getDolGlobalString($this->config->const('ALERT_EMAIL'));
        if ($to === '') {
            $to = \getDolGlobalString('MAIN_INFO_SOCIETE_MAIL');
        }
        if ($to === '') {
            \dol_syslog(
                $this->config->logPrefix() . ' MCP alert not sent: no recipient configured',
                LOG_WARNING
            );

            return false;
        }

        $from = \getDolGlobalString('MAIN_MAIL_EMAIL_FROM');
        if ($from === '') {
            $from = $to;
        }

        $subject = sprintf('[%s] Unusual MCP activity for user %s', $conf->global->MAIN_INFO_SOCIETE_NOM ?? 'Dolibarr', $login);

        $body = "The user \"" . $login . "\" made " . $used . " MCP calls in the last " . $window
            . " minutes, which is at or above the alert threshold of " . $threshold . ".\n\n"
            . "This is not necessarily a problem: a long analysis session legitimately makes many calls. "
            . "It is worth a look if that volume does not match what this person's work requires.\n\n"
            . "The full call history is in the module's MCP activity screen, where you can see which "
            . "tools were used and with which arguments.\n\n"
            . "No further alert will be sent for this user until the cooldown expires.\n";

        require_once DOL_DOCUMENT_ROOT . '/core/class/CMailFile.class.php';
        $mail = new \CMailFile($subject, $to, $from, $body);

        if ($mail->sendfile()) {
            \dol_syslog($this->config->logPrefix() . ' MCP alert sent for user ' . $login, LOG_INFO);

            return true;
        }

        \dol_syslog($this->config->logPrefix() . ' MCP alert could not be sent: ' . $mail->error, LOG_WARNING);

        return false;
    }
}
