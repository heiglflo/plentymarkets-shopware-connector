<?php

namespace ShopwareAdapter\CommandBus\CommandHandler\MediaCategory;

use Doctrine\ORM\EntityManagerInterface;
use PlentyConnector\Connector\CommandBus\Command\CommandInterface;
use PlentyConnector\Connector\CommandBus\Command\MediaCategory\RemoveMediaCategoryCommand;
use PlentyConnector\Connector\CommandBus\Command\RemoveCommandInterface;
use PlentyConnector\Connector\CommandBus\CommandHandler\CommandHandlerInterface;
use PlentyConnector\Connector\IdentityService\IdentityServiceInterface;
use PlentyConnector\Connector\TransferObject\Media\Media;
use Psr\Log\LoggerInterface;
use Shopware\Models\Media\Album;
use ShopwareAdapter\ShopwareAdapter;

/**
 * Class RemoveMediaCategoryCommandHandler.
 */
class RemoveMediaCategoryCommandHandler implements CommandHandlerInterface
{
    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * HandleMediaCategoryCommandHandler constructor.
     *
     * @param EntityManagerInterface $entityManager
     * @param IdentityServiceInterface $identityService
     * @param LoggerInterface $logger
     */
    public function __construct(
        EntityManagerInterface $entityManager,
        IdentityServiceInterface $identityService,
        LoggerInterface $logger
    ) {
        $this->entityManager = $entityManager;
        $this->identityService = $identityService;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(CommandInterface $command)
    {
        return $command instanceof RemoveMediaCategoryCommand &&
            $command->getAdapterName() === ShopwareAdapter::NAME;
    }

    /**
     * @param CommandInterface $command
     *
     * @throws \Shopware\Components\Api\Exception\ValidationException
     * @throws \Shopware\Components\Api\Exception\NotFoundException
     * @throws \Shopware\Components\Api\Exception\ParameterMissingException
     */
    public function handle(CommandInterface $command)
    {
        /**
         * @var RemoveCommandInterface $command
         */
        $identifier = $command->getObjectIdentifier();

        $identity = $this->identityService->findOneBy([
            'objectIdentifier' => $identifier,
            'objectType' => Media::TYPE,
            'adapterName' => ShopwareAdapter::NAME,
        ]);

        if (null === $identity) {
            return;
        }

        $repository = $this->entityManager->getRepository(Album::class);

        $album = $repository->find($identity->getAdapterIdentifier());

        if (null !== $album) {
            $this->entityManager->remove($album);
            $this->entityManager->flush();
        } else {
            $this->logger->notice('identity removed but the object was not found');
        }

        $this->identityService->remove($identity);
    }
}
