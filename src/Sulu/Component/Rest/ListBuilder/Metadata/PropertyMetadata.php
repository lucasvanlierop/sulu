<?php

namespace Sulu\Component\Rest\ListBuilder\Metadata;

use Metadata\MergeableInterface;
use Metadata\PropertyMetadata as BasePropertyMetadata;

class PropertyMetadata extends BasePropertyMetadata
{
    /**
     * @var BasePropertyMetadata[]
     */
    private $metadata = [];

    /**
     * @return BasePropertyMetadata[]
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * @param string $name
     * @param BasePropertyMetadata $metadata
     */
    public function addMetadata($name, $metadata)
    {
        $this->metadata[$name] = $metadata;
    }

    /**
     * @param string $name
     *
     * @return BasePropertyMetadata
     */
    public function get($name)
    {
        return $this->metadata[$name];
    }
}
