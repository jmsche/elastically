<?php

/*
 * This file is part of the jolicode/elastically library.
 *
 * (c) JoliCode <coucou@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace JoliCode\Elastically;

use Elastica\Index as ElasticaIndex;
use Elastica\ResultSet\BuilderInterface;
use Elastica\Search;

class Index extends ElasticaIndex
{
    private $builder;

    public function getModel($id)
    {
        $document = $this->getDocument($id);

        return $this->getBuilder()->buildModelFromIndexAndData($document->getIndex(), $document->getData());
    }

    public function createSearch($query = '', $options = null, BuilderInterface $builder = null): Search
    {
        $builder = $builder ?? $this->getBuilder();

        return parent::createSearch($query, $options, $builder);
    }

    public function getBuilder(): ResultSetBuilder
    {
        if (!$this->builder) {
            $this->builder = new ResultSetBuilder($this->getClient());
        }

        return $this->builder;
    }
}
