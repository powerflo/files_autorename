<?php

namespace OCA\Files_AutoRename\Service;

class RenameRuleParseException extends \Exception {}

class RenameRuleParser
{
    private const COMMENT_DELIMITER = '#';
    private const PATTERN_DELIMITER = '/';

    /**
     * Parse the contents of a .rename.conf file and return an array of rules.
     *
     * @param string $contents
     * @return array
     * @throws RenameRuleParseException
     */
    public function parse(string $contents): array
    {
        $rules = [];
        $lines = explode("\n", $contents);
    
        $insideGroup = false;
        $groupPatterns = [];
        $groupReplacements = [];
    
        foreach ($lines as $lineNumber => $lineContent) {
            $line = trim($lineContent);
    
            // Skip empty lines and comments
            if ($line === '' || strpos($line, self::COMMENT_DELIMITER) === 0) {
                continue;
            }
    
            if ($line === '{') {
                // Start of a grouped rule
                if ($insideGroup) {
                    throw new RenameRuleParseException('Nested "{" found at line ' . ($lineNumber + 1));
                }

                $insideGroup = true;
                $groupPatterns = [];
                $groupReplacements = [];
                continue;
            }
    
            if (str_starts_with($line, '}')) {
                if (!$insideGroup) {
                    throw new RenameRuleParseException('Closing "}" found without matching "{" at line ' . ($lineNumber + 1));
                    continue;
                }

                // Collect annotations by checking each enum case
                $annotations = [];
                foreach (RuleAnnotation::cases() as $case) {
                    $pattern = '/@' . preg_quote($case->value, '/') . '(?:\s|$)/';
                    if (preg_match($pattern, $line) === 1) {
                        $annotations[] = $case;
                    }
                }

                // End of a grouped rule
                if (!empty($groupPatterns) && !empty($groupReplacements)) {
                    $rules[] = [
                        'patterns'     => $groupPatterns,
                        'replacements' => $groupReplacements,
                        'annotations' => $annotations
                    ];
                }
                $insideGroup = false;
                continue;
            }

            // Split on the last unescaped colon
            // Regex breakdown:
            // (?<!\\):  -> Match a colon NOT preceded by a backslash
            // (?!.*(?<!\\):) -> Negative lookahead: ensure no other unescaped colons follow
            $parts = preg_split('/(?<!\\\\):(?!.*(?<!\\\\):)/', $line, 2);

            if (count($parts) !== 2) {
                throw new RenameRuleParseException('Invalid rule format at line ' . ($lineNumber + 1) . ': "' . $line . '"');
            }

            $pattern = trim($parts[0]);
            $replacement = trim($parts[1]);
    
            // Escape the pattern and wrap it with delimiters
            $escapedPattern = self::PATTERN_DELIMITER . str_replace(self::PATTERN_DELIMITER, '\\' . self::PATTERN_DELIMITER, $pattern) . self::PATTERN_DELIMITER;

            // Unescape any escaped colons in the replacement
            $replacement = str_replace('\:', ':', $replacement);

            if ($insideGroup) {
                $groupPatterns[] = $escapedPattern;
                $groupReplacements[] = $replacement;
            } else {
                $rules[] = [
                    'patterns'     => [$escapedPattern],
                    'replacements' => [$replacement],
                    'annotations' => []
                ];
            }
        }

        if ($insideGroup) {
            throw new RenameRuleParseException('File ended while inside a group (missing closing "}")');
        }
        
        return $rules;
    }
}