<?php

/*
 * This file is part of Sulu.
 *
 * (c) Sulu GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\ArticleBundle\Document\Subscriber;

use Doctrine\ORM\EntityManagerInterface;
use PHPCR\ItemNotFoundException;
use PHPCR\SessionInterface;
use Sulu\Bundle\ArticleBundle\Document\Behavior\RoutableBehavior;
use Sulu\Bundle\ArticleBundle\Document\Behavior\RoutablePageBehavior;
use Sulu\Bundle\DocumentManagerBundle\Bridge\DocumentInspector;
use Sulu\Bundle\DocumentManagerBundle\Bridge\PropertyEncoder;
use Sulu\Bundle\RouteBundle\Entity\RouteRepositoryInterface;
use Sulu\Bundle\RouteBundle\Exception\RouteIsNotUniqueException;
use Sulu\Bundle\RouteBundle\Generator\ChainRouteGeneratorInterface;
use Sulu\Bundle\RouteBundle\Manager\ConflictResolverInterface;
use Sulu\Bundle\RouteBundle\Manager\RouteManagerInterface;
use Sulu\Bundle\RouteBundle\Model\RouteInterface;
use Sulu\Component\Content\Document\LocalizationState;
use Sulu\Component\Content\Exception\ResourceLocatorAlreadyExistsException;
use Sulu\Component\Content\Metadata\Factory\StructureMetadataFactoryInterface;
use Sulu\Component\DocumentManager\Behavior\Mapping\ChildrenBehavior;
use Sulu\Component\DocumentManager\Behavior\Mapping\ParentBehavior;
use Sulu\Component\DocumentManager\DocumentManagerInterface;
use Sulu\Component\DocumentManager\Event\AbstractMappingEvent;
use Sulu\Component\DocumentManager\Event\CopyEvent;
use Sulu\Component\DocumentManager\Event\PublishEvent;
use Sulu\Component\DocumentManager\Event\RemoveEvent;
use Sulu\Component\DocumentManager\Event\ReorderEvent;
use Sulu\Component\DocumentManager\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Handles document-manager events to create/update/remove routes.
 */
class RoutableSubscriber implements EventSubscriberInterface
{
    const ROUTE_FIELD = 'routePath';

    const ROUTES_PROPERTY = 'suluRoutes';

    const TAG_NAME = 'sulu_article.article_route';

    /**
     * @var ChainRouteGeneratorInterface
     */
    private $chainRouteGenerator;

    /**
     * @var RouteManagerInterface
     */
    private $routeManager;

    /**
     * @var RouteRepositoryInterface
     */
    private $routeRepository;

    /**
     * @var EntityManagerInterface
     */
    private $entityManager;

    /**
     * @var DocumentManagerInterface
     */
    private $documentManager;

    /**
     * @var DocumentInspector
     */
    private $documentInspector;

    /**
     * @var PropertyEncoder
     */
    private $propertyEncoder;

    /**
     * @var StructureMetadataFactoryInterface
     */
    private $metadataFactory;

    /**
     * @var ConflictResolverInterface
     */
    private $conflictResolver;

    public function __construct(
        ChainRouteGeneratorInterface $chainRouteGenerator,
        RouteManagerInterface $routeManager,
        RouteRepositoryInterface $routeRepository,
        EntityManagerInterface $entityManager,
        DocumentManagerInterface $documentManager,
        DocumentInspector $documentInspector,
        PropertyEncoder $propertyEncoder,
        StructureMetadataFactoryInterface $metadataFactory,
        ConflictResolverInterface $conflictResolver
    ) {
        $this->chainRouteGenerator = $chainRouteGenerator;
        $this->routeManager = $routeManager;
        $this->routeRepository = $routeRepository;
        $this->entityManager = $entityManager;
        $this->documentManager = $documentManager;
        $this->documentInspector = $documentInspector;
        $this->propertyEncoder = $propertyEncoder;
        $this->metadataFactory = $metadataFactory;
        $this->conflictResolver = $conflictResolver;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            Events::HYDRATE => ['handleHydrate'],
            Events::PERSIST => [
                // low priority because all other subscriber should be finished
                ['handlePersist', -2000],
            ],
            Events::REMOVE => [
                // high priority to ensure nodes are not deleted until we iterate over children
                ['handleRemove', 1024],
            ],
            Events::PUBLISH => ['handlePublish', -2000],
            Events::REORDER => ['handleReorder', -1000],
            Events::COPY => ['handleCopy', -2000],
        ];
    }

    /**
     * Load route.
     */
    public function handleHydrate(AbstractMappingEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutablePageBehavior) {
            return;
        }

        $locale = $document->getLocale();
        if (LocalizationState::SHADOW === $this->documentInspector->getLocalizationState($document)) {
            $locale = $document->getOriginalLocale();
        }

        $propertyName = $this->getRoutePathPropertyName($document->getStructureType(), $locale);
        $routePath = $event->getNode()->getPropertyValueWithDefault($propertyName, null);
        $document->setRoutePath($routePath);

        $route = $this->routeRepository->findByEntity($document->getClass(), $document->getUuid(), $locale);
        if ($route) {
            $document->setRoute($route);
        }
    }

    /**
     * Generate route and save route-path.
     */
    public function handlePersist(AbstractMappingEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutablePageBehavior) {
            return;
        }

        $document->setUuid($event->getNode()->getIdentifier());

        $propertyName = $this->getRoutePathPropertyName($document->getStructureType(), $event->getLocale());
        $routePath = $event->getNode()->getPropertyValueWithDefault($propertyName, null);

        $route = $this->conflictResolver->resolve($this->chainRouteGenerator->generate($document, $routePath));
        $document->setRoutePath($route->getPath());

        $event->getNode()->setProperty($propertyName, $route->getPath());

        if (!$document instanceof ChildrenBehavior) {
            return;
        }

        foreach ($document->getChildren() as $child) {
            if (!$child instanceof RoutablePageBehavior) {
                continue;
            }

            $route = $this->chainRouteGenerator->generate($child);
            $child->setRoutePath($route->getPath());

            $node = $this->documentInspector->getNode($child);
            $node->setProperty($propertyName, $route->getPath());
        }
    }

    /**
     * Regenerate routes for siblings on reorder.
     */
    public function handleReorder(ReorderEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutablePageBehavior || !$document instanceof ParentBehavior) {
            return;
        }

        $parentDocument = $document->getParent();
        if (!$parentDocument instanceof ChildrenBehavior) {
            return;
        }

        $locale = $this->documentInspector->getLocale($parentDocument);
        $propertyName = $this->getRoutePathPropertyName($parentDocument->getStructureType(), $locale);
        foreach ($parentDocument->getChildren() as $childDocument) {
            $node = $this->documentInspector->getNode($childDocument);

            $route = $this->chainRouteGenerator->generate($childDocument);
            $childDocument->setRoutePath($route->getPath());

            $node->setProperty($propertyName, $route->getPath());
        }
    }

    /**
     * Handle publish event and generate route and the child-routes.
     *
     * @throws ResourceLocatorAlreadyExistsException
     */
    public function handlePublish(PublishEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $node = $this->documentInspector->getNode($document);

        try {
            $route = $this->createOrUpdateRoute($document, $event->getLocale());
        } catch (RouteIsNotUniqueException $exception) {
            throw new ResourceLocatorAlreadyExistsException($exception->getRoute()->getPath(), $document->getPath());
        }

        $document->setRoutePath($route->getPath());
        $this->entityManager->persist($route);

        $node->setProperty(
            $this->getRoutePathPropertyName($document->getStructureType(), $event->getLocale()),
            $route->getPath()
        );

        $propertyName = $this->getPropertyName($event->getLocale(), self::ROUTES_PROPERTY);

        // check if nodes previous generated routes exists and remove them if not
        $oldRoutes = $event->getNode()->getPropertyValueWithDefault($propertyName, []);
        $this->removeOldChildRoutes($event->getNode()->getSession(), $oldRoutes, $event->getLocale());

        $routes = [];
        if ($document instanceof ChildrenBehavior) {
            // generate new routes of children
            $routes = $this->generateChildRoutes($document, $event->getLocale());
        }

        // save the newly generated routes of children
        $event->getNode()->setProperty($propertyName, $routes);
        $this->entityManager->flush();
    }

    /**
     * Create or update for given document.
     *
     * @param string $locale
     *
     * @return RouteInterface
     */
    private function createOrUpdatePageRoute(RoutablePageBehavior $document, $locale)
    {
        $route = $this->reallocateExistingRoute($document, $locale);
        if ($route) {
            return $route;
        }

        $route = $document->getRoute();
        if (!$route) {
            $route = $this->routeRepository->findByEntity($document->getClass(), $document->getUuid(), $locale);
        }

        if ($route && $route->getEntityId() !== $document->getId()) {
            // Mismatch of entity-id's happens because doctrine don't check entities which has been changed in the
            // current session.

            $document->removeRoute();
            $route = null;
        }

        if ($route) {
            $document->setRoute($route);

            return $this->routeManager->update($document, null, false);
        }

        return $this->routeManager->create($document);
    }

    /**
     * Reallocates existing route to given document.
     *
     * @param string $locale
     *
     * @return RouteInterface
     */
    private function reallocateExistingRoute(RoutablePageBehavior $document, $locale)
    {
        $newRoute = $this->routeRepository->findByPath($document->getRoutePath(), $locale);
        if (!$newRoute) {
            return;
        }

        $oldRoute = $this->routeRepository->findByEntity(get_class($document), $document->getUuid(), $locale);
        $history = $this->routeRepository->findHistoryByEntity(get_class($document), $document->getUuid(), $locale);

        /** @var RouteInterface $historyRoute */
        foreach (array_filter(array_merge($history, [$oldRoute])) as $historyRoute) {
            if ($historyRoute->getId() === $newRoute->getId() || $document->getId() !== $historyRoute->getEntityId()) {
                // Mismatch of entity-id's happens because doctrine don't check entities which has been changed in the
                // current session. If the old-route was already reused by a page before it will be returned in the
                // query of line 329.

                continue;
            }

            $historyRoute->setTarget($newRoute);
            $historyRoute->setHistory(true);
            $newRoute->addHistory($historyRoute);
        }

        $newRoute->setEntityClass(get_class($document));
        $newRoute->setEntityId($document->getId());
        $newRoute->setTarget(null);
        $newRoute->setHistory(false);

        return $newRoute;
    }

    /**
     * Create or update for given document.
     *
     * @param string $locale
     *
     * @return RouteInterface
     */
    private function createOrUpdateRoute(RoutableBehavior $document, $locale)
    {
        $route = $document->getRoute();

        if (!$route) {
            $route = $this->routeRepository->findByEntity($document->getClass(), $document->getUuid(), $locale);
        }

        if ($route) {
            $document->setRoute($route);

            return $this->routeManager->update($document, $document->getRoutePath(), false);
        }

        return $this->routeManager->create($document, $document->getRoutePath(), false);
    }

    /**
     * Removes old-routes where the node does not exists anymore.
     *
     * @param string $locale
     */
    private function removeOldChildRoutes(SessionInterface $session, array $oldRoutes, $locale)
    {
        foreach ($oldRoutes as $oldRoute) {
            $oldRouteEntity = $this->routeRepository->findByPath($oldRoute, $locale);
            if ($oldRouteEntity && !$this->nodeExists($session, $oldRouteEntity->getEntityId())) {
                $this->entityManager->remove($oldRouteEntity);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Generates child routes.
     *
     * @param string $locale
     *
     * @return string[]
     */
    private function generateChildRoutes(ChildrenBehavior $document, $locale)
    {
        $routes = [];
        foreach ($document->getChildren() as $child) {
            if (!$child instanceof RoutablePageBehavior) {
                continue;
            }

            $childRoute = $this->createOrUpdatePageRoute($child, $locale);
            $this->entityManager->persist($childRoute);

            $child->setRoutePath($childRoute->getPath());
            $childNode = $this->documentInspector->getNode($child);

            $propertyName = $this->getRoutePathPropertyName($child->getStructureType(), $locale);
            $childNode->setProperty($propertyName, $childRoute->getPath());

            $routes[] = $childRoute->getPath();
        }

        return $routes;
    }

    /**
     * Removes route.
     */
    public function handleRemove(RemoveEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $locales = $this->documentInspector->getLocales($document);
        foreach ($locales as $locale) {
            $localizedDocument = $this->documentManager->find($document->getUuid(), $locale);

            $route = $this->routeRepository->findByEntity(
                $localizedDocument->getClass(),
                $localizedDocument->getUuid(),
                $locale
            );
            if (!$route) {
                continue;
            }

            $this->entityManager->remove($route);

            if ($document instanceof ChildrenBehavior) {
                $this->removeChildRoutes($document, $locale);
            }
        }

        $this->entityManager->flush();
    }

    /**
     * Update routes for copied article.
     */
    public function handleCopy(CopyEvent $event)
    {
        $document = $event->getDocument();
        if (!$document instanceof RoutableBehavior) {
            return;
        }

        $locales = $this->documentInspector->getLocales($document);
        foreach ($locales as $locale) {
            $localizedDocument = $this->documentManager->find($event->getCopiedPath(), $locale);

            $route = $this->conflictResolver->resolve($this->chainRouteGenerator->generate($localizedDocument));
            $localizedDocument->setRoutePath($route->getPath());

            $node = $this->documentInspector->getNode($localizedDocument);
            $node->setProperty(
                $this->getRoutePathPropertyName($localizedDocument->getStructureType(), $locale),
                $route->getPath()
            );

            $propertyName = $this->getRoutePathPropertyName($localizedDocument->getStructureType(), $locale);
            $node = $this->documentInspector->getNode($localizedDocument);
            $node->setProperty($propertyName, $route->getPath());

            if ($localizedDocument instanceof ChildrenBehavior) {
                $this->generateChildRoutes($localizedDocument, $locale);
            }
        }
    }

    /**
     * Iterate over children and remove routes.
     *
     * @param string $locale
     */
    private function removeChildRoutes(ChildrenBehavior $document, $locale)
    {
        foreach ($document->getChildren() as $child) {
            if ($child instanceof RoutablePageBehavior) {
                $this->removeChildRoute($child, $locale);
            }

            if ($child instanceof ChildrenBehavior) {
                $this->removeChildRoutes($child, $locale);
            }
        }
    }

    /**
     * Removes route if exists.
     *
     * @param string $locale
     */
    private function removeChildRoute(RoutablePageBehavior $document, $locale)
    {
        $route = $this->routeRepository->findByPath($document->getRoutePath(), $locale);
        if ($route) {
            $this->entityManager->remove($route);
        }
    }

    /**
     * Returns encoded "routePath" property-name.
     *
     * @param string $structureType
     * @param string $locale
     *
     * @return string
     */
    private function getRoutePathPropertyName($structureType, $locale)
    {
        $metadata = $this->metadataFactory->getStructureMetadata('article', $structureType);

        if ($metadata->hasTag(self::TAG_NAME)) {
            return $this->getPropertyName($locale, $metadata->getPropertyByTagName(self::TAG_NAME)->getName());
        }

        return $this->getPropertyName($locale, self::ROUTE_FIELD);
    }

    /**
     * Returns encoded property-name.
     *
     * @param string $locale
     * @param string $field
     *
     * @return string
     */
    private function getPropertyName($locale, $field)
    {
        return $this->propertyEncoder->localizedSystemName($field, $locale);
    }

    /**
     * Returns true if given uuid exists.
     *
     * @param string $uuid
     *
     * @return bool
     */
    private function nodeExists(SessionInterface $session, $uuid)
    {
        try {
            $session->getNodeByIdentifier($uuid);

            return true;
        } catch (ItemNotFoundException $exception) {
            return false;
        }
    }
}
