<?php

namespace App\Support;

/**
 * Splits `---\n<yaml>\n---\n<body>` and parses the YAML subset the article
 * frontmatter contract uses.
 *
 * Symfony's YAML component is only a *suggestion* of the packages this app
 * installs — it is not in the vendor tree — and the brief forbids adding a
 * package for this. So the parser is hand-rolled and deliberately covers only
 * the documented contract (content/articles/README.md):
 *
 *   key: scalar                     quoted, unquoted, true/false/null, numbers
 *   key: >                          folded block scalar
 *   key: |                          literal block scalar
 *   key:                            list of scalars
 *     - one
 *   key:                            list of maps (FAQs, sources)
 *     - question: …
 *       answer: >
 *         …
 *   key:                            nested map (author)
 *     name: …
 *   key: [a, b]                     one-level flow sequence
 *   key: {name: x, role: y}         one-level flow mapping
 *
 * Anything outside that subset (anchors, aliases, tags, multi-document files,
 * nested flow collections) throws rather than silently producing a wrong value
 * — a writing agent gets told, instead of shipping an article with a field
 * quietly missing.
 */
class Frontmatter
{
    /**
     * @return array{0: array<string, mixed>, 1: string} [frontmatter, body]
     */
    public static function split(string $raw): array
    {
        $raw = preg_replace('/\A\xEF\xBB\xBF/', '', $raw) ?? $raw;
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);

        if (! preg_match('/\A---[ \t]*\n(.*?)\n---[ \t]*\n?(.*)\z/s', $raw, $m)) {
            throw new \RuntimeException('no YAML frontmatter block found (the file must start with `---`)');
        }

        return [self::parse($m[1]), trim($m[2])];
    }

    /** @return array<string, mixed> */
    public static function parse(string $yaml): array
    {
        $lines = explode("\n", $yaml);

        // Strip comments and blank lines, keeping indentation intact.
        $clean = [];
        foreach ($lines as $line) {
            if (trim($line) === '' || str_starts_with(ltrim($line), '#')) {
                continue;
            }
            if (str_contains($line, "\t")) {
                throw new \RuntimeException('tabs are not valid YAML indentation — use spaces');
            }
            $clean[] = rtrim($line);
        }

        $index = 0;
        $result = self::parseBlock($clean, $index, 0);

        if (! is_array($result)) {
            throw new \RuntimeException('frontmatter must be a YAML mapping');
        }

        return $result;
    }

    /**
     * Parses every line at exactly `$indent` columns, recursing into deeper
     * blocks. Returns a map or a list depending on what it finds.
     *
     * @param  list<string>  $lines
     */
    private static function parseBlock(array $lines, int &$i, int $indent): array
    {
        $map = [];
        $list = [];

        while ($i < count($lines)) {
            $line = $lines[$i];
            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent < $indent) {
                break;
            }

            if ($lineIndent > $indent) {
                throw new \RuntimeException('unexpected indentation at: '.trim($line));
            }

            $content = trim($line);

            // ---- list item -------------------------------------------------
            if (str_starts_with($content, '- ') || $content === '-') {
                $rest = trim(substr($content, 1));

                if ($rest === '') {
                    // `-` alone: the item is the block that follows.
                    $i++;
                    $list[] = self::parseBlock($lines, $i, self::nextIndent($lines, $i, $indent) ?? $indent + 2);

                    continue;
                }

                // `- key: value` starts an inline map whose remaining keys are
                // indented to the position of that first key.
                if (self::isKeyLine($rest)) {
                    $itemIndent = $lineIndent + 2;
                    $item = self::parseKeyLine($lines, $i, $rest, $itemIndent);

                    while ($i < count($lines)) {
                        $next = $lines[$i];
                        $nextIndent = strlen($next) - strlen(ltrim($next));

                        if ($nextIndent !== $itemIndent || str_starts_with(trim($next), '- ')) {
                            break;
                        }

                        $item += self::parseKeyLine($lines, $i, trim($next), $itemIndent);
                    }

                    $list[] = $item;

                    continue;
                }

                $i++;
                $list[] = self::scalar($rest);

                continue;
            }

            // ---- key: value ------------------------------------------------
            if (! self::isKeyLine($content)) {
                throw new \RuntimeException('expected `key: value` at: '.$content);
            }

            $map += self::parseKeyLine($lines, $i, $content, $indent);
        }

        if ($list !== [] && $map !== []) {
            throw new \RuntimeException('a YAML block cannot mix list items and keys');
        }

        return $list !== [] ? $list : $map;
    }

    /**
     * Consumes one `key: …` line (advancing `$i`) plus any block that belongs
     * to it, and returns it as a single-entry map.
     *
     * @param  list<string>  $lines
     * @return array<string, mixed>
     */
    private static function parseKeyLine(array $lines, int &$i, string $content, int $indent): array
    {
        [$key, $value] = self::splitKey($content);
        $i++;

        // Block scalars: `>` folded, `|` literal (with optional `-` chomp).
        if (preg_match('/^([>|])([+-]?)$/', $value, $m)) {
            return [$key => self::blockScalar($lines, $i, $indent, $m[1] === '|')];
        }

        if ($value !== '') {
            return [$key => self::scalar($value)];
        }

        // Empty value: a nested block, or genuinely null.
        $childIndent = self::nextIndent($lines, $i, $indent);

        if ($childIndent === null) {
            return [$key => null];
        }

        return [$key => self::parseBlock($lines, $i, $childIndent)];
    }

    /**
     * Gathers an indented run of lines into one string.
     *
     * @param  list<string>  $lines
     */
    private static function blockScalar(array $lines, int &$i, int $indent, bool $literal): string
    {
        $collected = [];

        while ($i < count($lines)) {
            $line = $lines[$i];
            $lineIndent = strlen($line) - strlen(ltrim($line));

            if ($lineIndent <= $indent) {
                break;
            }

            $collected[] = ltrim($line);
            $i++;
        }

        return $literal ? implode("\n", $collected) : trim(implode(' ', $collected));
    }

    /**
     * The indentation of the block starting at `$i`, or null when the next line
     * is not more deeply indented than `$indent`.
     *
     * @param  list<string>  $lines
     */
    private static function nextIndent(array $lines, int $i, int $indent): ?int
    {
        if ($i >= count($lines)) {
            return null;
        }

        $next = $lines[$i];
        $nextIndent = strlen($next) - strlen(ltrim($next));

        return $nextIndent > $indent ? $nextIndent : null;
    }

    private static function isKeyLine(string $content): bool
    {
        return (bool) preg_match('/^(?:"[^"]*"|\'[^\']*\'|[A-Za-z0-9_][A-Za-z0-9_\- ]*)\s*:(\s|$)/', $content);
    }

    /** @return array{0: string, 1: string} */
    private static function splitKey(string $content): array
    {
        if (! preg_match('/^("([^"]*)"|\'([^\']*)\'|[A-Za-z0-9_][A-Za-z0-9_\- ]*?)\s*:(?:\s+(.*))?$/', $content, $m)) {
            throw new \RuntimeException('could not read the key in: '.$content);
        }

        $key = $m[2] ?? '';
        if ($key === '') {
            $key = $m[3] ?? '';
        }
        if ($key === '') {
            $key = trim($m[1], '"\'');
        }

        return [$key, trim($m[4] ?? '')];
    }

    /**
     * Splits a flow collection's interior on commas that are not inside quotes.
     *
     * @return list<string>
     */
    private static function splitFlow(string $inner): array
    {
        $parts = [];
        $buffer = '';
        $quote = null;

        foreach (str_split($inner) as $char) {
            if ($quote !== null) {
                $buffer .= $char;
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                $buffer .= $char;

                continue;
            }

            if ($char === ',') {
                $parts[] = $buffer;
                $buffer = '';

                continue;
            }

            $buffer .= $char;
        }

        $parts[] = $buffer;

        return $parts;
    }

    private static function scalar(string $value): mixed
    {
        // Strip a trailing inline comment on unquoted scalars only.
        if (! preg_match('/^["\']/', $value) && preg_match('/\s#\s/', $value)) {
            $value = trim(preg_split('/\s#\s/', $value, 2)[0]);
        }

        // Flow collections, one level deep: `[a, b]` and `{name: x, role: y}`.
        if (str_starts_with($value, '[') && str_ends_with($value, ']')) {
            return array_values(array_filter(
                array_map(fn (string $v): mixed => self::scalar(trim($v)), self::splitFlow(substr($value, 1, -1))),
                fn ($v): bool => $v !== null
            ));
        }

        if (str_starts_with($value, '{') && str_ends_with($value, '}')) {
            $map = [];
            foreach (self::splitFlow(substr($value, 1, -1)) as $pair) {
                if (trim($pair) === '') {
                    continue;
                }
                [$k, $v] = array_pad(explode(':', $pair, 2), 2, '');
                $map[trim($k, " \t\"'")] = self::scalar(trim($v));
            }

            return $map;
        }

        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $inner = substr($value, 1, -1);

                return $first === '"'
                    ? str_replace(['\\"', '\\n', '\\\\'], ['"', "\n", '\\'], $inner)
                    : str_replace("''", "'", $inner);
            }
        }

        return match (strtolower($value)) {
            'true', 'yes' => true,
            'false', 'no' => false,
            'null', '~', '' => null,
            default => preg_match('/^-?\d+$/', $value) === 1
                ? (int) $value
                : (preg_match('/^-?\d*\.\d+$/', $value) === 1 ? (float) $value : $value),
        };
    }
}
