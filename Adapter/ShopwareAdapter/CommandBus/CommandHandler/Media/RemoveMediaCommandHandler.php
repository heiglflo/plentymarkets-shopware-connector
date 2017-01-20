<?php

namespace ShopwareAdapter\CommandBus\CommandHandler\Media;

use PlentyConnector\Connector\CommandBus\Command\CommandInterface;
use PlentyConnector\Connector\CommandBus\Command\Media\RemoveMediaCommand;
use PlentyConnector\Connector\CommandBus\Command\RemoveCommandInterface;
use PlentyConnector\Connector\CommandBus\CommandHandler\CommandHandlerInterface;
use PlentyConnector\Connector\IdentityService\IdentityServiceInterface;
use PlentyConnector\Connector\TransferObject\Media\Media;
use Psr\Log\LoggerInterface;
use Shopware\Components\Api\Exception\NotFoundException;
use Shopware\Components\Api\Resource\Media as MediaResource;
use ShopwareAdapter\ShopwareAdapter;

/**
 * Class RemoveMediaCommandHandler.
 */
class RemoveMediaCommandHandler implements CommandHandlerInterface
{
    /**
     * @var MediaResource
     */
    private $resource;

    /**
     * @var IdentityServiceInterface
     */
    private $identityService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * RemoveMediaCommandHandler constructor.
     *
     * @param MediaResource $resource
     * @param IdentityServiceInterface $identityService
     * @param LoggerInterface $logger
     */
    public function __construct(
        MediaResource $resource,
        IdentityServiceInterface $identityService,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->identityService = $identityService;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function supports(CommandInterface $command)
    {
        return $command instanceof RemoveMediaCommand &&
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

        try {
            $this->resource->delete($identity->getAdapterIdentifier());
        } catch (NotFoundException $exception) {
            $this->logger->notice('identity removed but the object was not found');
        }

        $this->identityService->remove($identity);
    }
}
