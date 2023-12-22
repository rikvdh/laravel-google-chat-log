<?php

declare(strict_types=1);

/*
 * This file is part of the Monolog package.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Enigma;

use Monolog\Level;
use Monolog\Utils;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Formatter\FormatterInterface;
use Monolog\LogRecord;

/**
 * Google Chat record utility helping to log to Google Chat webhooks.
 *
 * @author Rik van der Heijden <mail@rikvanderheijden.com>
 * @see    https://developers.google.com/chat/how-tos/webhooks
 */
class GoogleChatRecord
{
    public const COLOR_DANGER = 'danger';

    public const COLOR_WARNING = 'warning';

    public const COLOR_GOOD = 'good';

    public const COLOR_DEFAULT = '#e3e4e6';

    /**
     * Whether the message should be added to Google Chat as attachment (plain text otherwise)
     */
    private bool $useAttachment;

    /**
     * Whether the the context/extra messages added to Google Chat as attachments are in a short style
     */
    private bool $useShortAttachment;

    /**
     * Whether the attachment should include context and extra data
     */
    private bool $includeContextAndExtra;

    /**
     * Dot separated list of fields to exclude from Google Chat message. E.g. ['context.field1', 'extra.field2']
     * @var string[]
     */
    private array $excludeFields;

    private NormalizerFormatter $normalizerFormatter;

    private array $exception = [];

    /**
     * @param string[] $excludeFields
     */
    public function __construct(
        bool $useAttachment = true,
        bool $useShortAttachment = false,
        bool $includeContextAndExtra = true,
        array $excludeFields = [],
    ) {
        $this
            ->useAttachment($useAttachment)
            ->useShortAttachment($useShortAttachment)
            ->includeContextAndExtra($includeContextAndExtra)
            ->excludeFields($excludeFields);

        if ($this->includeContextAndExtra) {
            $this->normalizerFormatter = new NormalizerFormatter();
        }
    }

    /**
     * Returns required data in format that GoogleChat
     * is expecting.
     *
     * @phpstan-return mixed[]
     */
    public function getGoogleChatData(LogRecord $record): array
    {
        $dataArray = [];

        $recordData = $this->removeExcludedFields($record);

        if ($this->useAttachment) {
            $attachment = [
                // 'header' => 'Details',
                'collapsible' => true,
                'uncollapsibleWidgetsCount' => 5,
                'widgets'    => [],
            ];

            if ($this->includeContextAndExtra) {
                foreach (['extra', 'context'] as $key) {
                    if (!isset($recordData[$key]) || \count($recordData[$key]) === 0) {
                        continue;
                    }

                    if ($this->useShortAttachment) {
                        $attachment['widgets'][] = $this->generateAttachmentField(
                            $key,
                            $recordData[$key]
                        );
                    } else {
                        // Add all extra widgets as individual widgets in attachment
                        $attachment['widgets'] = array_merge(
                            $attachment['widgets'],
                            $this->generateAttachmentFields($recordData[$key])
                        );
                    }
                }
            }

            if (count($attachment['widgets']) > 0) {
                $dataArray['cardsV2'] = [
                    'cardId' => 'info-card-id',
                    'card' => ['sections' => $attachment]
                ];
            }
        }
        $dataArray['text'] = '*' . config('app.name') . ' : ' .
            $record->level->name . ":* {$record->message}";
        if ($this->exception) {
            $e = $this->generateAttachmentField('exception', $this->exception);
            $dataArray['text'] .= ' ' . $e['decoratedText']['text'];
        }

        return $dataArray;
    }

    /**
     * Returns a Google Chat message attachment color associated with
     * provided level.
     */
    protected function colorText(Level $level, string $text): string
    {
        $color = [
            Level::Emergency->value => '#ff1100',
            Level::Alert->value => '#ff1100',
            Level::Critical->value => '#ff1100',
            Level::Error->value => '#ff1100',
            Level::Warning->value => '#ffc400',
            Level::Notice->value => '#00aeff',
            Level::Info->value => '#48d62f',
            Level::Debug->value => '#000000',
        ][$level->value] ?? '#ff1100';

        return '<font color="' . $color . '">' . $text . '</font>';
    }

    /**
     * Stringifies an array of key/value pairs to be used in attachment fields
     *
     * @param mixed[] $fields
     */
    public function stringify(array $fields): string
    {
        /** @var array<mixed> $normalized */
        $normalized = $this->normalizerFormatter->normalizeValue($fields);

        $hasSecondDimension = \count(array_filter($normalized, 'is_array')) > 0;
        $hasOnlyNonNumericKeys = \count(array_filter(array_keys($normalized), 'is_numeric')) === 0;

        return $hasSecondDimension || $hasOnlyNonNumericKeys
            ? Utils::jsonEncode($normalized, JSON_PRETTY_PRINT | Utils::DEFAULT_JSON_FLAGS)
            : Utils::jsonEncode($normalized, Utils::DEFAULT_JSON_FLAGS);
    }

    /**
     * @return $this
     */
    public function useAttachment(bool $useAttachment = true): self
    {
        $this->useAttachment = $useAttachment;

        return $this;
    }

    /**
     * @return $this
     */
    public function useShortAttachment(bool $useShortAttachment = false): self
    {
        $this->useShortAttachment = $useShortAttachment;

        return $this;
    }

    /**
     * @return $this
     */
    public function includeContextAndExtra(bool $includeContextAndExtra = false): self
    {
        $this->includeContextAndExtra = $includeContextAndExtra;

        if ($this->includeContextAndExtra) {
            $this->normalizerFormatter = new NormalizerFormatter();
        }

        return $this;
    }

    /**
     * @param string[] $excludeFields
     * @return $this
     */
    public function excludeFields(array $excludeFields = []): self
    {
        $this->excludeFields = $excludeFields;

        return $this;
    }

    /**
     * Generates attachment field
     *
     * @param string|mixed[] $value
     *
     * @return array{title: string, value: string, short: false}
     */
    private function generateAttachmentField(string $title, $value): array
    {

        if ($title == 'exception' && is_array($value)) {
            $value['file'] = str_replace(base_path() . '/', '', $value['file']);
            foreach ($value['trace'] as $k => $entry) {
                $value['trace'][$k] = str_replace(base_path() . '/', '', $entry);
            }
        }

        $value = is_array($value)
            ? sprintf('```%s```', substr($this->stringify($value), 0, 1990))
            : $value;

        return [
            'decoratedText' => [
                'startIcon' => [
                    'knownIcon' => $this->iconMapping($title),
                ],
                'topLabel' => ucfirst($title),
                'text' => (string)$value,
            ],
        ];
    }

    private function iconMapping($key)
    {
        return match ($key) {
            default => 'TICKET',
        };
    }

    /**
     * Generates a collection of attachment fields from array
     *
     * @param mixed[] $data
     *
     * @return array<array{title: string, value: string, short: false}>
     */
    private function generateAttachmentFields(array $data): array
    {
        /** @var array<mixed> $normalized */
        $normalized = $this->normalizerFormatter->normalizeValue($data);

        $fields = [];
        foreach ($normalized as $key => $value) {
            if ($key == 'exception' && is_array($value)) {
                $this->exception = $value;
                continue;
            }
            $fields[] = $this->generateAttachmentField((string) $key, $value);
        }

        return $fields;
    }

    /**
     * Get a copy of record with fields excluded according to $this->excludeFields
     *
     * @return mixed[]
     */
    private function removeExcludedFields(LogRecord $record): array
    {
        $recordData = $record->toArray();
        foreach ($this->excludeFields as $field) {
            $keys = explode('.', $field);
            $node = &$recordData;
            $lastKey = end($keys);
            foreach ($keys as $key) {
                if (!isset($node[$key])) {
                    break;
                }
                if ($lastKey === $key) {
                    unset($node[$key]);
                    break;
                }
                $node = &$node[$key];
            }
        }

        return $recordData;
    }
}
