<?php

return [
    // Install licensed Calibri on the server to reproduce the supplied Word PDFs.
    // Do not silently depend on a developer's Windows fonts in production.
    'font_directory' => env('QUOTATION_FONT_DIRECTORY', PHP_OS_FAMILY === 'Windows' ? 'C:/Windows/Fonts' : '/usr/share/fonts/truetype/msttcorefonts'),
];
