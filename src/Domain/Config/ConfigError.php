<?php

declare(strict_types=1);

namespace LiveScoreTracker\Domain\Config;

/**
 * One problem found in `competition.toml`: where, what (a message key) and
 * the values to show. The configuration screen translates it with the key
 * `config.error.<key>`; the command line and the logs use the English text.
 */
final class ConfigError
{
    /** English texts, used outside the screen (command line, logs, tests). */
    public const MESSAGES = [
        'no_event_for_group' => 'no event is defined for this level group',
        'locale_code' => 'must be a language code such as "fr" or "en"',
        'group_name' => 'group names may only contain lowercase letters, digits and "_"',
        'unknown_level' => 'unknown level "{level}" (not in participants.levels)',
        'level_twice' => 'level "{level}" is already in group "{group}"',
        'level_no_group' => 'level "{level}" belongs to no group',
        'closes_before_opens' => 'must be after opens_at',
        'one_of' => 'must be one of {values}',
        'listed_twice' => 'is listed twice',
        'floor_max' => 'must be lower than max_score',
        'foul_floor' => 'must be lower than floor, otherwise a foul scores like the worst valid result',
        'dnf_foul' => 'must not be higher than foul_score',
        'workshops_required' => 'at least one [[workshops]] entry is required',
        'table' => 'must be a table',
        'used_twice' => '"{id}" is used twice',
        'variants_required' => 'at least one [[workshops.variants]] entry is required',
        'variant_group_twice' => 'this workshop already has a variant for "{group}"',
        'unknown_group' => 'unknown level group "{group}"',
        'time_only' => 'only applies to time events',
        'counter_single_attempt' => 'counter events allow a single attempt',
        'max_value_only' => 'only applies to score and counter events',
        'bibs_per_timer' => 'must be 1 or 2',
        'range_format' => 'must be [minimum, maximum]',
        'range_order' => 'minimum must be lower than maximum',
        'modifier_value' => 'must be a non-zero number (negative for a bonus on a time)',
        'counter_action_required' => 'a counter event needs at least one action',
        'counter_limit_required' => 'a counter event needs a limit, e.g. { type = "duration", value = "1:30" }',
        'limit_duration' => 'must be a duration such as "1:30" or a number of seconds',
        'limit_shots' => 'must be a positive number of shots',
        'counter_only' => 'only applies to counter events',
        'not_counter' => 'does not apply to counter events',
        'column_header' => 'must be a column header (text)',
        'contacts_list' => 'must be a list of [[contacts]] entries',
        'unknown_setting' => 'unknown setting (allowed: {allowed})',
        'missing_section' => 'missing section',
        'table_list' => 'must be a list of tables',
        'required' => 'is required',
        'text' => 'must be a non-empty text',
        'identifier' => 'may only contain lowercase letters, digits and "_"',
        'text_list' => 'must be a list of texts',
        'item_listed_twice' => '"{item}" is listed twice',
        'not_empty' => 'must not be empty',
        'whole_number' => 'must be a whole number ≥ {min}',
        'boolean' => 'must be true or false',
        'date' => 'must be a date such as 2027-06-06 (or 2027-06-06T08:00:00)',
        'time' => 'must be a time such as 09:00:00',
        'required_one_of' => 'is required ({values})',
        'file_unreadable' => 'file not found or not readable',
        'toml_syntax' => 'TOML syntax error: {detail}',
    ];

    /**
     * @param array<string, string|int> $params
     */
    public function __construct(
        public readonly string $path,
        public readonly string $key,
        public readonly array $params = [],
    ) {}

    public function english(): string
    {
        $replacements = [];
        foreach ($this->params as $name => $value) {
            $replacements['{' . $name . '}'] = (string) $value;
        }
        $message = strtr(self::MESSAGES[$this->key] ?? $this->key, $replacements);

        return $this->path === '' ? $message : $this->path . ': ' . $message;
    }
}
