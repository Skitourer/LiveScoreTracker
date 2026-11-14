<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use IntlDateFormatter;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Domain\Registration\RegistrationStatus;
use LiveScoreTracker\Domain\Security\Phase;
use LiveScoreTracker\Http\RequestContext;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Mail\MailFailed;
use LiveScoreTracker\Infrastructure\Mail\MailSkipped;
use LiveScoreTracker\Infrastructure\Persistence\AppState;
use LiveScoreTracker\Infrastructure\Persistence\RegistrationRepository;
use LiveScoreTracker\Infrastructure\Settings;

/**
 * Emails sent to families about a registration (spec § 5.3, § 5.4, § 5.5, § 12.5),
 * and notifications to the organization.
 *
 * Emails to families are written in the language chosen on the public form,
 * followed by the French version; registrations made by the organization use
 * the installation's language. The same confirmation is sent whoever
 * registered the child, and reminds volunteers that they offered to help.
 * While the competition is being prepared, the confirmation and waiting-list
 * emails carry a withdrawal link (a new link replaces the previous one).
 */
final class RegistrationMailer
{
    /** Reason of the last failed sending (technical, for the audit log). */
    public ?string $lastError = null;

    /** True when the last email was not sent because the address is fictitious. */
    public bool $lastSkipped = false;

    public function __construct(
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly CompetitionConfig $config,
        private readonly RegistrationRepository $registrations,
        private readonly AppState $appState,
        private readonly Settings $settings,
        private readonly RequestContext $context,
    ) {}

    /**
     * False when emails are only written to a file (MAIL_TRANSPORT=log): nothing reaches the families.
     */
    public function delivers(): bool
    {
        return $this->settings->mailTransport === 'smtp';
    }

    /**
     * "Please confirm the registration" email with the verification link.
     */
    public function sendVerification(Registration $registration, string $token): bool
    {
        return $this->sendToFamily($registration, 'email.signup_verify', ['link' => $this->url('/inscription/confirmer/' . $token)]);
    }

    /**
     * Sends the "registration confirmed" email to the responsible adult.
     *
     * @return bool false when the email could not be sent (the registration itself is kept)
     */
    public function sendConfirmation(Registration $registration): bool
    {
        return $this->sendToFamily($registration, 'email.registration_confirmed', [], withVolunteer: true, withWithdrawal: true);
    }

    public function sendWaitlisted(Registration $registration): bool
    {
        return $this->sendToFamily($registration, 'email.signup_waitlisted', [], withWithdrawal: true);
    }

    public function sendWithdrawn(Registration $registration): bool
    {
        return $this->sendToFamily($registration, 'email.signup_withdrawn', []);
    }

    /**
     * Tells the organization that a child went to the waiting list or was
     * withdrawn by the family (spec § 5.3, § 5.5), in the installation's language.
     * Sent to NOTIFY_EMAIL, or else to the edition's contact address.
     *
     * @param 'waitlisted'|'withdrawn' $event
     */
    public function notifyOrganizers(string $event, Registration $registration): void
    {
        $to = $this->settings->notifyEmail !== '' ? $this->settings->notifyEmail : ($this->config->edition->contactEmail ?? '');
        if ($to === '') {
            return;
        }
        $locale = $this->config->edition->defaultLocale;
        $params = [
            'child' => $registration->fullName(),
            'level' => $this->translator->labelIn($locale, 'level.' . $registration->child->level),
            'school' => $registration->child->school,
            'bib' => $registration->bib ?? '–',
            'waitlist' => $this->registrations->countByStatus()[RegistrationStatus::Waitlisted->value] ?? 0,
            'link' => $this->url('/registrations/' . $registration->id),
        ];
        try {
            $this->mailer->send(
                $to,
                $this->translator->transIn($locale, "email.organizers.{$event}.subject", $params),
                self::tidy($this->translator->transIn($locale, "email.organizers.{$event}.body", $params)),
            );
        } catch (MailFailed) {
            // The organization still sees the change on the "Registrations" screen.
        }
    }

    /**
     * @param array<string, string> $extra
     */
    private function sendToFamily(Registration $registration, string $key, array $extra, bool $withVolunteer = false, bool $withWithdrawal = false): bool
    {
        if ($withWithdrawal && $this->appState->phase() === Phase::Preparation) {
            $token = bin2hex(random_bytes(24));
            $this->registrations->setWithdrawToken($registration->id, hash('sha256', $token));
            $extra['withdraw_link'] = $this->url('/inscription/desistement/' . $token);
        }

        // Online registrations: the family's language, then French.
        $locales = $registration->locale === null
            ? [$this->config->edition->defaultLocale]
            : array_values(array_unique([$registration->locale, 'fr']));

        $subject = '';
        $parts = [];
        foreach ($locales as $index => $locale) {
            $params = $this->params($registration, $locale, $extra);
            if ($withVolunteer && $registration->child->volunteer) {
                $params['volunteer'] = $this->translator->transIn($locale, $key . '.volunteer', $params);
            }
            if (isset($extra['withdraw_link'])) {
                $params['withdraw'] = $this->translator->transIn($locale, $key . '.withdraw', [...$params, 'link' => $extra['withdraw_link']]);
            }
            if ($index === 0) {
                $subject = $this->translator->transIn($locale, $key . '.subject', $params);
            } else {
                $parts[] = "———————\n" . $this->translator->transIn('fr', 'email.common.french_version') . "\n";
            }
            $parts[] = self::tidy($this->translator->transIn($locale, $key . '.body', $params));
        }

        try {
            $this->mailer->send($registration->child->guardianEmail, $subject, implode("\n", $parts));
            $this->lastError = null;
            $this->lastSkipped = false;

            return true;
        } catch (MailFailed $failure) {
            $this->lastError = mb_substr($failure->getMessage(), 0, 300);
            $this->lastSkipped = $failure instanceof MailSkipped;

            return false;
        }
    }

    /**
     * @param array<string, string> $extra
     *
     * @return array<string, string|int>
     */
    private function params(Registration $registration, string $locale, array $extra): array
    {
        $child = $registration->child;
        $edition = $this->config->edition;

        return [
            ...$extra,
            'guardian' => $child->guardianName,
            'child' => $child->firstName . ' ' . $child->lastName,
            'first_name' => $child->firstName,
            'level' => $this->translator->labelIn($locale, 'level.' . $child->level),
            'bib' => $registration->bib ?? '–',
            'edition' => $edition->name,
            'organizer' => $edition->organizer,
            'date' => $this->longDate($edition->date, $locale),
            'start' => $edition->start ?? '',
            'venue' => $edition->venue ?? '',
            'contact' => $edition->contactEmail ?? $edition->organizer,
            'volunteer' => '',
            'withdraw' => '',
        ];
    }

    private function url(string $path): string
    {
        return ($this->settings->appUrl !== '' ? $this->settings->appUrl : $this->context->baseUrl) . $path;
    }

    /**
     * "dimanche 6 juin 2027" in the given language (ISO date if intl is missing).
     */
    private function longDate(\DateTimeImmutable $date, string $locale): string
    {
        if (!class_exists(IntlDateFormatter::class)) {
            return $date->format('d/m/Y');
        }
        $formatter = new IntlDateFormatter($locale, IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC');
        $text = $formatter->format($date);

        return \is_string($text) ? $text : $date->format('d/m/Y');
    }

    /**
     * Removes the blank lines left by empty optional paragraphs.
     */
    private static function tidy(string $body): string
    {
        return trim(preg_replace("/\n{3,}/", "\n\n", $body) ?? $body) . "\n";
    }
}
