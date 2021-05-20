<?php

/**
 * The MIT License
 *
 * Copyright 2017 Austrian Centre for Digital Humanities.
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */

namespace acdhOeaw\oai\metadata;

use DOMDocument;
use DOMElement;
use zozlak\queryPart\QueryPart;
use acdhOeaw\arche\lib\RepoResourceDb;
use acdhOeaw\oai\data\MetadataFormat;

/**
 * Creates OAI-PMH &lt;metadata&gt; element in as an RDF-XML serialization of
 * a FedoraResource RDF metadata.
 *
 * @author zozlak
 */
class RdfXml implements MetadataInterface {

    /**
     * Repository resource object
     * @var RepoResourceDb
     */
    private $res;

    /**
     * Creates a metadata object for a given repository resource.
     * 
     * @param RepoResourceDb $resource a repository 
     *   resource object
     * @param object $searchResultRow SPARQL search query result row 
     * @param MetadataFormat $format metadata format descriptor
     *   describing this resource
     */
    public function __construct(RepoResourceDb $resource,
                                object $searchResultRow, MetadataFormat $format) {
        $this->res = $resource;
    }

    /**
     * Creates resource's XML metadata
     * 
     * @return DOMElement 
     */
    public function getXml(): DOMElement {
        $meta   = $this->res->getMetadata();
        $rdfxml = $meta->getGraph()->serialise('rdfxml');
        $doc    = new DOMDocument();
        $doc->loadXML($rdfxml);
        return $doc->documentElement;
    }

    /**
     * This implementation has no need to extend the search query.
     * 
     * @param MetadataFormat $format
     * @return QueryPart
     */
    static public function extendSearchFilterQuery(MetadataFormat $format): QueryPart {
        return new QueryPart();
    }
    
    /**
     * This implementation has no need to extend the search query.
     * 
     * @param MetadataFormat $format
     * @return QueryPart
     */
    static public function extendSearchDataQuery(MetadataFormat $format): QueryPart {
        return new QueryPart();
    }

}
