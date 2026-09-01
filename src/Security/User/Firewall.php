<?php

declare(strict_types=1);

namespace App\Security\User;

/**
 * The application's security firewalls, and the routes each one sends a user to. Symfony's FirewallMap exposes no
 * per-firewall route metadata, so the mapping lives here and is shared by the guards, listeners and handlers that need
 * it, keyed off the firewall name they already have.
 */
enum Firewall: string
{
    case Main = 'main';
    case Company = 'company';

    public function loginRoute(): string
    {
        return match ($this) {
            self::Main => 'user_login',
            self::Company => 'company_user_login',
        };
    }

    public function sudoConfirmRoute(): string
    {
        return match ($this) {
            self::Main => 'user_sudo_confirm',
            self::Company => 'company_user_sudo_confirm',
        };
    }

    /**
     * Where somebody who cannot sign in asks for a password reset.
     */
    public function forgotPasswordRoute(): string
    {
        return match ($this) {
            self::Main => 'user_forgot_password',
            self::Company => 'company_user_forgot_password',
        };
    }

    /**
     * Where a user reviews their own sessions and security settings.
     */
    public function securityIndexRoute(): string
    {
        return match ($this) {
            self::Main => 'user_security_index',
            self::Company => 'company_user_security_index',
        };
    }

    /**
     * One per firewall, each answering inside its own firewall's pattern: a company user fetching a subscribe cookie
     * from an address the main firewall answers is nobody there, and is handed a passer-by's cookie.
     */
    public function realtimeGrantRoute(): string
    {
        return match ($this) {
            self::Main => 'app_realtime_grant',
            self::Company => 'app_company_realtime_grant',
        };
    }

    /**
     * The multi-factor enrolment route, or null for a firewall that has none (only main members enrol here).
     */
    public function mfaEnableRoute(): ?string
    {
        return match ($this) {
            self::Main => 'user_mfa_enable',
            self::Company => null,
        };
    }

    /**
     * Marks a browser as one this firewall's account has signed in from before.
     */
    public function deviceCookieName(): string
    {
        return match ($this) {
            self::Main => 'GWS_USER_DEVICE',
            self::Company => 'GWS_COMPANY_DEVICE',
        };
    }
}
