<?php

namespace App\Services;

interface PdfRenderer
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $options
     */
    public function render(string $view, array $data, array $options = []): string;
}
