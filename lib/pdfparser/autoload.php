<?php
// Autoloader do smalot/pdfparser (bundle sem composer)
spl_autoload_register(function ($class) {
    $prefix = 'Smalot\\PdfParser\\';
    if (strpos($class, $prefix) === 0) {
        $path = __DIR__ . '/src/' . str_replace('\\', '/', $class) . '.php';
        if (file_exists($path)) require $path;
    }
});
