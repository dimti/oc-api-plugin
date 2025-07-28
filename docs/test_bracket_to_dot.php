<?php

// Mock the Transformer class to test the transformBracketToDotNotation method
class TestTransformer {
    /**
     * Transform bracket notation to dot notation for includes
     * Example: type(id,code) -> type.id,type.code
     *
     * @param string $includes
     * @return string
     */
    public function transformBracketToDotNotation(string $includes): string
    {
        echo "DEBUG: Input to transformBracketToDotNotation: $includes\n";

        // Process each include segment separately, but only split at top level
        $segments = $this->splitTopLevelCommas($includes);
        echo "DEBUG: Segments after splitTopLevelCommas: " . print_r($segments, true) . "\n";

        $result = [];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            echo "DEBUG: Processing segment: $segment\n";

            // If segment contains bracket notation
            if (strpos($segment, '(') !== false && strpos($segment, ')') !== false) {
                echo "DEBUG: Segment contains bracket notation\n";
                $processed = $this->processBracketNotation($segment);
                echo "DEBUG: Result of processBracketNotation: " . print_r($processed, true) . "\n";
                $result = array_merge($result, $processed);
            } else {
                echo "DEBUG: Segment does not contain bracket notation\n";
                $result[] = $segment;
            }

            echo "DEBUG: Result after processing segment: " . print_r($result, true) . "\n";
        }

        $finalResult = implode(',', $result);
        echo "DEBUG: Final result: $finalResult\n";

        return $finalResult;
    }

    /**
     * Process a single bracket notation segment
     *
     * @param string $segment
     * @return array
     */
    public function processBracketNotation(string $segment): array
    {
        echo "DEBUG: processBracketNotation input: $segment\n";

        // Find the position of the first opening bracket
        $openPos = strpos($segment, '(');
        if ($openPos === false) {
            echo "DEBUG: No opening bracket found\n";
            return [$segment];
        }

        // Get the relation name (everything before the first opening bracket)
        $relation = substr($segment, 0, $openPos);
        echo "DEBUG: Relation: $relation\n";

        // Find the matching closing bracket
        $closePos = $this->findMatchingClosingBracket($segment, $openPos);
        if ($closePos === false) {
            echo "DEBUG: No matching closing bracket found\n";
            return [$segment];
        }
        echo "DEBUG: Closing bracket position: $closePos\n";

        // Get the content inside the brackets
        $content = substr($segment, $openPos + 1, $closePos - $openPos - 1);
        echo "DEBUG: Content inside brackets: $content\n";

        // Split the content by commas, but only at the top level
        $fields = $this->splitTopLevelCommas($content);
        echo "DEBUG: Fields after splitting: " . print_r($fields, true) . "\n";

        // Transform to dot notation
        $dotNotation = [];
        foreach ($fields as $field) {
            $field = trim($field);
            echo "DEBUG: Processing field: $field\n";

            // If field contains bracket notation, process it recursively
            if (strpos($field, '(') !== false && strpos($field, ')') !== false) {
                echo "DEBUG: Field contains bracket notation, processing recursively\n";
                $subResults = $this->processBracketNotation($field);
                echo "DEBUG: Recursive processing result: " . print_r($subResults, true) . "\n";
                foreach ($subResults as $subResult) {
                    $dotResult = $relation . '.' . $subResult;
                    echo "DEBUG: Adding dot result: $dotResult\n";
                    $dotNotation[] = $dotResult;
                }
            } else {
                $dotResult = $relation . '.' . $field;
                echo "DEBUG: Adding dot result: $dotResult\n";
                $dotNotation[] = $dotResult;
            }
        }

        echo "DEBUG: Final dot notation: " . print_r($dotNotation, true) . "\n";
        return $dotNotation;
    }

    /**
     * Find the position of the matching closing bracket
     *
     * @param string $str
     * @param int $openPos
     * @return int|false
     */
    public function findMatchingClosingBracket(string $str, int $openPos)
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
     * Split a string by commas, but only at the top level
     *
     * @param string $str
     * @return array
     */
    public function splitTopLevelCommas(string $str): array
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

// Test cases
$testCases = [
    'name,created_at,type(id,code)' => 'name,created_at,type.id,type.code',
    'name,type(id,code),created_at' => 'name,type.id,type.code,created_at',
    'type(id,code)' => 'type.id,type.code',
    'name,created_at' => 'name,created_at',
    'user(id,name),post(title,content),comment(id,text)' => 'user.id,user.name,post.title,post.content,comment.id,comment.text',
    'user(id,name,email),nested(first(id,name),second(id,value))' => 'user.id,user.name,user.email,nested.first.id,nested.first.name,nested.second.id,nested.second.value',
    'simple,relation(field1,field2)' => 'simple,relation.field1,relation.field2',
];

// Run tests
$transformer = new TestTransformer();
$allPassed = true;

echo "Testing bracket to dot notation transformation:\n";
echo "=============================================\n";

foreach ($testCases as $input => $expected) {
    $result = $transformer->transformBracketToDotNotation($input);
    $passed = $result === $expected;
    $allPassed = $allPassed && $passed;

    echo "Input:    $input\n";
    echo "Expected: $expected\n";
    echo "Result:   $result\n";
    echo "Status:   " . ($passed ? "PASSED" : "FAILED") . "\n";
    echo "---------------------------------------------\n";
}

echo "\nOverall test result: " . ($allPassed ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";
