<?php

declare(strict_types=1);

namespace LiveScoreTracker\Action\Registration;

use LiveScoreTracker\Action\Support\Responder;
use LiveScoreTracker\Domain\Registration\ChildData;
use LiveScoreTracker\Domain\Registration\ChildValidator;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Reading the registration form fields.
 */
final class RegistrationForm
{
    /**
     * @return array<string, string>
     */
    public static function input(ServerRequestInterface $request): array
    {
        $input = [];
        foreach (ChildValidator::FIELDS as $field) {
            $input[$field] = Responder::field($request, $field);
        }
        $input['volunteer'] = $input['volunteer'] === '1' ? 'oui' : '';

        return $input;
    }

    /**
     * Form values from stored data (for editing).
     *
     * @return array<string, string>
     */
    public static function fromChild(ChildData $child): array
    {
        $values = [];
        foreach ($child->toArray() as $field => $value) {
            $values[$field] = match (true) {
                \is_bool($value) => $value ? 'oui' : '',
                default => (string) $value,
            };
        }

        return $values;
    }
}
