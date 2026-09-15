<?php

declare(strict_types=1);

namespace App\Validation;

use App\Support\ApiException;

/**
 * Server-side validation for auth endpoints. The client (Postman, curl, a
 * future React app) is never trusted to have validated anything before it
 * arrives here (rule 17/48) — every field is checked again regardless of
 * what a browser form might already have enforced.
 */
final class AuthValidator
{
    public static function validateRegister(array $data): array
    {
        $errors = [];

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email must be a valid email address.';
        }

        $password = (string) ($data['password'] ?? '');
        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid registration data.', 422, $errors);
        }

        return ['name' => $name, 'email' => $email, 'password' => $password];
    }

    public static function validateLogin(array $data): array
    {
        $errors = [];

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'Email is required.';
        }

        $password = (string) ($data['password'] ?? '');
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        }

        if ($errors !== []) {
            throw new ApiException('VALIDATION_ERROR', 'Invalid login data.', 422, $errors);
        }

        return ['email' => $email, 'password' => $password];
    }
}
