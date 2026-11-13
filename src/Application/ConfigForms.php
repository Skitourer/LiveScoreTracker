<?php

declare(strict_types=1);

namespace LiveScoreTracker\Application;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use LiveScoreTracker\Domain\Security\Phase;

/**
 * Converts the sections of `competition.toml` to form values and back
 * (configuration screen, spec § 12.3). Validation itself is left to the
 * configuration parser, so that the screen and the file follow the same rules.
 */
final class ConfigForms
{
    /** Tabs of the screen, in display order. */
    public const TABS = ['edition', 'participants', 'registration', 'scoring', 'workshops', 'import', 'contacts', 'file'];

    /** Tabs that change the events or the categories: frozen once the competition is running (until step 12b). */
    private const FROZEN_WHILE_RUNNING = ['participants', 'workshops', 'file'];

    public const IMPORT_FIELDS = ['last_name', 'first_name', 'gender', 'level', 'birth_date', 'school', 'guardian_name', 'guardian_phone', 'guardian_email', 'volunteer'];
    public const TIE_BREAKERS = ['first_places', 'top3_places', 'youngest'];
    public const MEASURES = ['time', 'distance', 'score', 'counter'];

    public static function editable(string $tab, Phase $phase): bool
    {
        return match ($phase) {
            Phase::Preparation => true,
            Phase::Running => !\in_array($tab, self::FROZEN_WHILE_RUNNING, true),
            Phase::Finished => false,
        };
    }

    // ------------------------------------------------------------- data -> form

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public static function values(string $tab, array $data): array
    {
        $section = static fn(string $key): array => \is_array($data[$key] ?? null) ? $data[$key] : [];

        return match ($tab) {
            'edition' => ['date' => substr(self::text($section('edition')['date'] ?? null), 0, 10)]
                + self::texts($section('edition'), ['name', 'organizer', 'start', 'end', 'venue', 'contact_email', 'default_locale']),
            'participants' => [
                'levels' => self::lines($section('participants')['levels'] ?? []),
                'genders' => self::lines($section('participants')['genders'] ?? []),
                'schools' => self::lines($section('participants')['schools'] ?? []),
                'level_groups' => implode("\n", array_map(
                    static fn(string|int $group, mixed $levels): string => $group . ': ' . implode(', ', \is_array($levels) ? array_map(self::text(...), $levels) : []),
                    array_keys($section('level_groups')),
                    $section('level_groups'),
                )),
            ],
            'registration' => self::texts($section('registration'), ['opens_at', 'closes_at', 'quota', 'first_bib']),
            'scoring' => self::texts($section('scoring'), ['max_score', 'floor', 'foul_score', 'dnf_score'])
                + ['tie_breakers' => array_map(self::text(...), \is_array($section('scoring')['tie_breakers'] ?? null) ? $section('scoring')['tie_breakers'] : [])],
            'import' => self::texts(\is_array($section('import')['columns'] ?? null) ? $section('import')['columns'] : [], self::IMPORT_FIELDS),
            'contacts' => array_map(
                static fn(mixed $c): array => self::texts(\is_array($c) ? $c : [], ['role', 'label', 'name', 'phone']),
                array_values(\is_array($data['contacts'] ?? null) ? $data['contacts'] : []),
            ),
            default => [],
        };
    }

    /**
     * Form values of one workshop (with its variants).
     *
     * @param array<mixed> $workshop
     *
     * @return array<string, mixed>
     */
    public static function workshopValues(array $workshop): array
    {
        $variants = [];
        foreach (\is_array($workshop['variants'] ?? null) ? $workshop['variants'] : [] as $v) {
            $v = \is_array($v) ? $v : [];
            $range = \is_array($v['plausible_range'] ?? null) ? $v['plausible_range'] : [];
            $limit = \is_array($v['limit'] ?? null) ? $v['limit'] : [];
            $variants[] = self::texts($v, ['group', 'label', 'measure', 'time_format', 'better', 'attempts', 'foul_mode', 'bibs_per_timer', 'max_value']) + [
                'range_min' => self::text($range[0] ?? null),
                'range_max' => self::text($range[1] ?? null),
                'limit_type' => self::text($limit['type'] ?? null),
                'limit_value' => self::text($limit['value'] ?? null),
                'modifiers' => array_map(
                    static fn(mixed $m): array => self::texts(\is_array($m) ? $m : [], ['id', 'name', 'value']) + ['repeatable' => (\is_array($m) ? $m['repeatable'] ?? false : false) === true],
                    array_values(\is_array($v['modifiers'] ?? null) ? $v['modifiers'] : []),
                ),
                'actions' => array_map(
                    static fn(mixed $a): array => self::texts(\is_array($a) ? $a : [], ['id', 'name', 'points']),
                    array_values(\is_array($v['actions'] ?? null) ? $v['actions'] : []),
                ),
            ];
        }

        return self::texts($workshop, ['id', 'name', 'help']) + ['variants' => $variants];
    }

    // ------------------------------------------------------------- form -> data

    /**
     * Applies a submitted tab to the decoded configuration.
     *
     * @param array<mixed> $data
     * @param array<mixed> $form
     *
     * @return array<mixed>
     */
    public static function apply(string $tab, array $data, array $form): array
    {
        $field = static fn(string $name): string => trim(\is_string($form[$name] ?? null) ? $form[$name] : '');

        switch ($tab) {
            case 'edition':
                $edition = \is_array($data['edition'] ?? null) ? $data['edition'] : [];
                foreach (['name', 'organizer', 'venue', 'contact_email', 'default_locale'] as $key) {
                    self::put($edition, $key, $field($key));
                }
                self::put($edition, 'date', self::date($field('date')));
                self::put($edition, 'start', self::time($field('start')));
                self::put($edition, 'end', self::time($field('end')));
                $data['edition'] = $edition;
                break;

            case 'participants':
                $participants = \is_array($data['participants'] ?? null) ? $data['participants'] : [];
                $participants['levels'] = self::list($field('levels'));
                $participants['genders'] = self::list($field('genders'));
                $participants['schools'] = self::list($field('schools'));
                $data['participants'] = $participants;
                $groups = [];
                foreach (self::list($field('level_groups')) as $line) {
                    [$group, $levels] = array_pad(explode(':', $line, 2), 2, '');
                    $groups[trim($group)] = array_values(array_filter(array_map('trim', explode(',', $levels)), static fn(string $l): bool => $l !== ''));
                }
                $data['level_groups'] = $groups;
                break;

            case 'registration':
                $registration = \is_array($data['registration'] ?? null) ? $data['registration'] : [];
                self::put($registration, 'opens_at', self::dateTime($field('opens_at')));
                self::put($registration, 'closes_at', self::dateTime($field('closes_at')));
                self::put($registration, 'quota', self::number($field('quota')));
                self::put($registration, 'first_bib', self::number($field('first_bib')));
                $data['registration'] = $registration;
                break;

            case 'scoring':
                $scoring = \is_array($data['scoring'] ?? null) ? $data['scoring'] : [];
                foreach (['max_score', 'floor', 'foul_score', 'dnf_score'] as $key) {
                    self::put($scoring, $key, self::number($field($key)));
                }
                $chosen = \is_array($form['tie_breakers'] ?? null) ? $form['tie_breakers'] : [];
                $scoring['tie_breakers'] = array_values(array_filter(array_map(static fn(mixed $t): string => \is_string($t) ? trim($t) : '', $chosen), static fn(string $t): bool => $t !== ''));
                $data['scoring'] = $scoring;
                break;

            case 'import':
                $columns = [];
                foreach (self::IMPORT_FIELDS as $key) {
                    if ($field($key) !== '') {
                        $columns[$key] = $field($key);
                    }
                }
                $import = \is_array($data['import'] ?? null) ? $data['import'] : [];
                $import['columns'] = $columns;
                $data['import'] = $import;
                if ($columns === []) {
                    unset($data['import']);
                }
                break;

            case 'contacts':
                $contacts = [];
                foreach (self::rows($form['contacts'] ?? null) as $row) {
                    $contact = [];
                    foreach (['role', 'label', 'name', 'phone'] as $key) {
                        self::put($contact, $key, $row[$key]);
                    }
                    if ($contact !== []) {
                        $contacts[] = $contact;
                    }
                }
                $data['contacts'] = $contacts;
                break;
        }

        return $data;
    }

    /**
     * Builds one workshop from its form; the existing workshop keeps the keys the form does not show.
     *
     * @param array<mixed> $existing
     * @param array<mixed> $form
     *
     * @return array<mixed>
     */
    public static function workshop(array $existing, array $form): array
    {
        $workshop = $existing;
        foreach (['id', 'name', 'help'] as $key) {
            self::put($workshop, $key, trim(\is_string($form[$key] ?? null) ? $form[$key] : ''));
        }
        $oldVariants = \is_array($existing['variants'] ?? null) ? array_values($existing['variants']) : [];
        $variants = [];
        foreach (self::rows($form['variants'] ?? null) as $i => $row) {
            if (($row['delete'] ?? '') === '1' || $row['group'] === '') {
                continue;
            }
            $old = \is_array($oldVariants[$i] ?? null) ? $oldVariants[$i] : [];
            $rawVariants = \is_array($form['variants'] ?? null) ? $form['variants'] : [];
            $variants[] = self::variant($old, $row, \is_array($rawVariants[$i] ?? null) ? $rawVariants[$i] : []);
        }
        $workshop['variants'] = $variants;

        // Key order of the written file: id, name, help, then the variants.
        return array_merge(array_intersect_key(['id' => null, 'name' => null, 'help' => null], $workshop), $workshop);
    }

    /**
     * @param array<mixed>          $old
     * @param array<string, string> $row
     * @param array<mixed>          $raw
     *
     * @return array<mixed>
     */
    private static function variant(array $old, array $row, array $raw): array
    {
        $v = $old;
        $measure = $row['measure'];
        foreach (['group', 'label', 'measure', 'better', 'foul_mode'] as $key) {
            self::put($v, $key, $row[$key]);
        }
        self::put($v, 'attempts', self::number($row['attempts']));
        self::put($v, 'time_format', $measure === 'time' ? $row['time_format'] : '');
        self::put($v, 'bibs_per_timer', $measure === 'time' ? self::number($row['bibs_per_timer']) : '');
        self::put($v, 'max_value', \in_array($measure, ['score', 'counter'], true) ? self::number($row['max_value']) : '');
        $min = self::number($row['range_min']);
        $max = self::number($row['range_max']);
        self::put($v, 'plausible_range', $min !== '' && $max !== '' && $measure !== 'counter' ? [(float) $min, (float) $max] : '');

        if ($measure === 'counter') {
            $value = $row['limit_type'] === 'shots' ? self::number($row['limit_value']) : $row['limit_value'];
            self::put($v, 'limit', $row['limit_type'] !== '' && $value !== '' ? ['type' => $row['limit_type'], 'value' => $value] : '');
            $actions = [];
            foreach (self::rows($raw['actions'] ?? null) as $a) {
                if ($a['id'] !== '') {
                    $action = ['id' => $a['id']];
                    self::put($action, 'name', $a['name']);
                    $action['points'] = self::number($a['points']);
                    $actions[] = $action;
                }
            }
            self::put($v, 'actions', $actions === [] ? '' : $actions);
            unset($v['modifiers']);
        } else {
            unset($v['limit'], $v['actions']);
            $modifiers = [];
            foreach (self::rows($raw['modifiers'] ?? null) as $m) {
                if ($m['id'] !== '') {
                    $modifier = ['id' => $m['id']];
                    self::put($modifier, 'name', $m['name']);
                    $modifier['value'] = self::number($m['value']);
                    if (($m['repeatable'] ?? '') === '1') {
                        $modifier['repeatable'] = true;
                    }
                    $modifiers[] = $modifier;
                }
            }
            self::put($v, 'modifiers', $modifiers === [] ? '' : $modifiers);
        }

        return $v;
    }

    // ------------------------------------------------------------------ helpers

    /**
     * Sets a key, or removes it when the value is empty (optional settings).
     *
     * @param array<mixed> $table
     */
    private static function put(array &$table, string $key, mixed $value): void
    {
        if ($value === '' || $value === null) {
            unset($table[$key]);
        } else {
            $table[$key] = $value;
        }
    }

    /**
     * Rows of a repeated group of fields (contacts, variants…), as trimmed texts.
     *
     * @return array<int, array<string, string>>
     */
    private static function rows(mixed $rows): array
    {
        $result = [];
        foreach (\is_array($rows) ? $rows : [] as $i => $row) {
            if (!\is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($row as $key => $value) {
                if (\is_string($key)) {
                    $clean[$key] = \is_string($value) ? trim($value) : '';
                }
            }
            $result[(int) $i] = $clean + ['group' => '', 'label' => '', 'measure' => '', 'better' => '', 'foul_mode' => '', 'attempts' => '', 'time_format' => '', 'bibs_per_timer' => '', 'max_value' => '', 'range_min' => '', 'range_max' => '', 'limit_type' => '', 'limit_value' => '', 'id' => '', 'name' => '', 'value' => '', 'points' => '', 'role' => '', 'phone' => ''];
        }

        return $result;
    }

    /**
     * @param array<mixed> $table
     * @param list<string> $keys
     *
     * @return array<string, string>
     */
    private static function texts(array $table, array $keys): array
    {
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = self::text($table[$key] ?? null);
        }

        return $values;
    }

    private static function text(mixed $value): string
    {
        return match (true) {
            $value instanceof DateTimeInterface => $value->format('Y-m-d\TH:i'),
            \is_bool($value) => $value ? '1' : '',
            \is_float($value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
            \is_scalar($value) => (string) $value,
            default => '',
        };
    }

    private static function lines(mixed $list): string
    {
        return \is_array($list) ? implode("\n", array_map(self::text(...), $list)) : '';
    }

    /**
     * @return list<string>
     */
    private static function list(string $text): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/', $text) ?: []), static fn(string $line): bool => $line !== ''));
    }

    /**
     * A number when the text is one (int or float), otherwise the text itself,
     * so that the parser reports it.
     */
    private static function number(string $text): int|float|string
    {
        $text = str_replace(',', '.', $text);
        if (preg_match('/^-?\d+$/', $text) === 1) {
            return (int) $text;
        }

        return is_numeric($text) ? (float) $text : $text;
    }

    private static function date(string $text): DateTimeImmutable|string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));

        return $date === false ? $text : $date;
    }

    private static function dateTime(string $text): DateTimeImmutable|string
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new DateTimeZone('UTC'));

        return $date === false ? $text : $date;
    }

    private static function time(string $text): string
    {
        return preg_match('/^\d{2}:\d{2}$/', $text) === 1 ? $text . ':00' : $text;
    }
}
