<?php
/*
 * This file is part of the Sulu CMS.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Component\Content\Mapper;

use DateTime;
use PHPCR\NodeInterface;
use PHPCR\Query\QueryInterface;
use PHPCR\SessionInterface;
use Sulu\Component\Content\BreadcrumbItem;
use Sulu\Component\Content\BreadcrumbItemInterface;
use Sulu\Component\Content\ContentTypeInterface;
use Sulu\Component\Content\ContentTypeManager;
use Sulu\Component\Content\Exception\StateNotFoundException;
use Sulu\Component\Content\Mapper\Translation\MultipleTranslatedProperties;
use Sulu\Component\Content\Mapper\Translation\TranslatedProperty;
use Sulu\Component\Content\Property;
use Sulu\Component\Content\PropertyInterface;
use Sulu\Component\Content\StructureInterface;
use Sulu\Component\Content\StructureManagerInterface;
use Sulu\Component\Content\StructureType;
use Sulu\Component\Content\Types\ResourceLocatorInterface;
use Sulu\Component\PHPCR\SessionManager\SessionManagerInterface;
use Sulu\Component\Webspace\Localization;
use Sulu\Component\Webspace\Manager\WebspaceManagerInterface;
use Sulu\Component\Webspace\Webspace;

class ContentMapper implements ContentMapperInterface
{
    /**
     * @var WebspaceManagerInterface
     */
    private $webspaceManager;

    /**
     * @var ContentTypeManager
     */
    private $contentTypeManager;

    /**
     * @var StructureManagerInterface
     */
    private $structureManager;

    /**
     * @var SessionManagerInterface
     */
    private $sessionManager;

    /**
     * namespace of translation
     * @var string
     */
    private $languageNamespace;

    /**
     * default language of translation
     * @var string
     */
    private $defaultLanguage;

    /**
     * default template
     * @var string
     */
    private $defaultTemplate;

    /**
     * TODO abstract with cleanup from RLPStrategy
     * replacers for cleanup
     * @var array
     */
    protected $replacers = array(
        'default' => array(
            ' ' => '-',
            '+' => '-',
            'ä' => 'ae',
            'ö' => 'oe',
            'ü' => 'ue',
            // because strtolower ignores Ä,Ö,Ü
            'Ä' => 'ae',
            'Ö' => 'oe',
            'Ü' => 'ue'
            // TODO should be filled
        ),
        'de' => array(
            '&' => 'und'
        ),
        'en' => array(
            '&' => 'and'
        )
    );

    /**
     * excepted states
     * @var array
     */
    private $states = array(
        StructureInterface::STATE_PUBLISHED,
        StructureInterface::STATE_TEST
    );

    private $properties;


    public function __construct(
        WebspaceManagerInterface $webspaceManager,
        ContentTypeManager $contentTypeManager,
        StructureManagerInterface $structureManager,
        SessionManagerInterface $sessionManager,
        $defaultLanguage,
        $defaultTemplate,
        $languageNamespace
    )
    {
        $this->webspaceManager = $webspaceManager;
        $this->contentTypeManager = $contentTypeManager;
        $this->structureManager = $structureManager;
        $this->sessionManager = $sessionManager;
        $this->defaultLanguage = $defaultLanguage;
        $this->defaultTemplate = $defaultTemplate;
        $this->languageNamespace = $languageNamespace;

        // properties
        $this->properties = new MultipleTranslatedProperties(
            array(
                'changer',
                'changed',
                'created',
                'creator',
                'state',
                'template',
                'navigation',
                'published'
            ),
            $this->languageNamespace
        );
    }

    /**
     * saves the given data in the content storage
     * @param array $data The data to be saved
     * @param string $templateKey Name of template
     * @param string $webspaceKey Key of webspace
     * @param string $languageCode Save data for given language
     * @param int $userId The id of the user who saves
     * @param bool $partialUpdate ignore missing property
     * @param string $uuid uuid of node if exists
     * @param string $parentUuid uuid of parent node
     * @param int $state state of node
     * @param string $showInNavigation
     *
     * @return StructureInterface
     */
    public function save(
        $data,
        $templateKey,
        $webspaceKey,
        $languageCode,
        $userId,
        $partialUpdate = true,
        $uuid = null,
        $parentUuid = null,
        $state = null,
        $showInNavigation = null
    )
    {
        // create translated properties
        $this->properties->setLanguage($languageCode);

        $structure = $this->getStructure($templateKey);
        $session = $this->getSession();

        if ($parentUuid !== null) {
            $root = $session->getNodeByIdentifier($parentUuid);
        } else {
            $root = $this->getContentNode($webspaceKey);
        }

        $path = $this->cleanUp($data['title']);

        $dateTime = new \DateTime();

        $titleProperty = new TranslatedProperty($structure->getProperty(
            'title'
        ), $languageCode, $this->languageNamespace);

        $newTranslatedNode = function (NodeInterface $node) use ($userId, $dateTime, &$state, &$showInNavigation) {
            $node->setProperty($this->properties->getName('creator'), $userId);
            $node->setProperty($this->properties->getName('created'), $dateTime);

            if (!isset($state)) {
                $state = StructureInterface::STATE_TEST;
            }
            if (!isset($showInNavigation)) {
                $showInNavigation = false;
            }
        };

        /** @var NodeInterface $node */
        if ($uuid === null) {
            // create a new node
            $path = $this->getUniquePath($path, $root);
            $node = $root->addNode($path);
            $newTranslatedNode($node);

            $node->addMixin('sulu:content');

        } else {
            $node = $session->getNodeByIdentifier($uuid);
            if (!$node->hasProperty($this->properties->getName('template'))) {
                $newTranslatedNode($node);
            }

            $hasSameLanguage = ($languageCode == $this->defaultLanguage);
            $hasSamePath = ($node->getPath() !== $this->getContentNode($webspaceKey)->getPath());
            $hasDifferentTitle = !$node->hasProperty($titleProperty->getName()) ||
                $node->getPropertyValue($titleProperty->getName()) !== $data['title'];

            if ($hasSameLanguage && $hasSamePath && $hasDifferentTitle) {
                $path = $this->getUniquePath($path, $node->getParent());
                $node->rename($path);
                // FIXME refresh session here
            }
        }
        $node->setProperty($this->properties->getName('template'), $templateKey);

        $node->setProperty($this->properties->getName('changer'), $userId);
        $node->setProperty($this->properties->getName('changed'), $dateTime);

        // do not state transition for root (contents) node
        $contentRootNode = $this->getContentNode($webspaceKey);
        if ($node->getPath() !== $contentRootNode->getPath() && isset($state)) {
            $this->changeState(
                $node,
                $state,
                $structure,
                $this->properties->getName('state'),
                $this->properties->getName('published')
            );
        }
        if (isset($showInNavigation)) {
            $node->setProperty($this->properties->getName('navigation'), $showInNavigation);
        }

        $postSave = array();

        // go through every property in the template
        /** @var PropertyInterface $property */
        foreach ($structure->getProperties() as $property) {

            // allow null values in data
            if (isset($data[$property->getName()])) {
                $type = $this->getContentType($property->getContentTypeName());
                $value = $data[$property->getName()];
                $property->setValue($value);

                // add property to post save action
                if ($type->getType() == ContentTypeInterface::POST_SAVE) {
                    $postSave[] = array(
                        'type' => $type,
                        'property' => $property
                    );
                } else {
                    $type->write(
                        $node,
                        new TranslatedProperty($property, $languageCode, $this->languageNamespace),
                        $userId,
                        $webspaceKey,
                        $languageCode,
                        null
                    );
                }
            } elseif (!$partialUpdate) {
                $type = $this->getContentType($property->getContentTypeName());
                // if it is not a partial update remove property
                $type->remove(
                    $node,
                    new TranslatedProperty($property, $languageCode, $this->languageNamespace),
                    $webspaceKey,
                    $languageCode,
                    null
                );
            }
            // if it is a partial update ignore property
        }

        // save node now
        $session->save();

        // set post save content types properties
        foreach ($postSave as $post) {
            try {
                /** @var ContentTypeInterface $type */
                $type = $post['type'];
                /** @var PropertyInterface $property */
                $property = $post['property'];

                $type->write(
                    $node,
                    new TranslatedProperty($property, $languageCode, $this->languageNamespace),
                    $userId,
                    $webspaceKey,
                    $languageCode,
                    null
                );
            } catch (\Exception $ex) {
                // TODO Introduce a PostSaveException, so that we don't have to catch everything
                // FIXME message for user or log entry
            }
        }

        $session->save();

        $structure->setUuid($node->getPropertyValue('jcr:uuid'));
        $structure->setWebspaceKey($webspaceKey);
        $structure->setLanguageCode($languageCode);
        $structure->setCreator($node->getPropertyValue($this->properties->getName('creator')));
        $structure->setChanger($node->getPropertyValue($this->properties->getName('changer')));
        $structure->setCreated($node->getPropertyValue($this->properties->getName('created')));
        $structure->setChanged($node->getPropertyValue($this->properties->getName('changed')));

        $structure->setNavigation(
            $node->getPropertyValueWithDefault($this->properties->getName('navigation'), false)
        );
        $structure->setGlobalState(
            $this->getInheritedState($node, $this->properties->getName('state'), $webspaceKey)
        );
        $structure->setPublished(
            $node->getPropertyValueWithDefault($this->properties->getName('published'), null)
        );

        return $structure;
    }

    /**
     * change state of given node
     * @param NodeInterface $node node to change state
     * @param int $state new state
     * @param \Sulu\Component\Content\StructureInterface $structure
     * @param string $statePropertyName
     * @param string $publishedPropertyName
     *
     * @throws \Sulu\Component\Content\Exception\StateTransitionException
     * @throws \Sulu\Component\Content\Exception\StateNotFoundException
     */
    private function changeState(
        NodeInterface $node,
        $state,
        StructureInterface $structure,
        $statePropertyName,
        $publishedPropertyName
    )
    {
        if (!in_array($state, $this->states)) {
            throw new StateNotFoundException($state);
        }

        // no state (new node) set state
        if (!$node->hasProperty($statePropertyName)) {
            $node->setProperty($statePropertyName, $state);
            $structure->setNodeState($state);

            // published => set only once
            if ($state === StructureInterface::STATE_PUBLISHED && !$node->hasProperty($publishedPropertyName)) {
                $node->setProperty($publishedPropertyName, new DateTime());
            }
        } else {
            $oldState = $node->getPropertyValue($statePropertyName);

            if ($oldState === $state) {
                // do nothing
                return;
            } elseif (
                // from test to published
                $oldState === StructureInterface::STATE_TEST &&
                $state === StructureInterface::STATE_PUBLISHED
            ) {
                $node->setProperty($statePropertyName, $state);
                $structure->setNodeState($state);

                // set only once
                if (!$node->hasProperty($publishedPropertyName)) {
                    $node->setProperty($publishedPropertyName, new DateTime());
                }
            } elseif (
                // from published to test
                $oldState === StructureInterface::STATE_PUBLISHED &&
                $state === StructureInterface::STATE_TEST
            ) {
                $node->setProperty($statePropertyName, $state);
                $structure->setNodeState($state);

                // set published date to null
                $node->getProperty($publishedPropertyName)->remove();
            }
        }
    }

    /**
     * saves the given data in the content storage
     * @param array $data The data to be saved
     * @param string $templateKey Name of template
     * @param string $webspaceKey Key of webspace
     * @param string $languageCode Save data for given language
     * @param int $userId The id of the user who saves
     * @param bool $partialUpdate ignore missing property
     *
     * @throws \PHPCR\ItemExistsException if new title already exists
     *
     * @return StructureInterface
     */
    public function saveStartPage(
        $data,
        $templateKey,
        $webspaceKey,
        $languageCode,
        $userId,
        $partialUpdate = true
    )
    {
        $uuid = $this->getContentNode($webspaceKey)->getIdentifier();
        return $this->save(
            $data,
            $templateKey,
            $webspaceKey,
            $languageCode,
            $userId,
            $partialUpdate,
            $uuid,
            null,
            StructureInterface::STATE_PUBLISHED,
            true
        );
    }

    /**
     * {@inheritDoc}
     */
    public function loadByParent(
        $uuid,
        $webspaceKey,
        $languageCode,
        $depth = 1,
        $flat = true,
        $ignoreExceptions = false,
        $loadGhosts = true
    )
    {
        if ($uuid != null) {
            $root = $this->getSession()->getNodeByIdentifier($uuid);
        } else {
            $root = $this->getContentNode($webspaceKey);
        }
        return $this->loadByParentNode(
            $root,
            $webspaceKey,
            $languageCode,
            $depth,
            $flat,
            $ignoreExceptions,
            $loadGhosts
        );
    }

    /**
     * returns a list of data from children of given node
     * @param NodeInterface $parent
     * @param $webspaceKey
     * @param $languageCode
     * @param int $depth
     * @param bool $flat
     * @param bool $ignoreExceptions
     * @param bool $loadGhosts If true ghost pages are also loaded
     * @throws \Exception
     * @return array
     */
    private function loadByParentNode(
        NodeInterface $parent,
        $webspaceKey,
        $languageCode,
        $depth = 1,
        $flat = true,
        $ignoreExceptions = false,
        $loadGhosts
    )
    {
        $results = array();

        /** @var NodeInterface $node */
        foreach ($parent->getNodes() as $node) {
            try {
                $result = $this->loadByNode($node, $languageCode, $webspaceKey, $loadGhosts);
                if ($result) {
                    $results[] = $result;
                }
                if ($depth === null || $depth > 1) {
                    $children = $this->loadByParentNode(
                        $node,
                        $webspaceKey,
                        $languageCode,
                        $depth !== null ? $depth - 1 : null,
                        $flat,
                        $ignoreExceptions,
                        $loadGhosts
                    );
                    if ($flat) {
                        $results = array_merge($results, $children);
                    } else {
                        $result->setChildren($children);
                    }
                }
            } catch (\Exception $ex) {
                if (!$ignoreExceptions) {
                    throw $ex;
                }
            }
        }

        return $results;
    }

    /**
     * returns the data from the given id
     * @param string $uuid UUID of the content
     * @param string $webspaceKey Key of webspace
     * @param string $languageCode Read data for given language
     * @return StructureInterface
     */
    public function load($uuid, $webspaceKey, $languageCode)
    {
        $session = $this->getSession();
        $contentNode = $session->getNodeByIdentifier($uuid);

        return $this->loadByNode($contentNode, $languageCode, $webspaceKey);
    }

    /**
     * returns the data from the given id
     * @param string $webspaceKey Key of webspace
     * @param string $languageCode Read data for given language
     * @return StructureInterface
     */
    public function loadStartPage($webspaceKey, $languageCode)
    {
        $uuid = $this->getContentNode($webspaceKey)->getIdentifier();

        $startPage = $this->load($uuid, $webspaceKey, $languageCode);
        $startPage->setNodeState(StructureInterface::STATE_PUBLISHED);
        $startPage->setGlobalState(StructureInterface::STATE_PUBLISHED);
        $startPage->setNavigation(true);
        return $startPage;
    }

    /**
     * returns data from given path
     * @param string $resourceLocator Resource locator
     * @param string $webspaceKey Key of webspace
     * @param string $languageCode
     * @return StructureInterface
     */
    public function loadByResourceLocator($resourceLocator, $webspaceKey, $languageCode)
    {
        $session = $this->getSession();
        $uuid = $this->getResourceLocator()->loadContentNodeUuid($resourceLocator, $webspaceKey);
        $contentNode = $session->getNodeByIdentifier($uuid);

        return $this->loadByNode($contentNode, $languageCode, $webspaceKey);
    }

    /**
     * returns the content returned by the given sql2 query as structures
     * @param string $sql2 The query, which returns the content
     * @param string $languageCode The language code
     * @param string $webspaceKey The webspace key
     * @param int $limit Limits the number of returned rows
     * @return StructureInterface[]
     */
    public function loadBySql2($sql2, $languageCode, $webspaceKey, $limit = null)
    {
        $structures = array();

        $query = $this->createSql2Query($sql2, $limit);
        $result = $query->execute();

        foreach ($result->getNodes() as $node) {
            $structures[] = $this->loadByNode($node, $languageCode, $webspaceKey);
        }

        return $structures;
    }

    /**
     * returns a sql2 query
     * @param string $sql2 The query, which returns the content
     * @param int $limit Limits the number of returned rows
     * @return QueryInterface
     */
    private function createSql2Query($sql2, $limit = null)
    {
        $queryManager = $this->getSession()->getWorkspace()->getQueryManager();
        $query = $queryManager->createQuery($sql2, 'JCR-SQL2');
        if ($limit) {
            $query->setLimit($limit);
        }
        return $query;
    }

    /**
     * Returns the closes available localization for the given node
     * @param NodeInterface $contentNode The node to look up the localizations
     * @param string $localizationCode The code for which the data should be loaded
     * @param string $webspaceKey The key for the webspace to look for the defined localizations
     * @return \Sulu\Component\Webspace\Localization
     */
    private function getAvailableLocalization(NodeInterface $contentNode, $localizationCode, $webspaceKey)
    {
        // use created field to check localization availability
        $property = new TranslatedProperty(
            new Property('title', 'none'), // FIXME none as type is a dirty hack
            $localizationCode,
            $this->languageNamespace
        );

        // get localization object for querying parent localizations
        $webspace = $this->webspaceManager->findWebspaceByKey($webspaceKey);
        $localization = $webspace->getLocalization($localizationCode);
        $resultLocalization = null;

        // check if it already is the correct localization
        if ($contentNode->hasProperty($property->getName())) {
            $resultLocalization = $localization;
        }

        // find first available localization in parents
        if (!$resultLocalization) {
            $resultLocalization = $this->findAvailableParentLocalization(
                $contentNode,
                $localization,
                $property
            );
        }

        // find first available localization in children
        if (!$resultLocalization) {
            $resultLocalization = $this->findAvailableChildLocalization($contentNode, $localization, $property);
        }

        // find any localization available
        if (!$resultLocalization) {
            $resultLocalization = $this->findAvailableLocalization(
                $contentNode,
                $webspace->getLocalizations(),
                $property
            );
        }

        return $resultLocalization;
    }

    /**
     * Finds any localization, in which the node is translated
     * @param NodeInterface $contentNode The node, which properties will be checkec
     * @param array $localizations The available localizations
     * @param TranslatedProperty $property The property to check
     * @return null|Localization
     */
    private function findAvailableLocalization(
        NodeInterface $contentNode,
        array $localizations,
        TranslatedProperty $property
    )
    {
        foreach ($localizations as $localization) {
            /** @var Localization $localization */
            $property->setLocalization($localization->getLocalization());
            if ($contentNode->hasProperty($property->getName())) {
                return $localization;
            }

            $childrenLocalizations = $localization->getChildren();
            if (!empty($childrenLocalizations)) {
                return $this->findAvailableLocalization($contentNode, $childrenLocalizations, $property);
            }
        }

        return null;
    }

    /**
     * Finds the next available child-localization in which the node has a translation
     * @param NodeInterface $contentNode The node, which properties will be checked
     * @param Localization $localization The localization to start the search for
     * @param TranslatedProperty $property The property which will be checked for the translation
     * @return null|Localization
     */
    private function findAvailableChildLocalization(
        NodeInterface $contentNode,
        Localization $localization,
        TranslatedProperty $property
    )
    {
        $childrenLocalizations = $localization->getChildren();
        if (!empty($childrenLocalizations)) {
            foreach ($childrenLocalizations as $childrenLocalization) {
                $property->setLocalization($childrenLocalization->getLocalization('_'));
                // return the localization if a translation exists in the child localization
                if ($contentNode->hasProperty($property->getName())) {
                    return $childrenLocalization;
                }

                // recursively call this function for checking children
                return $this->findAvailableChildLocalization($contentNode, $childrenLocalization, $property);
            }
        }

        // return null if nothing was found
        return null;
    }

    /**
     * Finds the next available parent-localization in which the node has a translation
     * @param NodeInterface $contentNode The node, which properties will be checked
     * @param \Sulu\Component\Webspace\Localization $localization The localization to start the search for
     * @param TranslatedProperty $property The property which will be checked for the translation
     * @return Localization|null
     */
    private function findAvailableParentLocalization(
        NodeInterface $contentNode,
        Localization $localization,
        TranslatedProperty $property
    )
    {
        do {
            $property->setLocalization($localization->getLocalization('_'));
            if ($contentNode->hasProperty($property->getName())) {
                return $localization;
            }

            // try to load parent and stop if there is no parent
            $localization = $localization->getParent();
        } while ($localization != null);

        return null;
    }

    /**
     * returns data from given node
     * @param NodeInterface $contentNode
     * @param string $localization
     * @param string $webspaceKey
     * @param bool $loadGhost True if also a ghost page should be returned, otherwise false
     * @return StructureInterface
     */
    private function loadByNode(NodeInterface $contentNode, $localization, $webspaceKey, $loadGhost = true)
    {
        $availableLocalization = $this->getAvailableLocalization($contentNode, $localization, $webspaceKey);
        if (!$loadGhost && $availableLocalization->getLocalization() != $localization) {
            return null;
        }

        // create translated properties
        $this->properties->setLanguage($availableLocalization->getLocalization('_'));

        $templateKey = $contentNode->getPropertyValueWithDefault(
            $this->properties->getName('template'),
            $this->defaultTemplate
        );

        $structure = $this->getStructure($templateKey);

        // set structure to ghost, if the available localization does not match the requested one
        if ($availableLocalization->getLocalization('_') != $localization) {
            $structure->setType(StructureType::getGhost($availableLocalization));
        }

        $structure->setHasTranslation($contentNode->hasProperty($this->properties->getName('template')));

        $structure->setUuid($contentNode->getPropertyValue('jcr:uuid'));
        $structure->setWebspaceKey($webspaceKey);
        $structure->setLanguageCode($localization);
        $structure->setCreator($contentNode->getPropertyValueWithDefault($this->properties->getName('creator'), 0));
        $structure->setChanger($contentNode->getPropertyValueWithDefault($this->properties->getName('changer'), 0));
        $structure->setCreated(
            $contentNode->getPropertyValueWithDefault($this->properties->getName('created'), new \DateTime())
        );
        $structure->setChanged(
            $contentNode->getPropertyValueWithDefault($this->properties->getName('changed'), new \DateTime())
        );
        $structure->setHasChildren($contentNode->hasNodes());

        $structure->setNodeState(
            $contentNode->getPropertyValueWithDefault(
                $this->properties->getName('state'),
                StructureInterface::STATE_TEST
            )
        );
        $structure->setNavigation(
            $contentNode->getPropertyValueWithDefault($this->properties->getName('navigation'), false)
        );
        $structure->setGlobalState(
            $this->getInheritedState($contentNode, $this->properties->getName('state'), $webspaceKey)
        );
        $structure->setPublished(
            $contentNode->getPropertyValueWithDefault($this->properties->getName('published'), null)
        );

        // go through every property in the template
        /** @var PropertyInterface $property */
        foreach ($structure->getProperties() as $property) {
            $type = $this->getContentType($property->getContentTypeName());
            $type->read(
                $contentNode,
                new TranslatedProperty(
                    $property,
                    $availableLocalization->getLocalization('_'),
                    $this->languageNamespace
                ),
                $webspaceKey,
                $availableLocalization->getLocalization('_'),
                null
            );
        }

        return $structure;
    }

    /**
     * load breadcrumb for given uuid in given language
     * @param $uuid
     * @param $languageCode
     * @param $webspaceKey
     * @return BreadcrumbItemInterface[]
     */
    public function loadBreadcrumb($uuid, $languageCode, $webspaceKey)
    {
        $this->properties->setLanguage($languageCode);

        $sql = 'SELECT *
                FROM  [sulu:content] as parent INNER JOIN [sulu:content] as child
                    ON ISDESCENDANTNODE(child, parent)
                WHERE child.[jcr:uuid]="' . $uuid . '"';

        $query = $this->createSql2Query($sql);
        $nodes = $query->execute();

        $result = array();
        $groundDepth = $this->getContentNode($webspaceKey)->getDepth();

        /** @var NodeInterface $node */
        foreach ($nodes->getNodes() as $node) {
            // uuid
            $nodeUuid = $node->getIdentifier();
            // depth
            $depth = $node->getDepth() - $groundDepth;
            // title
            $templateKey = $node->getPropertyValueWithDefault(
                $this->properties->getName('template'),
                $this->defaultTemplate
            );
            $structure = $this->getStructure($templateKey);
            $property = $structure->getProperty('title');
            $type = $this->getContentType($property->getContentTypeName());
            $type->read(
                $node,
                new TranslatedProperty($property, $languageCode, $this->languageNamespace),
                $webspaceKey,
                $languageCode,
                null
            );
            $title = $property->getValue();

            $result[] = new BreadcrumbItem($depth, $nodeUuid, $title);
        }

        return $result;
    }

    /**
     * deletes content with subcontent in given webspace
     * @param string $uuid UUID of content
     * @param string $webspaceKey Key of webspace
     */
    public function delete($uuid, $webspaceKey)
    {
        $session = $this->getSession();
        $contentNode = $session->getNodeByIdentifier($uuid);

        $this->deleteRecursively($contentNode);
        $session->save();
    }

    /**
     * remove node with references (path, history path ...)
     * @param NodeInterface $node
     */
    private function deleteRecursively(NodeInterface $node)
    {
        foreach ($node->getReferences() as $ref) {
            if ($ref instanceof \PHPCR\PropertyInterface) {
                $this->deleteRecursively($ref->getParent());
            } else {
                $this->deleteRecursively($ref);
            }
        }
        $node->remove();
    }

    /**
     * returns a structure with given key
     * @param string $key key of content type
     * @return StructureInterface
     */
    protected function getStructure($key)
    {
        return $this->structureManager->getStructure($key);
    }

    /**
     * @return ResourceLocatorInterface
     */
    public function getResourceLocator()
    {
        return $this->getContentType('resource_locator');
    }

    /**
     * returns a type with given name
     * @param $name
     * @return ContentTypeInterface
     */
    protected function getContentType($name)
    {
        return $this->contentTypeManager->get($name);
    }

    /**
     * @param $webspaceKey
     * @return NodeInterface
     */
    protected function getContentNode($webspaceKey)
    {
        return $this->sessionManager->getContentNode($webspaceKey);
    }

    /**
     * @return SessionInterface
     */
    protected function getSession()
    {
        return $this->sessionManager->getSession();
    }

    /**
     * @param $webspaceKey
     * @return NodeInterface
     */
    protected function getRouteNode($webspaceKey)
    {
        return $this->sessionManager->getRouteNode($webspaceKey);
    }

    /**
     * TODO abstract with cleanup from RLPStrategy
     * @param string $dirty
     * @return string
     */
    protected function cleanUp($dirty)
    {
        $clean = strtolower($dirty);

        // TODO language
        $languageCode = 'de';
        $replacers = array_merge($this->replacers['default'], $this->replacers[$languageCode]);

        if (count($replacers) > 0) {
            foreach ($replacers as $key => $value) {
                $clean = str_replace($key, $value, $clean);
            }
        }

        // Inspired by ZOOLU
        // delete problematic characters
        $clean = str_replace('%2F', '/', urlencode(preg_replace('/([^A-za-z0-9\s-_\/])/', '', $clean)));

        // replace multiple minus with one
        $clean = preg_replace('/([-]+)/', '-', $clean);

        // delete minus at the beginning or end
        $clean = preg_replace('/^([-])/', '', $clean);
        $clean = preg_replace('/([-])$/', '', $clean);

        // remove double slashes
        $clean = str_replace('//', '/', $clean);

        return $clean;
    }

    /**
     * @param $name
     * @param NodeInterface $parent
     * @return string
     */
    private function getUniquePath($name, NodeInterface $parent)
    {
        if ($parent->hasNode($name)) {
            $i = 0;
            do {
                $i++;
            } while ($parent->hasNode($name . '-' . $i));
            return $name . '-' . $i;
        } else {
            return $name;
        }
    }

    private function getInheritedState(NodeInterface $contentNode, $statePropertyName, $webspaceKey)
    {
        // index page is default PUBLISHED
        $contentRootNode = $this->getContentNode($webspaceKey);
        if ($contentNode->getName() === $contentRootNode->getPath()) {
            return StructureInterface::STATE_PUBLISHED;
        }

        // if test then return it
        if ($contentNode->getPropertyValueWithDefault(
                $statePropertyName,
                StructureInterface::STATE_TEST
            ) === StructureInterface::STATE_TEST
        ) {
            return StructureInterface::STATE_TEST;
        }

        $session = $this->getSession();
        $workspace = $session->getWorkspace();
        $queryManager = $workspace->getQueryManager();

        $sql = 'SELECT *
                FROM  [sulu:content] as parent INNER JOIN [sulu:content] as child
                    ON ISDESCENDANTNODE(child, parent)
                WHERE child.[jcr:uuid]="' . $contentNode->getIdentifier() . '"';

        $query = $queryManager->createQuery($sql, 'JCR-SQL2');
        $result = $query->execute();

        /** @var \PHPCR\NodeInterface $node */
        foreach ($result->getNodes() as $node) {
            // exclude /cmf/sulu_io/contents
            if (
                $node->getPath() !== $contentRootNode->getPath() &&
                $node->getPropertyValueWithDefault(
                    $statePropertyName,
                    StructureInterface::STATE_TEST
                ) === StructureInterface::STATE_TEST
            ) {
                return StructureInterface::STATE_TEST;
            }
        }

        return StructureInterface::STATE_PUBLISHED;
    }
}
