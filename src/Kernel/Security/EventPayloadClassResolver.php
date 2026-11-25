<?php

declare(strict_types=1);
/**
 * Copyright (c) The Magic , Distributed under the software license
 */

namespace Dtyq\AsyncEvent\Kernel\Security;

use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;

/**
 * 根据事件类的属性类型声明，递归收集反序列化时允许出现的类。
 * 接口和抽象类单独记录，由调用方按“同一顶层命名空间下的实现”规则放行。结果按类缓存在静态属性中。
 */
class EventPayloadClassResolver
{
    /**
     * @var array<string, array{classes: array<string, string>, abstracts: array<string, string>}> 键为小写类名
     */
    protected static array $cache = [];

    /**
     * @var array<string, array<string, string>> 文件路径 => 小写别名 => 完整类名
     */
    protected static array $importCache = [];

    /**
     * 注释类型中不代表类的关键字（PHPDoc 与 PHPStan 常用写法）。
     */
    private const DOC_BUILTIN_TYPES = [
        'array', 'list', 'iterable', 'int', 'integer', 'string', 'bool', 'boolean', 'float', 'double', 'mixed', 'null',
        'object', 'callable', 'false', 'true', 'void', 'never', 'resource', 'scalar', 'numeric', 'self', 'static', 'parent',
        'array-key', 'non-empty-array', 'non-empty-list', 'non-empty-string', 'positive-int', 'negative-int', 'class-string',
        'key-of', 'value-of', 'callable-string', 'literal-string', 'numeric-string',
    ];

    /**
     * 返回某个类的类型树：可实例化的类（含自身）与遇到的接口、抽象类，键均为小写类名，值为原始类名。
     *
     * @return array{classes: array<string, string>, abstracts: array<string, string>}
     */
    public static function resolve(string $class): array
    {
        $key = strtolower(ltrim($class, '\\'));
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }
        $classes = [];
        $abstracts = [];
        self::walk(ltrim($class, '\\'), $classes, $abstracts);
        self::$cache[$key] = ['classes' => $classes, 'abstracts' => $abstracts];
        return self::$cache[$key];
    }

    /**
     * 清空缓存，仅供测试使用。
     */
    public static function clear(): void
    {
        self::$cache = [];
        self::$importCache = [];
    }

    /**
     * 深度优先遍历类及其父类的非静态属性类型，已访问的类直接跳过以防循环引用。
     *
     * @param array<string, string> $classes
     * @param array<string, string> $abstracts
     */
    protected static function walk(string $class, array &$classes, array &$abstracts): void
    {
        $key = strtolower($class);
        if (isset($classes[$key]) || isset($abstracts[$key])) {
            return;
        }
        if (! class_exists($class) && ! interface_exists($class) && ! (function_exists('enum_exists') && enum_exists($class))) {
            return;
        }
        try {
            $reflection = new ReflectionClass($class);
        } catch (ReflectionException) {
            return;
        }
        if ($reflection->isInterface() || $reflection->isAbstract()) {
            $abstracts[$key] = $reflection->getName();
            return;
        }
        $classes[$key] = $reflection->getName();
        // 枚举不能定义魔术方法，也没有需要展开的实例属性
        if (method_exists($reflection, 'isEnum') && $reflection->isEnum()) {
            return;
        }
        for ($current = $reflection; $current; $current = $current->getParentClass()) {
            foreach ($current->getProperties() as $property) {
                if ($property->isStatic() || $property->getDeclaringClass()->getName() !== $current->getName()) {
                    continue;
                }
                $typeNames = self::typeNames($property->getType(), $current);
                if (self::isLooseType($property->getType())) {
                    // 数组等宽松类型无法从类型声明得知元素类型，补充读取 docblock 中声明的元素类
                    $typeNames = array_merge($typeNames, self::docblockClassNames($property, $current));
                }
                foreach ($typeNames as $typeName) {
                    self::walk($typeName, $classes, $abstracts);
                }
            }
        }
    }

    /**
     * 属性类型是否无法确定其中对象的类：没有类型声明，或为 array、iterable、mixed、object。
     */
    protected static function isLooseType(?ReflectionType $type): bool
    {
        if ($type === null) {
            return true;
        }
        $types = $type instanceof ReflectionNamedType ? [$type] : (method_exists($type, 'getTypes') ? $type->getTypes() : []);
        foreach ($types as $subType) {
            if ($subType instanceof ReflectionNamedType && in_array(strtolower($subType->getName()), ['array', 'iterable', 'mixed', 'object'], true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * 读取属性 @var 注释（构造器提升属性读取构造器的 @param 注释）中出现的类名，并按文件的 use 语句解析为完整类名。
     *
     * @return string[]
     */
    protected static function docblockClassNames(ReflectionProperty $property, ReflectionClass $declaringClass): array
    {
        $typeString = '';
        $doc = (string) $property->getDocComment();
        if (preg_match('/@var\s+([^\s*]+)/', $doc, $matches)) {
            $typeString = $matches[1];
        } elseif (method_exists($property, 'isPromoted') && $property->isPromoted()) {
            $constructor = $declaringClass->getConstructor();
            $pattern = '/@param\s+([^\s*]+)\s+\$' . preg_quote($property->getName(), '/') . '\b/';
            if ($constructor && preg_match($pattern, (string) $constructor->getDocComment(), $matches)) {
                $typeString = $matches[1];
            }
        }
        if ($typeString === '' || ! preg_match_all('/\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*/', $typeString, $matches)) {
            return [];
        }
        $names = [];
        foreach ($matches[0] as $name) {
            if (in_array(strtolower($name), self::DOC_BUILTIN_TYPES, true)) {
                continue;
            }
            $names[] = self::resolveDocName($name, $declaringClass);
        }
        return $names;
    }

    /**
     * 把注释中的类名解析为完整类名：以反斜杠开头视为完整类名，否则先查 use 别名，再按声明类所在命名空间补全。
     */
    protected static function resolveDocName(string $name, ReflectionClass $declaringClass): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }
        $segments = explode('\\', $name);
        $imports = self::imports($declaringClass);
        $first = strtolower($segments[0]);
        if (isset($imports[$first])) {
            $segments[0] = $imports[$first];
            return implode('\\', $segments);
        }
        $namespace = $declaringClass->getNamespaceName();
        return $namespace === '' ? $name : $namespace . '\\' . $name;
    }

    /**
     * 解析声明类所在文件顶部的 use 语句（类导入，含别名和分组），返回小写别名到完整类名的映射，按文件缓存。
     *
     * @return array<string, string>
     */
    protected static function imports(ReflectionClass $class): array
    {
        $file = $class->getFileName();
        if ($file === false || ! is_file($file)) {
            return [];
        }
        if (isset(self::$importCache[$file])) {
            return self::$importCache[$file];
        }
        $imports = [];
        $tokens = \PhpToken::tokenize((string) file_get_contents($file));
        $count = count($tokens);
        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];
            if ($token->is([T_CLASS, T_INTERFACE, T_TRAIT]) || (defined('T_ENUM') && $token->is(T_ENUM))) {
                break;
            }
            if (! $token->is(T_USE)) {
                continue;
            }
            // 读取到分号为止的 use 语句文本，跳过 use function / use const
            $statement = '';
            for (++$i; $i < $count && $tokens[$i]->text !== ';'; ++$i) {
                $statement .= $tokens[$i]->text;
            }
            $statement = trim($statement);
            if (preg_match('/^(function|const)\s/i', $statement)) {
                continue;
            }
            if (preg_match('/^(.*)\\\\\{(.*)\}$/s', $statement, $group)) {
                $prefix = trim($group[1]);
                $parts = explode(',', $group[2]);
            } else {
                $prefix = '';
                $parts = explode(',', $statement);
            }
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                $alias = null;
                if (preg_match('/^(.+?)\s+as\s+(\w+)$/i', $part, $aliasMatch)) {
                    [$part, $alias] = [trim($aliasMatch[1]), $aliasMatch[2]];
                }
                $full = ltrim(($prefix !== '' ? $prefix . '\\' : '') . $part, '\\');
                $alias ??= substr((string) strrchr('\\' . $full, '\\'), 1);
                $imports[strtolower($alias)] = $full;
            }
        }
        self::$importCache[$file] = $imports;
        return $imports;
    }

    /**
     * 取出属性类型中的类名：内置类型跳过，self/static/parent 解析为对应类，联合与交叉类型逐个展开。
     *
     * @return string[]
     */
    protected static function typeNames(?ReflectionType $type, ReflectionClass $declaringClass): array
    {
        if ($type === null) {
            return [];
        }
        if ($type instanceof ReflectionNamedType) {
            if ($type->isBuiltin()) {
                return [];
            }
            $name = $type->getName();
            $lower = strtolower($name);
            if ($lower === 'self' || $lower === 'static') {
                return [$declaringClass->getName()];
            }
            if ($lower === 'parent') {
                $parent = $declaringClass->getParentClass();
                return $parent ? [$parent->getName()] : [];
            }
            return [$name];
        }
        $names = [];
        if (method_exists($type, 'getTypes')) {
            foreach ($type->getTypes() as $subType) {
                $names = array_merge($names, self::typeNames($subType, $declaringClass));
            }
        }
        return $names;
    }
}
