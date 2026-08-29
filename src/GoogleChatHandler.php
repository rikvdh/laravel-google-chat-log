<?php

namespace Enigma;

use Closure;
use Monolog\Handler\Curl\Util;
use Throwable;
use Exception;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Monolog\Utils;

class GoogleChatHandler extends AbstractProcessingHandler
{
    /**
     * Additional logs closure.
     */
    public static Closure|null $additionalLogs = null;

    /**
     * Instance of the GoogleChatRecord util class preparing data for Google Chat API.
     */
    private readonly GoogleChatRecord $googleChatRecord;

    public function __construct(
        private readonly ?string $webhookUrl,
        int|string|Level $level = Level::Debug,
        bool $bubble = true
    ) {
        parent::__construct($level, $bubble);
        $this->googleChatRecord = new GoogleChatRecord();
    }

    /**
     * Writes the record down to the log of the implementing handler.
     *
     *
     * @throws Exception
     */
    protected function write(LogRecord $record): void
    {
        if ($this->webhookUrl === null) {
            return;
        }
        $postData = $this->googleChatRecord->getGoogleChatData($record);
        $postString = Utils::jsonEncode($postData);

        $ch = curl_init();
        $options = [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-type: application/json'],
            CURLOPT_POSTFIELDS => $postString,
        ];
        if (defined('CURLOPT_SAFE_UPLOAD')) {
            $options[CURLOPT_SAFE_UPLOAD] = true;
        }

        curl_setopt_array($ch, $options);

        Util::execute($ch);
    }

    /**
     * Card widget content.
     *
     * @return array[]
     */
    public function cardWidget(string $text, string $icon): array
    {
        return [
            'decoratedText' => [
                'startIcon' => ['knownIcon' => $icon],
                'text' => $text,
            ],
        ];
    }

    /**
     * Get the custom logs.
     *
     * @throws Exception
     */
    public function getCustomLogs(): array
    {
        $additionalLogs = GoogleChatHandler::$additionalLogs;
        if (!$additionalLogs) {
            return [];
        }

        $additionalLogs = $additionalLogs(request());
        if (!is_array($additionalLogs)) {
            throw new Exception('Data returned from the additional Log must be an array.');
        }

        $logs = [];
        foreach ($additionalLogs as $key => $value) {
            if ($value && !is_string($value)) {
                try {
                    $value = json_encode($value);
                } catch (Throwable $throwable) {
                    throw new Exception(
                        'Additional log key-value should be a string for key[' . $key .
                            ']. For logging objects, json or array, please stringify by doing json encode ' .
                            'or serialize on the value.',
                        0,
                        $throwable
                    );
                }
            }

            if (!is_numeric($key)) {
                $key = ucwords(str_replace('_', ' ', $key));
                $value = '<b>' . $key . ':</b> ' . $value;
            }
            $logs[] = $this->cardWidget($value, 'CONFIRMATION_NUMBER_ICON');
        }

        return $logs;
    }
}
