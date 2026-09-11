<?php

declare(strict_types=1);

namespace Guardian\Analysis\Support;

use PhpParser\Node;

final class AstValue
{
    public static function scalar(Node\Expr $expr): mixed
    {
        return match (true) {
            $expr instanceof Node\Scalar\String_ => $expr->value,
            $expr instanceof Node\Scalar\Int_ => $expr->value,
            $expr instanceof Node\Scalar\Float_ => $expr->value,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'true' => true,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'false' => false,
            $expr instanceof Node\Expr\ConstFetch && strtolower($expr->name->toString()) === 'null' => null,
            $expr instanceof Node\Expr\Array_ => self::array($expr),
            default => null,
        };
    }

    /** @return array<mixed> */
    public static function array(Node\Expr\Array_ $array): array
    {
        $result = [];
        foreach ($array->items as $item) {
            if ($item === null) {
                continue;
            }
            $value = self::scalar($item->value);
            if ($item->key === null) {
                $result[] = $value;
                continue;
            }
            $key = self::scalar($item->key);
            if (is_int($key) || is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
