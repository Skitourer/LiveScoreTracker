<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use IntlDateFormatter;
use LiveScoreTracker\Domain\Config\CompetitionConfig;
use LiveScoreTracker\Domain\Registration\Registration;
use LiveScoreTracker\Infrastructure\I18n\Translator;
use LiveScoreTracker\Infrastructure\Mail\Mailer;
use LiveScoreTracker\Infrastructure\Mail\MailFailed;

/**
 * Emails sent to families about a registration (spec § 5.3, § 5.4, § 12.5).
 *
 * The same confirmation is sent whoever registered the child (online form,
 * admin, organizer), and reminds volunteers that they offered to help.
 */
final class RegistrationMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly CompetitionConfig $config,
    ) {}

    /**
     * Sends the "registration confirmed" email to the responsible adult.
     *
     * @return bool false when the email could not be sent (the registration itself is kept)
     */
    public function sendConfirmation(Registration $registration): bool
    {
        $child = $registration->child;
        $edition = $this->config->edition;
        $params = [
            'guardian' => $child->guardianName,
            'child' => $child->firstName . ' ' . $child->lastName,
            'first_name' => $child->firstName,
            'level' => $this->translator->label('level.' . $child->level),
            'bib' => $registration->bib ?? '–',
            'edition' => $edition->name,
            'organizer' => $edition->organizer,
            'date' => $this->longDate($edition->date),
            'start' => $edition->start ?? '',
            'venue' => $edition->venue ?? '',
            'contact' => $edition->contactEmail ?? $edition->organizer,
        ];
        $params['volunteer'] = $child->volunteer ? $this->translator->trans('email.registration_confirmed.volunteer', $params) : '';

        try {
            $this->mailer->send(
                $child->guardianEmail,
                $this->translator->trans('email.registration_confirmed.subject', $params),
                self::tidy($this->translator->trans('email.registration_confirmed.body', $params)),
            );

            return true;
        } catch (MailFailed) {
            return false;
        }
    }

    /**
     * "dimanche 6 juin 2027" in the installation's language (ISO date if intl is missing).
     */
    private function longDate(\DateTimeImmutable $date): string
    {
        if (!class_exists(IntlDateFormatter::class)) {
            return $date->format('d/m/Y');
        }
        $formatter = new IntlDateFormatter($this->translator->locale(), IntlDateFormatter::FULL, IntlDateFormatter::NONE, 'UTC');
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
