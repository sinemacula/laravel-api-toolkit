<?php

declare(strict_types = 1);

namespace SineMacula\ApiToolkit\OpenApi\Resolution;

/**
 * Resolves a class name written in a method's doc comment to its fully
 * qualified name.
 *
 * The name is read as PHP reads a class name in the file the method is written
 * in: a leading backslash marks it fully qualified, a namespace\ prefix stands
 * for the enclosing namespace, a first segment matching an import, compared
 * case-insensitively, is replaced by the imported name, and any other name is
 * relative to the enclosing namespace. The namespace and the imports declared
 * before the method come from the file declaring it, so a method inherited from
 * a parent class or a trait resolves against its own file. Each file is
 * tokenized once. Function and constant imports, trait uses, and closure uses
 * are not imports of a class. A method whose file cannot be read resolves
 * against its class's namespace with no imports.
 *
 * @author      Ben Carey <bdmc@sinemacula.co.uk>
 * @copyright   2026 Sine Macula Limited.
 */
final class ClassNameResolver
{
    /** @var array<int, int> The tokens a written name may be */
    private const array NAME_TOKENS = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];

    /** @var array<int, int|string> The tokens a use statement importing names may hold */
    private const array IMPORT_TOKENS = [...self::NAME_TOKENS, T_AS, T_NS_SEPARATOR, T_FUNCTION, T_CONST, ',', '{', '}', ';'];

    /** @var array<string, array<int, array{line: int, namespace: string, imports: array<string, string>}>> The scopes of each file read, in file order */
    private array $scopes = [];

    /**
     * Resolve the name as written in the doc comment of the given method.
     *
     * @param  string  $name
     * @param  \ReflectionMethod  $method
     * @return string
     */
    public function resolve(string $name, \ReflectionMethod $method): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $scope    = $this->scopeOf($method);
        $segments = explode('\\', $name, 2);
        $imports  = ['namespace' => $scope['namespace']] + $scope['imports'];
        $import   = $imports[strtolower($segments[0])] ?? null;

        if ($import === null) {
            return ltrim($scope['namespace'] . '\\' . $name, '\\');
        }

        return ltrim(isset($segments[1]) ? $import . '\\' . $segments[1] : $import, '\\');
    }

    /**
     * Find the scope in effect where the method is declared.
     *
     * @param  \ReflectionMethod  $method
     * @return array{namespace: string, imports: array<string, string>}
     */
    private function scopeOf(\ReflectionMethod $method): array
    {
        $file   = (string) $method->getFileName();
        $line   = (int) $method->getStartLine();
        $blocks = array_filter($this->scopes[$file] ??= $this->scopesIn($file), static fn (array $block): bool => $block['line'] <= $line);

        return $blocks === []
            ? ['namespace' => $method->getDeclaringClass()->getNamespaceName(), 'imports' => []]
            : end($blocks);
    }

    /**
     * Read the scopes of the file in order, each with the line it takes effect
     * on, its namespace, and its class imports keyed by lowercase alias.
     *
     * Each namespace statement opens a scope with no imports, and each use
     * statement outside any brace opens one adding its imports, so a name
     * resolves only against the imports declared before it. Braces opening and
     * closing a braced namespace are not counted.
     *
     * @param  string  $file
     * @return array<int, array{line: int, namespace: string, imports: array<string, string>}>
     */
    private function scopesIn(string $file): array
    {
        $contents = is_file($file) ? file_get_contents($file) : false;

        if ($contents === false) {
            return [];
        }

        $tokens = array_values(array_filter(\PhpToken::tokenize($contents, TOKEN_PARSE), static fn (\PhpToken $token): bool => !$token->isIgnorable()));
        $blocks = [];
        $block  = ['line' => 0, 'namespace' => '', 'imports' => []];
        $depth  = 0;

        foreach ($tokens as $index => $token) {

            if ($token->id === T_NAMESPACE) {
                $blocks[] = $block;
                $block    = ['line' => $token->line, 'namespace' => $this->namespaceAt($tokens, $index), 'imports' => []];
            } elseif ($token->id === T_USE && $depth === 0) {
                $blocks[] = $block;
                $block    = ['line' => $token->line, 'namespace' => $block['namespace'], 'imports' => $block['imports'] + $this->importsAt($tokens, $index + 1)];
            }

            $depth = $this->depthAfter($tokens, $index, $depth);
        }

        $blocks[] = $block;

        return $blocks;
    }

    /**
     * Read the name the namespace keyword at the given index declares, empty
     * for the global namespace.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @return string
     */
    private function namespaceAt(array $tokens, int $index): string
    {
        return $tokens[$index + 1]->is([T_STRING, T_NAME_QUALIFIED]) ? $tokens[$index + 1]->text : '';
    }

    /**
     * Track the brace depth past the token at the given index, leaving the
     * braces of a braced namespace and literal braces in strings uncounted.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @param  int  $depth
     * @return int
     */
    private function depthAfter(array $tokens, int $index, int $depth): int
    {
        $id = $tokens[$index]->id;

        if (in_array($id, [ord('{'), T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true) && !$this->opensNamespace($tokens, $index)) {
            return $depth + 1;
        }

        return $id === ord('}') && $depth > 0 ? $depth - 1 : $depth;
    }

    /**
     * Determine whether the brace at the given index opens a braced namespace.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @return bool
     */
    private function opensNamespace(array $tokens, int $index): bool
    {
        return ($tokens[$index - 1] ?? null)?->id === T_NAMESPACE
            || ($tokens[$index - 2] ?? null)?->id === T_NAMESPACE;
    }

    /**
     * Read the class imports of the use statement starting at the given index,
     * keyed by lowercase alias, or none when the statement imports functions or
     * constants or is not an import at all.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @return array<string, string>
     */
    private function importsAt(array $tokens, int $index): array
    {
        $statement = $this->statementAt($tokens, $index);

        if ($statement === null || $statement[0]->is([T_FUNCTION, T_CONST])) {
            return [];
        }

        $imports = [];
        $prefix  = '';
        $clause  = [];

        foreach ($statement as $token) {

            if ($token->is(T_NS_SEPARATOR)) {
                $prefix = $clause[0]->text . '\\';
                $clause = [];
            } elseif ($token->is([',', '}', ';'])) {
                $imports += $this->importOf($clause, $prefix);
                $clause = [];
            } elseif (!$token->is('{')) {
                $clause[] = $token;
            }
        }

        return $imports;
    }

    /**
     * Collect the tokens of the use statement starting at the given index,
     * through its closing semicolon, or null when a token shows it is not an
     * import of names.
     *
     * @param  array<int, \PhpToken>  $tokens
     * @param  int  $index
     * @return non-empty-list<\PhpToken>|null
     */
    private function statementAt(array $tokens, int $index): ?array
    {
        $statement = [];

        for (; isset($tokens[$index]) && $tokens[$index]->is(self::IMPORT_TOKENS); $index++) {

            $statement[] = $tokens[$index];

            if ($tokens[$index]->is(';')) {
                return $statement;
            }
        }

        return null;
    }

    /**
     * Read the import one clause of a use statement declares, keyed by its
     * lowercase alias, or none for a function or constant clause.
     *
     * @param  array<int, \PhpToken>  $clause
     * @param  string  $prefix
     * @return array<string, string>
     */
    private function importOf(array $clause, string $prefix): array
    {
        $names = array_values(array_filter($clause, static fn (\PhpToken $token): bool => $token->is(self::NAME_TOKENS)));

        if ($names === [] || $clause[0]->is([T_FUNCTION, T_CONST])) {
            return [];
        }

        $name = $prefix . $names[0]->text;

        return [strtolower(isset($names[1]) ? $names[1]->text : class_basename($name)) => $name];
    }
}
