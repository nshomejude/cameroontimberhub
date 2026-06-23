<?php
// In-process render check: confirms the dark-mode wiring + component dark: variants
// are present in the actual rendered HTML. Run: php artisan tinker design-system/_build/check-dark.php
$kernel = app(\Illuminate\Contracts\Http\Kernel::class);
function chk(string $h, string $name, string $needle): void {
    echo str_pad($name, 24) . ': ' . (str_contains($h, $needle) ? 'YES' : 'NO') . "\n";
}
foreach (['Home' => '/', 'Pricing' => '/pricing'] as $label => $uri) {
    $resp = $kernel->handle(\Illuminate\Http\Request::create($uri, 'GET'));
    $h = $resp->getContent();
    echo "=== {$label} ({$uri})  status " . $resp->getStatusCode() . "  " . strlen($h) . " bytes ===\n";
    chk($h, 'theme toggle button', 'Toggle theme');
    chk($h, 'pre-paint boot script', "classList.add('dark')");
    chk($h, 'body dark bg', 'dark:bg-[#14130f]');
    chk($h, 'surface dark variant', 'dark:bg-[#1f1d18]');
    chk($h, 'dark text variant', 'dark:text-');
    chk($h, 'tab bar', 'tab-bar');
    echo "\n";
}
echo "done\n";
