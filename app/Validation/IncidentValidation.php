<?php

namespace App\Validation;

use App\Enums\IncidentPriority;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class IncidentValidation
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validateCreation(array $input): array
    {
        $input = self::trimStrings($input, ['title', 'description', 'requester_name', 'assigned_to']);

        foreach (['description', 'assigned_to'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $input[$key] = null;
            }
        }

        return Validator::make($input, self::creationRules(), self::creationMessages())->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function validateComment(array $input): array
    {
        $input = self::trimStrings($input, ['author_name', 'body']);

        return Validator::make($input, self::commentRules(), self::commentMessages())->validate();
    }

    /**
     * @return array<string, mixed>
     */
    public static function creationRules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'priority' => ['required', Rule::enum(IncidentPriority::class)],
            'requester_name' => ['required', 'string', 'max:255'],
            'assigned_to' => ['nullable', 'string', 'max:255'],
            'status' => ['prohibited'],
            'closed_at' => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function creationMessages(): array
    {
        return [
            'title.required' => 'El título es obligatorio.',
            'title.string' => 'El título debe ser texto.',
            'title.max' => 'El título no puede superar los 255 caracteres.',
            'description.string' => 'La descripción debe ser texto.',
            'priority.required' => 'La prioridad es obligatoria.',
            'priority.enum' => 'La prioridad seleccionada no es válida.',
            'requester_name.required' => 'Indica quién solicita la incidencia.',
            'requester_name.string' => 'El nombre del solicitante debe ser texto.',
            'requester_name.max' => 'El nombre del solicitante no puede superar los 255 caracteres.',
            'assigned_to.string' => 'El nombre de la persona asignada debe ser texto.',
            'assigned_to.max' => 'El nombre de la persona asignada no puede superar los 255 caracteres.',
            'status.prohibited' => 'El estado no se puede indicar al crear la incidencia.',
            'closed_at.prohibited' => 'La fecha de cierre no se puede indicar al crear la incidencia.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function commentRules(): array
    {
        return [
            'author_name' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function commentMessages(): array
    {
        return [
            'author_name.required' => 'Indica el autor del comentario.',
            'author_name.string' => 'El nombre del autor debe ser texto.',
            'author_name.max' => 'El nombre del autor no puede superar los 255 caracteres.',
            'body.required' => 'El comentario no puede estar vacío.',
            'body.string' => 'El comentario debe ser texto.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private static function trimStrings(array $input, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($input[$key]) && is_string($input[$key])) {
                $input[$key] = trim($input[$key]);
            }
        }

        return $input;
    }
}
