<?php declare(strict_types=1);

namespace Shopware\Rest\Context;

use Shopware\Api\Entity\Search\Criteria;
use Shopware\Api\Entity\Search\EntitySearcherInterface;
use Shopware\Api\Entity\Search\Query\TermQuery;
use Shopware\Api\User\Definition\UserDefinition;
use Shopware\Context\Struct\ShopContext;
use Shopware\Context\Struct\StorefrontContext;
use Shopware\StorefrontApi\Context\ContextSubscriber;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Controller\ArgumentValueResolverInterface;
use Symfony\Component\HttpKernel\ControllerMetadata\ArgumentMetadata;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

class RestContextValueResolver implements ArgumentValueResolverInterface
{
    /**
     * @var TokenStorageInterface
     */
    private $tokenStorage;

    /**
     * @var EntitySearcherInterface
     */
    private $searcher;

    /**
     * @var array
     */
    private $mapping = [];

    public function __construct(TokenStorageInterface $tokenStorage, EntitySearcherInterface $searcher)
    {
        $this->tokenStorage = $tokenStorage;
        $this->searcher = $searcher;
    }

    public function supports(Request $request, ArgumentMetadata $argument): bool
    {
        return $argument->getType() === RestContext::class;
    }

    public function resolve(Request $request, ArgumentMetadata $argument): iterable
    {
        /** @var StorefrontContext $storefrontContext */
        $storefrontContext = $request->attributes->get(ContextSubscriber::SHOP_CONTEXT_PROPERTY);

        yield new RestContext(
            $request,
            $storefrontContext->getShopContext(),
            $this->getUserId($storefrontContext->getShopContext())
        );
    }

    private function getUserId(ShopContext $context): ?string
    {
        $token = $this->tokenStorage->getToken();
        if (!$token) {
            return null;
        }

        /** @var UserInterface $user */
        $user = $token->getUser();

        $name = $user->getUsername();
        if (array_key_exists($name, $this->mapping)) {
            return $this->mapping[$name];
        }

        $criteria = new Criteria();
        $criteria->setLimit(1);
        $criteria->addFilter(new TermQuery(UserDefinition::getEntityName() . '.username', $name));

        $users = $this->searcher->search(UserDefinition::class, $criteria, $context);
        $ids = $users->getIds();

        $id = array_shift($ids);

        if (!$id) {
            return $this->mapping[$name] = null;
        }

        return $this->mapping[$name] = $id;
    }
}