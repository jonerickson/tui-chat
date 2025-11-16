<?php

declare(strict_types=1);

if (! function_exists('center')) {
    function center(string $text, int $width): string
    {
        $padding = ($width - strlen($text)) / 2;

        return str_repeat(' ', (int) floor($padding)).$text.str_repeat(' ', (int) ceil($padding));
    }
}
