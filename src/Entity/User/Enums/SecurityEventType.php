<?php

declare(strict_types=1);

namespace App\Entity\User\Enums;

use Psr\Log\LogLevel;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * Everything the application records about who got into an account and what they were allowed to do once there.
 *
 * One case per thing that happened, rather than a free-text message, because these are read back: the administration
 * filters on them, a data export names them, and answering "why was this member signed out" means matching a report
 * against a case rather than grepping for a sentence somebody has since reworded.
 *
 * Adding a case is cheap and expected. Renaming the *value* of one is not: the values are written to the database and
 * to half a year of log files, and a renamed value silently splits one event into two.
 */
enum SecurityEventType: string
{
    // Getting in, and failing to.
    case SignInSucceeded = 'sign_in_succeeded';
    case SignInFailed = 'sign_in_failed';
    case SignInThrottled = 'sign_in_throttled';
    case SignInRefused = 'sign_in_refused';
    case SignedOut = 'signed_out';
    case SessionResumed = 'session_resumed';

    // What happened to a session after it was opened.
    case SessionEndedWithoutCookie = 'session_ended_without_cookie';
    case SessionEndedUnknownSeries = 'session_ended_unknown_series';
    case SessionEndedCredentialsChanged = 'session_ended_credentials_changed';
    case SessionEndedDeviceChanged = 'session_ended_device_changed';
    case SessionSignatureRejected = 'session_signature_rejected';
    case SessionExpired = 'session_expired';
    case RememberMeTokenUnrecognised = 'remember_me_token_unrecognised';
    case RememberMeTokenReplayed = 'remember_me_token_replayed';
    case CrossFirewallTokenRejected = 'cross_firewall_token_rejected';
    case SessionTerminated = 'session_terminated';
    case SessionsTerminated = 'sessions_terminated';
    case ReloginForced = 'relogin_forced';

    // The second factor.
    case MfaChallengeSucceeded = 'mfa_challenge_succeeded';
    case MfaChallengeFailed = 'mfa_challenge_failed';
    case MfaEnabled = 'mfa_enabled';
    case MfaDisabled = 'mfa_disabled';
    case BackupCodeUsed = 'backup_code_used';
    case BackupCodesRegenerated = 'backup_codes_regenerated';

    // The first one.
    case PasswordChanged = 'password_changed';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordResetRejected = 'password_reset_rejected';

    // Re-proving an open session before it may do something serious.
    case SudoGranted = 'sudo_granted';
    case SudoRefused = 'sudo_refused';

    // Acting as somebody else.
    case ImpersonationStarted = 'impersonation_started';
    case ImpersonationStopped = 'impersonation_stopped';

    // Machine callers.
    case ApiTokenRejected = 'api_token_rejected';
    case ApiRateLimitExceeded = 'api_rate_limit_exceeded';

    // Where the request came from, where that is what decided the answer.
    case RegisterAccessRefused = 'register_access_refused';
    case CompanyAccessRevoked = 'company_access_revoked';

    // What was done with the account itself.
    case DataExportRequested = 'data_export_requested';
    case DataExportDownloaded = 'data_export_downloaded';
    case ExternalAppAuthorised = 'external_app_authorised';

    public function category(): SecurityEventCategory
    {
        return match ($this) {
            self::SignInSucceeded,
            self::SignInFailed,
            self::SignInThrottled,
            self::SignInRefused,
            self::SignedOut,
            self::SessionResumed => SecurityEventCategory::Authentication,
            self::SessionEndedWithoutCookie,
            self::SessionEndedUnknownSeries,
            self::SessionEndedCredentialsChanged,
            self::SessionEndedDeviceChanged,
            self::SessionSignatureRejected,
            self::SessionExpired,
            self::RememberMeTokenUnrecognised,
            self::RememberMeTokenReplayed,
            self::CrossFirewallTokenRejected,
            self::SessionTerminated,
            self::SessionsTerminated,
            self::ReloginForced => SecurityEventCategory::Session,
            self::MfaChallengeSucceeded,
            self::MfaChallengeFailed,
            self::MfaEnabled,
            self::MfaDisabled,
            self::BackupCodeUsed,
            self::BackupCodesRegenerated => SecurityEventCategory::MultiFactor,
            self::PasswordChanged,
            self::PasswordResetRequested,
            self::PasswordResetCompleted,
            self::PasswordResetRejected => SecurityEventCategory::Password,
            self::SudoGranted,
            self::SudoRefused => SecurityEventCategory::Elevation,
            self::ImpersonationStarted,
            self::ImpersonationStopped => SecurityEventCategory::Delegation,
            self::ApiTokenRejected,
            self::ApiRateLimitExceeded => SecurityEventCategory::Api,
            self::RegisterAccessRefused,
            self::CompanyAccessRevoked => SecurityEventCategory::Network,
            self::DataExportRequested,
            self::DataExportDownloaded,
            self::ExternalAppAuthorised => SecurityEventCategory::Account,
        };
    }

    /**
     * How loudly the event is written to the log. The `security` channel keeps everything from `info` up
     * unconditionally, so this decides what an alert on the file would be built to watch rather than whether the
     * record survives at all.
     *
     * Ordinary use is `info`; something a member should look at is `notice`; something that suggests an attempt on an
     * account is `warning`; and `critical` is reserved for a token presented twice, which is either theft or a bug in
     * our own rotation, and is the only one of these that signs somebody out of every device at once.
     */
    public function level(): string
    {
        return match ($this) {
            self::SignInSucceeded,
            self::SignedOut,
            self::SessionResumed,
            self::SessionExpired,
            self::SessionTerminated,
            self::SessionsTerminated,
            self::MfaChallengeSucceeded,
            self::SudoGranted,
            self::DataExportRequested,
            self::DataExportDownloaded,
            self::ExternalAppAuthorised => LogLevel::INFO,
            self::SignInFailed,
            self::SignInRefused,
            self::SessionEndedCredentialsChanged,
            self::SessionEndedWithoutCookie,
            self::SessionEndedUnknownSeries,
            self::MfaChallengeFailed,
            self::MfaEnabled,
            self::MfaDisabled,
            self::BackupCodeUsed,
            self::BackupCodesRegenerated,
            self::PasswordChanged,
            self::PasswordResetRequested,
            self::PasswordResetCompleted,
            self::SudoRefused,
            self::ImpersonationStarted,
            self::ImpersonationStopped,
            self::ReloginForced => LogLevel::NOTICE,
            self::SignInThrottled,
            self::SessionEndedDeviceChanged,
            self::SessionSignatureRejected,
            self::RememberMeTokenUnrecognised,
            self::CrossFirewallTokenRejected,
            self::PasswordResetRejected,
            self::ApiTokenRejected,
            self::ApiRateLimitExceeded,
            self::RegisterAccessRefused,
            self::CompanyAccessRevoked => LogLevel::WARNING,
            self::RememberMeTokenReplayed => LogLevel::CRITICAL,
        };
    }

    /**
     * Whether a row is written beside the log line.
     *
     * Everything is kept except the resumption of a session from a remember-me cookie. That happens on every
     * arrival from another site, because the session cookie is `strict` and is not sent with one, and would be most
     * of the table inside a week while saying nothing the sign-in it follows does not already say. It is still
     * written to the log file, where a fortnight of it is a few megabytes rather than a few million rows.
     */
    public function isRecorded(): bool
    {
        return self::SessionResumed !== $this;
    }

    /**
     * What the administration's security log shows in the event column. A sentence in the passive: the subject of the
     * row is named in a column of its own, and half of these have no actor to put in front of the verb.
     */
    public function label(): TranslatableMessage
    {
        return match ($this) {
            self::SignInSucceeded => new TranslatableMessage('Signed in'),
            self::SignInFailed => new TranslatableMessage('Sign-in failed'),
            self::SignInThrottled => new TranslatableMessage('Sign-in throttled'),
            self::SignInRefused => new TranslatableMessage('Sign-in refused for this account'),
            self::SignedOut => new TranslatableMessage('Signed out'),
            self::SessionResumed => new TranslatableMessage('Session resumed from a remembered device'),
            self::SessionEndedWithoutCookie => new TranslatableMessage('Session ended: no remembered device'),
            self::SessionEndedUnknownSeries => new TranslatableMessage('Session ended: unknown device'),
            self::SessionEndedCredentialsChanged => new TranslatableMessage('Session ended: credentials changed'),
            self::SessionEndedDeviceChanged => new TranslatableMessage('Session ended: browser or system changed'),
            self::SessionSignatureRejected => new TranslatableMessage('Session ended: record could not be trusted'),
            self::SessionExpired => new TranslatableMessage('Session expired'),
            self::RememberMeTokenUnrecognised => new TranslatableMessage('Unrecognised remembered device presented'),
            self::RememberMeTokenReplayed => new TranslatableMessage('Remembered device presented twice'),
            self::CrossFirewallTokenRejected => new TranslatableMessage('Device presented on the wrong firewall'),
            self::SessionTerminated => new TranslatableMessage('Session terminated'),
            self::SessionsTerminated => new TranslatableMessage('Sessions terminated'),
            self::ReloginForced => new TranslatableMessage('Sign-in required again'),
            self::MfaChallengeSucceeded => new TranslatableMessage('Second factor accepted'),
            self::MfaChallengeFailed => new TranslatableMessage('Second factor rejected'),
            self::MfaEnabled => new TranslatableMessage('Multi-factor authentication enabled'),
            self::MfaDisabled => new TranslatableMessage('Multi-factor authentication disabled'),
            self::BackupCodeUsed => new TranslatableMessage('Backup code used'),
            self::BackupCodesRegenerated => new TranslatableMessage('Backup codes regenerated'),
            self::PasswordChanged => new TranslatableMessage('Password changed'),
            self::PasswordResetRequested => new TranslatableMessage('Password reset requested'),
            self::PasswordResetCompleted => new TranslatableMessage('Password reset completed'),
            self::PasswordResetRejected => new TranslatableMessage('Password reset link refused'),
            self::SudoGranted => new TranslatableMessage('Identity confirmed'),
            self::SudoRefused => new TranslatableMessage('Identity confirmation failed'),
            self::ImpersonationStarted => new TranslatableMessage('Impersonation started'),
            self::ImpersonationStopped => new TranslatableMessage('Impersonation stopped'),
            self::ApiTokenRejected => new TranslatableMessage('API token refused'),
            self::ApiRateLimitExceeded => new TranslatableMessage('API rate limit exceeded'),
            self::RegisterAccessRefused => new TranslatableMessage('Register refused from this network'),
            self::CompanyAccessRevoked => new TranslatableMessage('Company access withdrawn'),
            self::DataExportRequested => new TranslatableMessage('Data export requested'),
            self::DataExportDownloaded => new TranslatableMessage('Data export downloaded'),
            self::ExternalAppAuthorised => new TranslatableMessage('External application authorised'),
        };
    }
}
