<?php
declare(strict_types=1);
namespace DynamicAllocations;
use Pterodactyl\Extensions\ExtensionProvider;
final class Provider extends ExtensionProvider {
    public function boot(): void { $this->registerApiRoutes(); }
}
