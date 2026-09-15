<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\ApiException;

final class CategoryValidator
{
    /**
     * Used for both POST (create) and PUT (full replace) — both require a
     * complete representation of the resource.
     */
    public static function validateFull(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        } elseif (strlen($name) > 100) {
            $errors['name'] = 'Name must be 100 characters or fewer.';
        }

        $description = $data['description'] ?? null;
        if ($description !== null && !is_string($description)) {
            $errors['description'] = 'Description must be a string.';
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid category data.', 422, $errors);
        }

        return ['name' => $name, 'description' => $description !== null ? trim($description) : null];
    }

    /**
     * PATCH: every field is optional, but at least one must be present and
     * whatever is present must still be valid — partial does not mean unchecked.
     */
    public static function validatePartial(array $data): array
    {
        $errors = [];
        $fields = [];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                $errors['name'] = 'Name cannot be empty.';
            } elseif (strlen($name) > 100) {
                $errors['name'] = 'Name must be 100 characters or fewer.';
            } else {
                $fields['name'] = $name;
            }
        }

        if (array_key_exists('description', $data)) {
            $description = $data['description'];
            if ($description !== null && !is_string($description)) {
                $errors['description'] = 'Description must be a string.';
            } else {
                $fields['description'] = $description !== null ? trim($description) : null;
            }
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid category data.', 422, $errors);
        }

        if ($fields === []) {
            throw new ApiException('VALIDATION_ERROR', 'At least one field must be provided.', 422);
        }

        return $fields;
    }
}
