<?php

// Simple function to simulate the transformation logic
function transformBracketToDotNotation(string $includes): string
{
    // Process each include segment separately, but only split at top level
    $segments = splitTopLevelCommas($includes);
    $result = [];

    foreach ($segments as $segment) {
        $segment = trim($segment);

        // If segment contains bracket notation
        if (strpos($segment, '(') !== false && strpos($segment, ')') !== false) {
            $result = array_merge($result, processBracketNotation($segment));
        } else {
            $result[] = $segment;
        }
    }

    return implode(',', $result);
}

function processBracketNotation(string $segment): array
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
    $closePos = findMatchingClosingBracket($segment, $openPos);
    if ($closePos === false) {
        return [$segment];
    }

    // Get the content inside the brackets
    $content = substr($segment, $openPos + 1, $closePos - $openPos - 1);

    // Split the content by commas, but only at the top level
    $fields = splitTopLevelCommas($content);

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
            $subResults = processBracketNotation($field);
            foreach ($subResults as $subResult) {
                $dotNotation[] = $relation . '.' . $subResult;
            }
        } else {
            $dotNotation[] = $relation . '.' . $field;
        }
    }

    return $dotNotation;
}

function findMatchingClosingBracket(string $str, int $openPos)
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

function splitTopLevelCommas(string $str): array
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

// Function to check if includes should be transformed
function shouldTransformIncludes(string $includes): bool
{
    // Split by commas at top level
    $segments = splitTopLevelCommas($includes);

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

// Test cases
$testCases = [
    'Simple bracket notation' => 'operation_date,processed_at,type_id,type(name,code)',
    'Parameter notation' => 'check_templates,document_groups_require_password,orderArts.art.thumb:size(250%7C32)',
    'Mixed bracket and parameter notation' => 'operation_date,processed_at,type_id,type(name,code),art(thumb:size(250:50))',
    'Nested bracket notation' => 'user(profile(avatar,bio),roles(name,permissions(name)))',
    'Edge case: Multiple parameters' => 'products.product:filter(active):sort(name),orderArts.art.thumb:size(250%7C32):quality(high)',
    'Edge case: Parameter with comma in brackets' => 'image:crop(100,200,300,400)',
    'Edge case: Bracket notation with parameter inside' => 'order(items(product:filter(active),quantity))',
    'Edge case: Empty brackets' => 'type(),user(profile())',
];

echo "Current behavior:\n";
echo "================\n\n";

foreach ($testCases as $name => $includes) {
    echo "$name:\n";
    echo "Original: $includes\n";

    $shouldTransform = shouldTransformIncludes($includes);
    echo "Should transform? " . ($shouldTransform ? "Yes" : "No") . "\n";

    if ($shouldTransform) {
        $transformed = transformBracketToDotNotation($includes);
        echo "Transformed: $transformed\n";
    }

    echo "\n";
}
