<?php

declare(strict_types=1);

use App\Kernel;
use Doctrine\Bundle\DoctrineBundle\Mapping\MappingDriver as BundleMappingDriver;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriver as MappingDriverInterface;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;

require_once __DIR__ . '/bootstrap.php';

$kernel = new Kernel(
    strval($_SERVER['APP_ENV'] ?? 'test'),
    boolval($_SERVER['APP_DEBUG'] ?? false),
);
$kernel->boot();

$registry = $kernel->getContainer()->get('doctrine');
$manager = $registry->getManager();

// The registry is typed to the persistence layer, which knows nothing of ORM configuration.
if (!$manager instanceof EntityManagerInterface) {
    throw new RuntimeException('The default manager is not an ORM entity manager.');
}

// doctrine-bundle wraps each manager's chain in a decorator that resolves custom id generators, so the chain itself is
// one layer in. Without unwrapping, the folding below silently does nothing and every entity on the web manager
// analyses as a mixed type.
$chainOf = static function (?MappingDriverInterface $driver): ?MappingDriverChain {
    if ($driver instanceof BundleMappingDriver) {
        $driver = $driver->getDriver();
    }

    return $driver instanceof MappingDriverChain
        ? $driver
        : null;
};

// The analyser is handed one manager, while the entities are divided over two: the ledger on the default connection
// and everything else on the web one. Folding the other managers' drivers into this one's chain leaves every entity
// resolvable from the manager that is returned, so a repository or a query on either side is still checked against
// real mapping metadata.
$chain = $chainOf($manager->getConfiguration()->getMetadataDriverImpl());

if (null !== $chain) {
    foreach ($registry->getManagers() as $other) {
        if (
            $other === $manager
            || !$other instanceof EntityManagerInterface
        ) {
            continue;
        }

        $otherChain = $chainOf($other->getConfiguration()->getMetadataDriverImpl());

        if (null === $otherChain) {
            continue;
        }

        foreach ($otherChain->getDrivers() as $namespace => $driver) {
            $chain->addDriver(
                $driver,
                $namespace,
            );
        }
    }
}

return $manager;
