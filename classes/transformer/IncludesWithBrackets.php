<?php

namespace Octobro\API\Classes\Transformer;

trait IncludesWithBrackets
{
    /**
     * Transform bracket notation to dot notation for includes
     * Example: type(id,code) -> type.id,type.code
     *
     * @param string $includes
     * @return string
     */
    public static function transformBracketToDotNotation(string $includes): string
    {
        // Process each include segment separately, but only split at top level
        $segments = self::splitTopLevelCommas($includes);
        $result = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // If segment contains bracket notation
            if (strpos($segment, '(') !== false && strpos($segment, ')') !== false) {
                $result = array_merge($result, self::processBracketNotation($segment));
            } else {
                $result[] = $segment;
            }
        }

        return implode(',', $result);
    }

    /**
     * Process a single bracket notation segment
     * Example: type(id,code) -> [type.id, type.code]
     * Preserves parameter notation like thumb:size(250|32)
     *
     * @param string $segment
     * @return array
     */
    protected static function processBracketNotation(string $segment): array
    {
        // Find the position of the first opening bracket
        $openPos = strpos($segment, '(');
        if ($openPos === false) {
            return [$segment];
        }

        // Check if this is parameter notation (has colon before bracket without comma between)
        $colonPos = strrpos(substr($segment, 0, $openPos), ':');
        if ($colonPos !== false) {
            // Check if there's a comma between colon and bracket
            $commaPos = strpos(substr($segment, $colonPos, $openPos - $colonPos), ',');
            if ($commaPos === false) {
                // This is parameter notation, return as is
                return [$segment];
            }
        }

        // Get the relation name (everything before the first opening bracket)
        $relation = substr($segment, 0, $openPos);

        // Find the matching closing bracket
        $closePos = self::findMatchingClosingBracket($segment, $openPos);
        if ($closePos === false) {
            return [$segment];
        }

        // Get the content inside the brackets
        $content = substr($segment, $openPos + 1, $closePos - $openPos - 1);

        // Split the content by commas, but only at the top level
        $fields = self::splitTopLevelCommas($content);

        // Add the relation itself to the result
        $dotNotation = [$relation];

        // Transform to dot notation
        foreach ($fields as $field) {
            $field = trim($field);

            // Check if field contains parameter notation
            $fieldOpenPos = strpos($field, '(');
            if ($fieldOpenPos !== false) {
                $fieldColonPos = strrpos(substr($field, 0, $fieldOpenPos), ':');
                if ($fieldColonPos !== false) {
                    // Check if there's a comma between colon and bracket
                    $fieldCommaPos = strpos(substr($field, $fieldColonPos, $fieldOpenPos - $fieldColonPos), ',');
                    if ($fieldCommaPos === false) {
                        // This is parameter notation, preserve it
                        $dotNotation[] = $relation . '.' . $field;
                        continue;
                    }
                }
            }

            // If field contains bracket notation, process it recursively
            if (strpos($field, '(') !== false && strpos($field, ')') !== false) {
                $subResults = self::processBracketNotation($field);
                foreach ($subResults as $subResult) {
                    $dotNotation[] = $relation . '.' . $subResult;
                }
            } else {
                $dotNotation[] = $relation . '.' . $field;
            }
        }

        return $dotNotation;
    }

    /**
     * Find the position of the matching closing bracket
     *
     * @param string $str
     * @param int $openPos
     * @return int|false
     */
    protected static function findMatchingClosingBracket(string $str, int $openPos)
    {
        $level = 0;
        $len = strlen($str);

        for ($i = $openPos; $i < $len; $i++) {
            if ($str[$i] === '(') {
                $level++;
            } elseif ($str[$i] === ')') {
                $level--;
                if ($level === 0) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Check if the includes string contains bracket notation that should be transformed
     * Ignores parameter notation like ":size(250|32)" which should not be transformed (re: по-моему это уже решено)
     *
     * @param string $includes
     * @return bool
     */
    public static function containsBracketNotation(string $includes): bool
    {
        // Split by commas at top level
        $segments = self::splitTopLevelCommas($includes);

        foreach ($segments as $segment) {
            $segment = trim($segment);

            // Check for opening bracket
            $openPos = strpos($segment, '(');
            if ($openPos === false) {
                continue;
            }

            // Check if this is parameter notation (has colon before bracket without comma between)
            $colonPos = strrpos(substr($segment, 0, $openPos), ':');
            if ($colonPos !== false) {
                // Check if there's a comma between colon and bracket
                $commaPos = strpos(substr($segment, $colonPos, $openPos - $colonPos), ',');
                if ($commaPos === false) {
                    // This is parameter notation, skip it
                    continue;
                }
            }

            // This is bracket notation
            return true;
        }

        return false;
    }

    /**
     * Split a string by commas, but only at the top level
     * Example: "a,b(c,d),e" -> ["a", "b(c,d)", "e"]
     *
     * @param string $str
     * @return array
     */
    protected static function splitTopLevelCommas(string $str): array
    {
        $result = [];
        $current = '';
        $level = 0;
        $len = strlen($str);

        for ($i = 0; $i < $len; $i++) {
            $char = $str[$i];

            if ($char === '(') {
                $level++;
                $current .= $char;
            } elseif ($char === ')') {
                $level--;
                $current .= $char;
            } elseif ($char === ',' && $level === 0) {
                $result[] = $current;
                $current = '';
            } else {
                $current .= $char;
            }
        }

        if ($current !== '') {
            $result[] = $current;
        }

        return $result;
    }
}
