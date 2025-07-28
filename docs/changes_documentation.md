# Changes to Bracket-to-Dot Notation Transformation

## Issue Description

The `processIncludedResources` method in the `Transformer` class had an issue with the transformation of bracket notation to dot notation. The method was incorrectly identifying parameter notation (like `:size(250|32)`) as bracket notation that needed transformation, and it wasn't properly preserving parameter notation during transformation.

## Changes Made

### 1. Added `containsBracketNotation` Method

Added a new method to correctly identify bracket notation while ignoring parameter notation:

```php
/**
 * Check if the includes string contains bracket notation that should be transformed
 * Ignores parameter notation like ":size(250|32)" which should not be transformed
 *
 * @param string $includes
 * @return bool
 */
protected function containsBracketNotation(string $includes): bool
{
    // Split by commas at top level
    $segments = $this->splitTopLevelCommas($includes);

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
```

### 2. Updated `processIncludedResources` Method

Updated the condition in `processIncludedResources` to use the new `containsBracketNotation` method instead of the simple `strpos` check:

```php
// Check if includes contains bracket notation (but not just parameter notation)
if ($includes && $this->containsBracketNotation($includes)) {
    // Transform bracket notation to dot notation
    $dotIncludes = $this->transformBracketToDotNotation($includes);

    // Parse the transformed includes
    $scope->getManager()->parseIncludes($dotIncludes);
} elseif ($includes) {
    // If no bracket notation, just parse the includes as is
    $scope->getManager()->parseIncludes($includes);
}
```

### 3. Updated `processBracketNotation` Method

Updated the `processBracketNotation` method to preserve parameter notation during transformation:

```php
/**
 * Process a single bracket notation segment
 * Example: type(id,code) -> [type.id, type.code]
 * Preserves parameter notation like thumb:size(250|32)
 *
 * @param string $segment
 * @return array
 */
protected function processBracketNotation(string $segment): array
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
    $closePos = $this->findMatchingClosingBracket($segment, $openPos);
    if ($closePos === false) {
        return [$segment];
    }

    // Get the content inside the brackets
    $content = substr($segment, $openPos + 1, $closePos - $openPos - 1);

    // Split the content by commas, but only at the top level
    $fields = $this->splitTopLevelCommas($content);

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
            $subResults = $this->processBracketNotation($field);
            foreach ($subResults as $subResult) {
                $dotNotation[] = $relation . '.' . $subResult;
            }
        } else {
            $dotNotation[] = $relation . '.' . $field;
        }
    }

    return $dotNotation;
}
```

## Testing

The changes were tested with various include patterns:

1. Simple bracket notation: `operation_date,processed_at,type_id,type(name,code)`
2. Parameter notation: `check_templates,document_groups_require_password,orderArts.art.thumb:size(250%7C32)`
3. Mixed bracket and parameter notation: `operation_date,processed_at,type_id,type(name,code),art(thumb:size(250:50))`
4. Nested bracket notation: `user(profile(avatar,bio),roles(name,permissions(name)))`
5. Multiple parameters: `products.product:filter(active):sort(name),orderArts.art.thumb:size(250%7C32):quality(high)`
6. Parameter with comma in brackets: `image:crop(100,200,300,400)`
7. Bracket notation with parameter inside: `order(items(product:filter(active),quantity))`
8. Empty brackets: `type(),user(profile())`

All test cases passed, confirming that the implementation correctly handles all scenarios described in the issue.
