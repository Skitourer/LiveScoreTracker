<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

use BackedEnum;
use DateTimeImmutable;

/**
 * Builds a validated CompetitionConfig from the decoded `competition.toml` array.
 *
 * Every problem is collected with its location, so that the administrator sees
 * all errors at once (spec § 12.3). Unknown keys are reported too: they are
 * usually typos.
 */
final class ConfigParser
{
    private const ID_PATTERN = '/^[a-z0-9_]+$/';

    /** @var list<string> */
    private array $errors = [];

    /**
     * @param array<mixed> $data
     *
     * @throws InvalidConfig
     */
    public static function parse(array $data): CompetitionConfig
    {
        return (new self())->build($data);
    }

    /**
     * @param array<mixed> $data
     */
    private function build(array $data): CompetitionConfig
    {
        $this->allowKeys($data, '', ['edition', 'participants', 'level_groups', 'registration', 'scoring', 'workshops', 'import', 'contacts']);

        $edition = $this->edition($this->table($data, 'edition', 'edition', true));
        [$levels, $genders, $schools] = $this->participants($this->table($data, 'participants', 'participants', true));
        $levelGroups = $this->levelGroups($this->table($data, 'level_groups', 'level_groups', true), $levels);
        $registration = $this->registration($this->table($data, 'registration', 'registration', false));
        $scoring = $this->scoring($this->table($data, 'scoring', 'scoring', false), 'scoring');
        $workshops = $this->workshops($data['workshops'] ?? null, array_keys($levelGroups), $scoring);
        $importColumns = $this->importColumns($this->table($data, 'import', 'import', false));
        $contacts = $this->contacts($data['contacts'] ?? []);

        foreach (array_keys($levelGroups) as $group) {
            if (!$this->groupHasVariant($workshops, $group)) {
                $this->error("level_groups.{$group}", 'no event is defined for this level group');
            }
        }

        if ($this->errors !== [] || $edition === null) {
            throw new InvalidConfig($this->errors === [] ? ['edition: missing'] : $this->errors);
        }

        return new CompetitionConfig(
            $edition,
            $levels,
            $genders,
            $schools,
            $levelGroups,
            $registration,
            $scoring,
            $workshops,
            $importColumns,
            $contacts,
        );
    }

    // ----------------------------------------------------------------- sections

    /**
     * @param array<mixed> $t
     */
    private function edition(array $t): ?Edition
    {
        $this->allowKeys($t, 'edition', ['name', 'organizer', 'date', 'start', 'end', 'venue', 'contact_email', 'default_locale']);
        $name = $this->string($t, 'name', 'edition', true);
        $organizer = $this->string($t, 'organizer', 'edition', true);
        $date = $this->date($t, 'date', 'edition', true);
        $locale = $this->string($t, 'default_locale', 'edition', false) ?? 'fr';
        if (preg_match('/^[a-z]{2}(_[A-Z]{2})?$/', $locale) !== 1) {
            $this->error('edition.default_locale', 'must be a language code such as "fr" or "en"');
        }

        if ($name === null || $organizer === null || $date === null) {
            return null;
        }

        return new Edition(
            name: $name,
            organizer: $organizer,
            date: $date,
            defaultLocale: $locale,
            start: $this->time($t, 'start', 'edition'),
            end: $this->time($t, 'end', 'edition'),
            venue: $this->string($t, 'venue', 'edition', false),
            contactEmail: $this->string($t, 'contact_email', 'edition', false),
        );
    }

    /**
     * @param array<mixed> $t
     *
     * @return array{0: list<string>, 1: list<string>, 2: list<string>}
     */
    private function participants(array $t): array
    {
        $this->allowKeys($t, 'participants', ['levels', 'genders', 'schools']);

        return [
            $this->stringList($t, 'levels', 'participants', true),
            $this->stringList($t, 'genders', 'participants', true),
            $this->stringList($t, 'schools', 'participants', false),
        ];
    }

    /**
     * @param array<mixed> $t
     * @param list<string> $levels
     *
     * @return array<string, list<string>>
     */
    private function levelGroups(array $t, array $levels): array
    {
        $groups = [];
        $owner = [];
        foreach ($t as $group => $members) {
            $path = "level_groups.{$group}";
            if (!\is_string($group) || preg_match(self::ID_PATTERN, $group) !== 1) {
                $this->error($path, 'group names may only contain lowercase letters, digits and "_"');
                continue;
            }
            $list = $this->stringList($t, $group, 'level_groups', true);
            foreach ($list as $level) {
                if (!\in_array($level, $levels, true)) {
                    $this->error($path, \sprintf('unknown level "%s" (not in participants.levels)', $level));
                } elseif (isset($owner[$level])) {
                    $this->error($path, \sprintf('level "%s" is already in group "%s"', $level, $owner[$level]));
                } else {
                    $owner[$level] = $group;
                }
            }
            $groups[$group] = $list;
        }

        if ($t !== []) {
            foreach ($levels as $level) {
                if (!isset($owner[$level])) {
                    $this->error('level_groups', \sprintf('level "%s" belongs to no group', $level));
                }
            }
        }

        return $groups;
    }

    /**
     * @param array<mixed> $t
     */
    private function registration(array $t): Registration
    {
        $this->allowKeys($t, 'registration', ['opens_at', 'closes_at', 'quota', 'first_bib']);
        $opensAt = $this->date($t, 'opens_at', 'registration', false);
        $closesAt = $this->date($t, 'closes_at', 'registration', false);
        if ($opensAt !== null && $closesAt !== null && $opensAt >= $closesAt) {
            $this->error('registration.closes_at', 'must be after opens_at');
        }

        return new Registration(
            $opensAt,
            $closesAt,
            $this->int($t, 'quota', 'registration', 100, 1),
            $this->int($t, 'first_bib', 'registration', 1, 1),
        );
    }

    /**
     * @param array<mixed> $t
     */
    private function scoring(array $t, string $path): ScoringRules
    {
        $this->allowKeys($t, $path, ['max_score', 'floor', 'foul_score', 'dnf_score', 'tie_breakers']);
        $defaults = new ScoringRules();

        $tieBreakers = $defaults->tieBreakers;
        if (\array_key_exists('tie_breakers', $t)) {
            $tieBreakers = [];
            foreach ($this->stringList($t, 'tie_breakers', $path, false) as $i => $value) {
                $criterion = TieBreaker::tryFrom($value);
                if ($criterion === null) {
                    $this->error("{$path}.tie_breakers[{$i}]", 'must be one of ' . self::enumValues(TieBreaker::class));
                } elseif (\in_array($criterion, $tieBreakers, true)) {
                    $this->error("{$path}.tie_breakers[{$i}]", 'is listed twice');
                } else {
                    $tieBreakers[] = $criterion;
                }
            }
        }

        $rules = new ScoringRules(
            $this->int($t, 'max_score', $path, $defaults->maxScore, 1),
            $this->int($t, 'floor', $path, $defaults->floor, 0),
            $this->int($t, 'foul_score', $path, $defaults->foulScore, 0),
            $this->int($t, 'dnf_score', $path, $defaults->dnfScore, 0),
            $tieBreakers,
        );
        $this->checkPoints($rules, $path);

        return $rules;
    }

    private function checkPoints(ScoringRules $rules, string $path): void
    {
        if ($rules->floor >= $rules->maxScore) {
            $this->error("{$path}.floor", 'must be lower than max_score');
        }
        // Spec § 9.2: a foul must score less than the worst valid performance.
        if ($rules->foulScore >= $rules->floor) {
            $this->error("{$path}.foul_score", 'must be lower than floor, otherwise a foul scores like the worst valid result');
        }
        if ($rules->dnfScore > $rules->foulScore) {
            $this->error("{$path}.dnf_score", 'must not be higher than foul_score');
        }
    }

    /**
     * @param list<string> $groups
     *
     * @return list<Workshop>
     */
    private function workshops(mixed $list, array $groups, ScoringRules $scoring): array
    {
        if (!\is_array($list) || $list === [] || !array_is_list($list)) {
            $this->error('workshops', 'at least one [[workshops]] entry is required');

            return [];
        }

        $workshops = [];
        $ids = [];
        foreach ($list as $i => $w) {
            $path = "workshops[{$i}]";
            if (!\is_array($w)) {
                $this->error($path, 'must be a table');
                continue;
            }
            $this->allowKeys($w, $path, ['id', 'help', 'variants']);
            $id = $this->identifier($w, 'id', $path);
            if ($id !== null && isset($ids[$id])) {
                $this->error("{$path}.id", \sprintf('"%s" is used twice', $id));
            }
            $ids[(string) $id] = true;

            $variants = [];
            $rawVariants = $w['variants'] ?? null;
            if (!\is_array($rawVariants) || $rawVariants === [] || !array_is_list($rawVariants)) {
                $this->error("{$path}.variants", 'at least one [[workshops.variants]] entry is required');
            } else {
                $seenGroups = [];
                foreach ($rawVariants as $j => $v) {
                    $vPath = "{$path}.variants[{$j}]";
                    if (!\is_array($v)) {
                        $this->error($vPath, 'must be a table');
                        continue;
                    }
                    $variant = $this->variant($v, $vPath, (string) $id, $groups, $scoring);
                    if ($variant === null) {
                        continue;
                    }
                    if (isset($seenGroups[$variant->group])) {
                        $this->error("{$vPath}.group", \sprintf('this workshop already has a variant for "%s"', $variant->group));
                    }
                    $seenGroups[$variant->group] = true;
                    $variants[] = $variant;
                }
            }

            if ($id !== null) {
                $workshops[] = new Workshop($id, $this->string($w, 'help', $path, false), $variants);
            }
        }

        return $workshops;
    }

    /**
     * @param array<mixed> $v
     * @param list<string> $groups
     */
    private function variant(array $v, string $path, string $workshopId, array $groups, ScoringRules $globalScoring): ?Variant
    {
        // "built_in_timer" is no longer used (the built-in timer is always offered first, spec § 8.4);
        // it stays accepted so that existing configuration files remain valid.
        $this->allowKeys($v, $path, [
            'group', 'label', 'measure', 'better', 'time_format', 'attempts', 'foul_mode', 'plausible_range',
            'max_value', 'modifiers', 'built_in_timer', 'bibs_per_timer', 'limit', 'actions',
            'max_score', 'floor', 'foul_score', 'dnf_score',
        ]);

        $group = $this->string($v, 'group', $path, true);
        if ($group !== null && !\in_array($group, $groups, true)) {
            $this->error("{$path}.group", \sprintf('unknown level group "%s"', $group));
        }
        $measure = $this->enum($v, 'measure', $path, Measure::class, null);
        $better = $this->enum($v, 'better', $path, Better::class, null);
        $foulMode = $this->enum($v, 'foul_mode', $path, FoulMode::class, FoulMode::Strict);
        if ($group === null || $measure === null || $better === null || $foulMode === null) {
            return null;
        }

        $timeFormat = null;
        if ($measure === Measure::Time) {
            $timeFormat = $this->enum($v, 'time_format', $path, TimeFormat::class, TimeFormat::Seconds);
        } elseif (\array_key_exists('time_format', $v)) {
            $this->error("{$path}.time_format", 'only applies to time events');
        }

        $attempts = $this->int($v, 'attempts', $path, 1, 1);
        $isCounter = $measure === Measure::Counter;
        if ($isCounter && $attempts !== 1) {
            $this->error("{$path}.attempts", 'counter events allow a single attempt');
        }

        $maxValue = null;
        if (\array_key_exists('max_value', $v)) {
            if (\in_array($measure, [Measure::Score, Measure::Counter], true)) {
                $maxValue = $this->int($v, 'max_value', $path, 0, 1);
            } else {
                $this->error("{$path}.max_value", 'only applies to score and counter events');
            }
        }

        $bibsPerTimer = $this->int($v, 'bibs_per_timer', $path, 1, 1);
        if ($bibsPerTimer > 2) {
            $this->error("{$path}.bibs_per_timer", 'must be 1 or 2');
        }

        $scoring = $globalScoring->with(
            $this->optionalInt($v, 'max_score', $path, 1),
            $this->optionalInt($v, 'floor', $path, 0),
            $this->optionalInt($v, 'foul_score', $path, 0),
            $this->optionalInt($v, 'dnf_score', $path, 0),
        );
        $overridesScale = array_intersect(['max_score', 'floor', 'foul_score', 'dnf_score'], array_keys($v)) !== [];
        if ($overridesScale) {
            $this->checkPoints($scoring, $path);
        }

        return new Variant(
            workshopId: $workshopId,
            group: $group,
            measure: $measure,
            better: $better,
            attempts: $attempts,
            foulMode: $foulMode,
            scoring: $scoring,
            label: $this->string($v, 'label', $path, false),
            timeFormat: $timeFormat,
            plausibleRange: $this->range($v, $path, $measure),
            maxValue: $maxValue,
            modifiers: $isCounter ? $this->noneAllowed($v, 'modifiers', $path) : $this->modifiers($v, $path, $measure),
            bibsPerTimer: $bibsPerTimer,
            limit: $isCounter ? $this->limit($v, $path) : $this->noLimit($v, $path),
            actions: $isCounter ? $this->actions($v, $path) : $this->noneAllowed($v, 'actions', $path),
        );
    }

    /**
     * @param array<mixed> $v
     *
     * @return array{0: int, 1: int}|null
     */
    private function range(array $v, string $path, Measure $measure): ?array
    {
        if (!\array_key_exists('plausible_range', $v)) {
            return null;
        }
        $range = $v['plausible_range'];
        if (!\is_array($range) || \count($range) !== 2 || !self::isNumber($range[0] ?? null) || !self::isNumber($range[1] ?? null)) {
            $this->error("{$path}.plausible_range", 'must be [minimum, maximum]');

            return null;
        }
        $min = self::toStorage($range[0], $measure);
        $max = self::toStorage($range[1], $measure);
        if ($min >= $max) {
            $this->error("{$path}.plausible_range", 'minimum must be lower than maximum');

            return null;
        }

        return [$min, $max];
    }

    /**
     * @param array<mixed> $v
     *
     * @return list<Modifier>
     */
    private function modifiers(array $v, string $path, Measure $measure): array
    {
        $modifiers = [];
        $ids = [];
        foreach ($this->tableList($v, 'modifiers', $path) as $i => $m) {
            $mPath = "{$path}.modifiers[{$i}]";
            $this->allowKeys($m, $mPath, ['id', 'value', 'repeatable']);
            $id = $this->identifier($m, 'id', $mPath);
            $value = $m['value'] ?? null;
            if (!self::isNumber($value) || $value === 0) {
                $this->error("{$mPath}.value", 'must be a non-zero number (negative for a bonus on a time)');
                continue;
            }
            if ($id === null) {
                continue;
            }
            if (isset($ids[$id])) {
                $this->error("{$mPath}.id", \sprintf('"%s" is used twice', $id));
            }
            $ids[$id] = true;
            $modifiers[] = new Modifier($id, self::toStorage($value, $measure), $this->bool($m, 'repeatable', $mPath, false));
        }

        return $modifiers;
    }

    /**
     * @param array<mixed> $v
     *
     * @return list<CounterAction>
     */
    private function actions(array $v, string $path): array
    {
        $actions = [];
        $ids = [];
        foreach ($this->tableList($v, 'actions', $path) as $i => $a) {
            $aPath = "{$path}.actions[{$i}]";
            $this->allowKeys($a, $aPath, ['id', 'points']);
            $id = $this->identifier($a, 'id', $aPath);
            $points = $this->int($a, 'points', $aPath, 0, 1);
            if ($id === null) {
                continue;
            }
            if (isset($ids[$id])) {
                $this->error("{$aPath}.id", \sprintf('"%s" is used twice', $id));
            }
            $ids[$id] = true;
            $actions[] = new CounterAction($id, $points);
        }
        if ($actions === []) {
            $this->error("{$path}.actions", 'a counter event needs at least one action');
        }

        return $actions;
    }

    /**
     * @param array<mixed> $v
     */
    private function limit(array $v, string $path): ?CounterLimit
    {
        $limit = $v['limit'] ?? null;
        if (!\is_array($limit)) {
            $this->error("{$path}.limit", 'a counter event needs a limit, e.g. { type = "duration", value = "1:30" }');

            return null;
        }
        $lPath = "{$path}.limit";
        $this->allowKeys($limit, $lPath, ['type', 'value']);
        $type = $this->enum($limit, 'type', $lPath, CounterLimitType::class, null);
        if ($type === null) {
            return null;
        }
        $raw = $limit['value'] ?? null;
        $value = match ($type) {
            CounterLimitType::Duration => \is_int($raw) ? $raw : (\is_string($raw) ? self::parseDuration($raw) : null),
            CounterLimitType::Shots => \is_int($raw) ? $raw : null,
        };
        if ($value === null || $value < 1) {
            $this->error("{$lPath}.value", $type === CounterLimitType::Duration
                ? 'must be a duration such as "1:30" or a number of seconds'
                : 'must be a positive number of shots');

            return null;
        }

        return new CounterLimit($type, $value);
    }

    /**
     * @param array<mixed> $v
     */
    private function noLimit(array $v, string $path): null
    {
        if (\array_key_exists('limit', $v)) {
            $this->error("{$path}.limit", 'only applies to counter events');
        }

        return null;
    }

    /**
     * @param array<mixed> $v
     *
     * @return list<never>
     */
    private function noneAllowed(array $v, string $key, string $path): array
    {
        if (\array_key_exists($key, $v)) {
            $this->error("{$path}.{$key}", $key === 'actions' ? 'only applies to counter events' : 'does not apply to counter events');
        }

        return [];
    }

    /**
     * @param array<mixed> $t
     *
     * @return array<string, string>
     */
    private function importColumns(array $t): array
    {
        $this->allowKeys($t, 'import', ['columns']);
        $columns = [];
        foreach ($this->table($t, 'columns', 'import.columns', false) as $field => $header) {
            if (!\is_string($field) || !\is_string($header) || $header === '') {
                $this->error("import.columns.{$field}", 'must be a column header (text)');
                continue;
            }
            $columns[$field] = $header;
        }

        return $columns;
    }

    /**
     * @return list<Contact>
     */
    private function contacts(mixed $list): array
    {
        if (!\is_array($list) || !array_is_list($list)) {
            $this->error('contacts', 'must be a list of [[contacts]] entries');

            return [];
        }
        $contacts = [];
        foreach ($list as $i => $c) {
            $path = "contacts[{$i}]";
            if (!\is_array($c)) {
                $this->error($path, 'must be a table');
                continue;
            }
            $this->allowKeys($c, $path, ['role', 'name', 'phone']);
            $role = $this->identifier($c, 'role', $path);
            $name = $this->string($c, 'name', $path, true);
            $phone = $this->string($c, 'phone', $path, true);
            if ($role !== null && $name !== null && $phone !== null) {
                $contacts[] = new Contact($role, $name, $phone);
            }
        }

        return $contacts;
    }

    // ------------------------------------------------------------ value readers

    private function error(string $path, string $message): void
    {
        $this->errors[] = ($path === '' ? '' : $path . ': ') . $message;
    }

    /**
     * @param array<mixed> $t
     * @param list<string> $allowed
     */
    private function allowKeys(array $t, string $path, array $allowed): void
    {
        foreach (array_keys($t) as $key) {
            if (!\in_array($key, $allowed, true)) {
                $this->error(ltrim("{$path}.{$key}", '.'), 'unknown setting (allowed: ' . implode(', ', $allowed) . ')');
            }
        }
    }

    /**
     * @param array<mixed> $t
     *
     * @return array<mixed>
     */
    private function table(array $t, string $key, string $path, bool $required): array
    {
        if (!\array_key_exists($key, $t)) {
            if ($required) {
                $this->error($path, 'missing section');
            }

            return [];
        }
        if (!\is_array($t[$key]) || (array_is_list($t[$key]) && $t[$key] !== [])) {
            $this->error($path, 'must be a table');

            return [];
        }

        return $t[$key];
    }

    /**
     * @param array<mixed> $t
     *
     * @return list<array<mixed>>
     */
    private function tableList(array $t, string $key, string $path): array
    {
        $list = $t[$key] ?? [];
        if (!\is_array($list) || !array_is_list($list)) {
            $this->error("{$path}.{$key}", 'must be a list of tables');

            return [];
        }
        $tables = [];
        foreach ($list as $i => $item) {
            if (\is_array($item)) {
                $tables[] = $item;
            } else {
                $this->error("{$path}.{$key}[{$i}]", 'must be a table');
            }
        }

        return $tables;
    }

    /**
     * @param array<mixed> $t
     */
    private function string(array $t, string $key, string $path, bool $required): ?string
    {
        $value = $t[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->error("{$path}.{$key}", 'is required');
            }

            return null;
        }
        if (!\is_string($value) || trim($value) === '') {
            $this->error("{$path}.{$key}", 'must be a non-empty text');

            return null;
        }

        return trim($value);
    }

    /**
     * @param array<mixed> $t
     */
    private function identifier(array $t, string $key, string $path): ?string
    {
        $value = $this->string($t, $key, $path, true);
        if ($value !== null && preg_match(self::ID_PATTERN, $value) !== 1) {
            $this->error("{$path}.{$key}", 'may only contain lowercase letters, digits and "_"');

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $t
     *
     * @return list<string>
     */
    private function stringList(array $t, string $key, string $path, bool $required): array
    {
        $value = $t[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->error("{$path}.{$key}", 'is required');
            }

            return [];
        }
        if (!\is_array($value) || !array_is_list($value)) {
            $this->error("{$path}.{$key}", 'must be a list of texts');

            return [];
        }
        $list = [];
        foreach ($value as $i => $item) {
            if (!\is_string($item) || trim($item) === '') {
                $this->error("{$path}.{$key}[{$i}]", 'must be a non-empty text');
            } elseif (\in_array(trim($item), $list, true)) {
                $this->error("{$path}.{$key}[{$i}]", \sprintf('"%s" is listed twice', $item));
            } else {
                $list[] = trim($item);
            }
        }
        if ($required && $list === [] && $value === []) {
            $this->error("{$path}.{$key}", 'must not be empty');
        }

        return $list;
    }

    /**
     * @param array<mixed> $t
     */
    private function int(array $t, string $key, string $path, int $default, int $min): int
    {
        return $this->optionalInt($t, $key, $path, $min) ?? $default;
    }

    /**
     * @param array<mixed> $t
     */
    private function optionalInt(array $t, string $key, string $path, int $min): ?int
    {
        if (!\array_key_exists($key, $t)) {
            return null;
        }
        $value = $t[$key];
        if (!\is_int($value) || $value < $min) {
            $this->error("{$path}.{$key}", \sprintf('must be a whole number ≥ %d', $min));

            return null;
        }

        return $value;
    }

    /**
     * @param array<mixed> $t
     */
    private function bool(array $t, string $key, string $path, bool $default): bool
    {
        $value = $t[$key] ?? $default;
        if (!\is_bool($value)) {
            $this->error("{$path}.{$key}", 'must be true or false');

            return $default;
        }

        return $value;
    }

    /**
     * @param array<mixed> $t
     */
    private function date(array $t, string $key, string $path, bool $required): ?DateTimeImmutable
    {
        $value = $t[$key] ?? null;
        if ($value === null) {
            if ($required) {
                $this->error("{$path}.{$key}", 'is required');
            }

            return null;
        }
        if (!$value instanceof DateTimeImmutable) {
            $this->error("{$path}.{$key}", 'must be a date such as 2027-06-06 (or 2027-06-06T08:00:00)');

            return null;
        }

        // TOML local dates are wall-clock values: keep their components, drop any time zone.
        return new DateTimeImmutable($value->format('Y-m-d H:i:s'));
    }

    /**
     * @param array<mixed> $t
     */
    private function time(array $t, string $key, string $path): ?string
    {
        $value = $t[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!\is_string($value) || preg_match('/^([01]\d|2[0-3]):[0-5]\d(:[0-5]\d)?$/', $value) !== 1) {
            $this->error("{$path}.{$key}", 'must be a time such as 09:00:00');

            return null;
        }

        return substr($value, 0, 5);
    }

    /**
     * @template T of BackedEnum
     *
     * @param array<mixed>    $t
     * @param class-string<T> $enum
     * @param T|null          $default
     *
     * @return T|null
     */
    private function enum(array $t, string $key, string $path, string $enum, ?BackedEnum $default): ?BackedEnum
    {
        if (!\array_key_exists($key, $t)) {
            if ($default === null) {
                $this->error("{$path}.{$key}", 'is required (' . self::enumValues($enum) . ')');
            }

            return $default;
        }
        $value = $t[$key];
        $case = \is_string($value) ? $enum::tryFrom($value) : null;
        if ($case === null) {
            $this->error("{$path}.{$key}", 'must be one of ' . self::enumValues($enum));
        }

        return $case;
    }

    /**
     * @param list<Workshop> $workshops
     */
    private function groupHasVariant(array $workshops, string $group): bool
    {
        foreach ($workshops as $workshop) {
            foreach ($workshop->variants as $variant) {
                if ($variant->group === $group) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param class-string<BackedEnum> $enum
     */
    private static function enumValues(string $enum): string
    {
        return implode(', ', array_map(static fn(BackedEnum $case): string => (string) $case->value, $enum::cases()));
    }

    /**
     * @phpstan-assert-if-true int|float $value
     */
    private static function isNumber(mixed $value): bool
    {
        return \is_int($value) || \is_float($value);
    }

    /**
     * Converts a configuration value (seconds, metres, points) to storage units.
     */
    private static function toStorage(int|float $value, Measure $measure): int
    {
        return (int) round($value * $measure->storageFactor());
    }

    /**
     * "1:30" → 90 seconds; "45" → 45 seconds.
     */
    private static function parseDuration(string $value): ?int
    {
        if (preg_match('/^(?:(\d+):)?([0-5]?\d)$/', trim($value), $m) !== 1) {
            return null;
        }

        return (int) $m[1] * 60 + (int) $m[2];
    }
}
