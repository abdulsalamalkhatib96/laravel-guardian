<?php

declare(strict_types=1);

namespace Guardian\Analysis;

use Guardian\Analysis\Index\ClassInfo;
use Guardian\Analysis\Index\MethodInfo;
use Guardian\Analysis\Index\SourceFile;
use Guardian\Analysis\Facts\GuardianFact;

final class ProgramIndex
{
    /** @var array<string, ClassInfo> */
    private array $classes = [];

    /** @var array<string, SourceFile> */
    private array $files = [];

    /** @var list<GuardianFact> */
    private array $facts = [];


    public function addFact(GuardianFact $fact): void
    {
        $this->facts[] = $fact;
    }

    /** @template T of GuardianFact @param class-string<T>|null $type @return list<T>|list<GuardianFact> */
    public function facts(?string $type = null): array
    {
        if ($type === null) {
            return $this->facts;
        }
        return array_values(array_filter($this->facts, static fn (GuardianFact $fact): bool => $fact instanceof $type));
    }

    public function addClass(ClassInfo $class): void
    {
        $this->classes[strtolower($class->name)] = $class;
    }

    public function addFile(SourceFile $file): void
    {
        $this->files[$file->path] = $file;
    }

    /** @return list<ClassInfo> */
    public function classes(): array
    {
        return array_values($this->classes);
    }

    /** @return list<MethodInfo> */
    public function methods(): array
    {
        $methods = [];
        foreach ($this->classes as $class) {
            array_push($methods, ...array_values($class->methods));
        }

        return $methods;
    }

    /** @return list<SourceFile> */
    public function files(): array
    {
        return array_values($this->files);
    }

    public function class(string $name): ?ClassInfo
    {
        return $this->classes[strtolower(ltrim($name, '\\'))] ?? null;
    }

    public function method(string $class, string $method): ?MethodInfo
    {
        $classInfo = $this->class($class);
        return $classInfo?->methods[strtolower($method)] ?? null;
    }

    public function findClassByBasename(string $basename): ?ClassInfo
    {
        foreach ($this->classes as $class) {
            if (strcasecmp($class->basename(), $basename) === 0) {
                return $class;
            }
        }

        return null;
    }

    public function file(string $path): ?SourceFile
    {
        return $this->files[$path] ?? null;
    }

    public function isModelClass(string $class): bool
    {
        $seen = [];
        $current = $this->class($class);
        while ($current !== null && ! isset($seen[strtolower($current->name)])) {
            $seen[strtolower($current->name)] = true;
            if ($current->isModel()) {
                return true;
            }
            $current = $current->extends ? $this->class($current->extends) : null;
        }

        return false;
    }
}
