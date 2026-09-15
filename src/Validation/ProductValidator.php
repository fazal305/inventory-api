<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\ApiException;

final class ProductValidator
{
    /**
     * POST (create) and PUT (full replace): every field required.
     */
    public static function validateFull(array $data): array
    {
        return self::validate($data, required: true);
    }

    /**
     * PATCH: every field optional, but whatever is present is checked with
     * the same rules, and at least one field must be present.
     */
    public static function validatePartial(array $data): array
    {
        $fields = self::validate($data, required: false);
        if ($fields === []) {
            throw new ApiException('VALIDATION_ERROR', 'At least one field must be provided.', 422);
        }
        return $fields;
    }

    private static function validate(array $data, bool $required): array
    {
        $errors = [];
        $fields = [];

        self::stringField($data, 'name', 150, $required, $errors, $fields);
        self::stringField($data, 'sku', 64, $required, $errors, $fields);

        if (array_key_exists('description', $data)) {
            $description = $data['description'];
            if ($description !== null && !is_string($description)) {
                $errors['description'] = 'Description must be a string.';
            } else {
                $fields['description'] = $description !== null ? trim((string) $description) : null;
            }
        } elseif ($required) {
            $fields['description'] = null;
        }

        if ($required || array_key_exists('category_id', $data)) {
            if (!isset($data['category_id']) || !self::isPositiveInt($data['category_id'])) {
                $errors['category_id'] = 'category_id is required and must be a positive integer.';
            } else {
                $fields['category_id'] = (int) $data['category_id'];
            }
        }

        if ($required || array_key_exists('price', $data)) {
            if (!isset($data['price']) || !is_numeric($data['price']) || (float) $data['price'] < 0) {
                $errors['price'] = 'price is required and must be a number greater than or equal to 0.';
            } else {
                $fields['price'] = round((float) $data['price'], 2);
            }
        }

        if ($required || array_key_exists('quantity', $data)) {
            if (!isset($data['quantity']) || !self::isNonNegativeInt($data['quantity'])) {
                $errors['quantity'] = 'quantity is required and must be an integer greater than or equal to 0.';
            } else {
                $fields['quantity'] = (int) $data['quantity'];
            }
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid product data.', 422, $errors);
        }

        return $fields;
    }

    private static function stringField(array $data, string $key, int $maxLength, bool $required, array &$errors, array &$fields): void
    {
        if (!array_key_exists($key, $data)) {
            if ($required) {
                $errors[$key] = ucfirst($key) . ' is required.';
            }
            return;
        }

        $value = trim((string) $data[$key]);
        if ($value === '') {
            $errors[$key] = ucfirst($key) . ' cannot be empty.';
        } elseif (strlen($value) > $maxLength) {
            $errors[$key] = ucfirst($key) . " must be {$maxLength} characters or fewer.";
        } else {
            $fields[$key] = $value;
        }
    }

    private static function isPositiveInt(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0;
    }

    private static function isNonNegativeInt(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0;
    }
}
