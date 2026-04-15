<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

class MysqlMigrationCompatibilityTest extends TestCase
{
    public function test_mysql_identifier_names_in_migrations_stay_within_supported_limits(): void
    {
        $violations = [];

        foreach (glob(database_path('migrations/*.php')) as $path) {
            $currentTable = null;
            $statement = null;
            $statementLine = null;
            $lines = file($path, FILE_IGNORE_NEW_LINES);

            foreach ($lines as $index => $line) {
                if (preg_match("/Schema::(?:create|table)\\('([^']+)'/", $line, $matches) === 1) {
                    $currentTable = $matches[1];
                }

                if ($statement !== null) {
                    $statement .= "\n".$line;

                    if (str_contains($line, ');')) {
                        $this->collectIdentifierViolations($violations, $path, $statementLine, $currentTable, $statement);
                        $statement = null;
                        $statementLine = null;
                    }

                    if (trim($line) === '});') {
                        $currentTable = null;
                    }

                    continue;
                }

                if (
                    $currentTable !== null
                    && (
                        str_contains($line, '$table->index(')
                        || str_contains($line, '$table->unique(')
                    )
                ) {
                    $statement = $line;
                    $statementLine = $index + 1;

                    if (str_contains($line, ');')) {
                        $this->collectIdentifierViolations($violations, $path, $statementLine, $currentTable, $statement);
                        $statement = null;
                        $statementLine = null;
                    }
                }

                if (trim($line) === '});') {
                    $currentTable = null;
                }
            }
        }

        $this->assertSame([], $violations, "MySQL identifier names exceed 64 characters:\n".implode("\n", $violations));
    }

    /**
     * @param  array<int, string>  $violations
     */
    private function collectIdentifierViolations(array &$violations, string $path, int $line, ?string $table, string $statement): void
    {
        if ($table === null) {
            return;
        }

        if (preg_match('/\\$table->(index|unique)\\((.*)\\);/s', $statement, $matches) !== 1) {
            return;
        }

        $type = $matches[1];
        $args = $this->splitTopLevelArgs(trim($matches[2]));

        if ($args === []) {
            return;
        }

        $name = isset($args[1])
            ? $this->trimQuotedValue($args[1])
            : $this->buildImplicitIdentifierName($table, $type, $args[0]);

        if ($name !== '' && strlen($name) > 64) {
            $violations[] = sprintf('%s:%d [%s] %s', $path, $line, $type, $name);
        }
    }

    private function buildImplicitIdentifierName(string $table, string $type, string $firstArgument): string
    {
        $columns = $this->extractColumns($firstArgument);

        if ($columns === []) {
            return '';
        }

        return $table.'_'.implode('_', $columns).'_'.$type;
    }

    /**
     * @return array<int, string>
     */
    private function extractColumns(string $argument): array
    {
        $argument = trim($argument);

        if ($argument === '') {
            return [];
        }

        if ($argument[0] !== '[') {
            $column = $this->trimQuotedValue($argument);

            return $column === '' ? [] : [$column];
        }

        preg_match_all('/[\'"]([^\'"]+)[\'"]/', $argument, $matches);

        return $matches[1] ?? [];
    }

    /**
     * @return array<int, string>
     */
    private function splitTopLevelArgs(string $args): array
    {
        $parts = [];
        $buffer = '';
        $depth = 0;
        $quote = null;
        $length = strlen($args);

        for ($i = 0; $i < $length; $i++) {
            $char = $args[$i];

            if ($quote !== null) {
                $buffer .= $char;

                if ($char === $quote && ($i === 0 || $args[$i - 1] !== '\\')) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '\'' || $char === '"') {
                $quote = $char;
                $buffer .= $char;
                continue;
            }

            if ($char === '[' || $char === '(') {
                $depth++;
                $buffer .= $char;
                continue;
            }

            if ($char === ']' || $char === ')') {
                $depth--;
                $buffer .= $char;
                continue;
            }

            if ($char === ',' && $depth === 0) {
                $parts[] = trim($buffer);
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        if (trim($buffer) !== '') {
            $parts[] = trim($buffer);
        }

        return $parts;
    }

    private function trimQuotedValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (
            (str_starts_with($value, '\'') && str_ends_with($value, '\''))
            || (str_starts_with($value, '"') && str_ends_with($value, '"'))
        ) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
