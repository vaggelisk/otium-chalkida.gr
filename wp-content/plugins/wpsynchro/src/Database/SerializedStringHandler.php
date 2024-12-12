<?php

namespace WPSynchro\Database;

use WPSynchro\Database\Exception\SerializedStringException;

/**
 * Handle serialized strings
 */
class SerializedStringHandler
{
    const STRING_START_PART = 's:';
    const STRING_TOKENIZE_LENGTH = 2;
    const STRING_END_PART = '";';
    const MAX_TIME_ALLOWED = 500; // ms

    /**
     * Do search/replaces in serialized data
     * @throws SerializedStringException Throws exception when serialized string is broken and no search/replace could be done
     */
    public function searchReplaceSerialized(string &$data, array $search_replaces)
    {
        $start_time = microtime(true);
        $rounds = 0;
        $offset = 0;
        $last_offset = -1;
        while (($data_pos = strpos($data, self::STRING_START_PART, $offset)) !== false) {
            $rounds++;
            // Make sure we dont do endless loops, if the serialized content is broken
            if ($offset == $last_offset) {
                throw new SerializedStringException('Skipping serialized value that is broken, so no search/replaces could be done in this value.', 0, $data);
            }
            $last_offset = $offset;

            // Every some rounds, we just check that we have not spent more time here than we should.
            if ($rounds % 10000 == 0) {
                $current_time = microtime(true);
                $time_spent_in_ms = ($current_time - $start_time) * 1000;
                if ($time_spent_in_ms > self::MAX_TIME_ALLOWED) {
                    // Likely that something is broken, so we break
                    throw new SerializedStringException(
                        'Skipping large serialized value that could not be search/replaced within reasonable time. First part of string:',
                        0,
                        substr($data, 0, 200)
                    );
                }
            }

            // Get string length
            $length_start_pos = $data_pos + self::STRING_TOKENIZE_LENGTH;
            $length_end_pos = strpos($data, ':', $length_start_pos);
            $length_length = $length_end_pos - $length_start_pos;
            $expected_string_length = substr($data, $length_start_pos, $length_length);

            // Get string value
            $string_start_pos = $length_start_pos + $length_length + 2;
            $string_end_pos = $string_start_pos + $expected_string_length;
            $string_length = $string_end_pos - $string_start_pos;

            // Check that string ends there and if not, something is wrong and we abort
            if (substr($data, $string_end_pos, 2) !== self::STRING_END_PART) {
                throw new SerializedStringException('Skipping serialized value that is broken, so no search/replaces could be done in this value.', 0, $data);
            }

            $string_value = substr($data, $string_start_pos, $string_length);

            // Set new offset
            $data_end = $string_end_pos + 2;
            $offset = $data_end;

            // Do search/replace in string value
            $string_value_replaced = $string_value;
            $search_replace_changed_string = false;
            foreach ($search_replaces as $replaces) {
                $replaces_actually_done = 0;
                $string_value_replaced = str_replace($replaces->from, $replaces->to, $string_value_replaced, $replaces_actually_done);
                if ($replaces_actually_done > 0) {
                    $search_replace_changed_string = true;
                }
            }

            // Change the string in the data, if it was changed
            if ($search_replace_changed_string) {
                $new_string = self::STRING_START_PART . strlen($string_value_replaced) . ':"' . $string_value_replaced . self::STRING_END_PART;
                $new_string_length = strlen($new_string);
                $data = substr_replace(
                    $data,
                    $new_string,
                    $data_pos,
                    $data_end - $data_pos
                );

                // Adjust our current position in serialized string, if the replaced string was longer or shorter
                $offset += $new_string_length - ($data_end - $data_pos);
            }
        }
    }
}
