<?php

/**
 * The MIT License
 *
 * Copyright 2018 Austrian Centre for Digital Humanities.
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
use RuntimeException;
use stdClass;
use EasyRdf\Literal;
use EasyRdf\Resource;
use acdhOeaw\fedora\Fedora;
use acdhOeaw\fedora\FedoraResource;
use acdhOeaw\fedora\metadataQuery\SimpleQuery;
use acdhOeaw\oai\data\MetadataFormat;

/**
 * Creates &lt;metadata&gt; element by filling in an XML template with values
 * read from the repository resource's metadata.
 * 
 * Required metadata format definitition properties:
 * - `uriProp` - metadata property storing resource's OAI-PMH id
 * - `idProp` - metadata property identifying a repository resource
 * - `labelProp` - metadata property storing repository resource label
 * - `schemaProp` - metadata property storing resource's CMDI profile URI
 * - `templateDir` - path to a directory storing XML templates;
 *    each template should have exactly same name as the CMDI profile id, e.g. `clarin.eu:cr1:p_1290431694580.xml`
 * - `defaultLang` - default language to be used when the template doesn't explicitly specify one
 * 
 * Optional metadata format definition properties:
 * - `propNmsp[prefix]` - an array of property URIs namespaces used in the template
 * - `schemaDefault` provides a default CMDI profile (e.g. `clarin.eu:cr1:p_1290431694580.xml`)
 *   to be used when a resource's metadata don't contain the `schemaProp` or none of its values
 *   correspond to an existing CMDI template.
 *   If `schemaDefault` isn't provided, resources which don't contain the `schemaProp`
 *   in their metadata are automatically excluded from the OAI-PMH search.
 * - `schemaEnforce` if provided, only resources with a given value of the `schemaProp`
 *   are processed.
 * 
 * XML tags in the template can be annotated with following attributes:
 * - `val="/propURI"` or `val="/propURI[key]"` - specifies the metadata property 
 *   to fetch the value from;
 *   The `/propURI[key]` means the metadata property value should be parsed as YAML and the `key` value should be used.
 *   There are also few special `val` attribute values: `NOW`, `URI` and `OAIURI`
 *   which correspond, respectively, to the current time, repository resource's URI
 *   and the repository resource's OAI-PMH id. When special `val` attribute values are used
 *   other attributes described bellow are not taken into account.
 * - `count="N"` (default `1`)
 *     - when "*" and metadata contain no property specified by the `val` attribute
 *       the tag is removed from the template;
 *     - when "*" or "+" and metadata contain many properties specified by the `val` attribute
 *       the tag is repeated for each metadata property value
 *     - when "1" or "+" and metadata contain no property specified by the `val` attribute
 *       the tag is left empty in the template;
 *     - when "1" and metadata contain many properties specified by the `val` attribute
 *       first metadata property value is used
 * - `lang="true"` if present and a metadata property value contains information about
 *   the language, the `xml:lang` attribute is added to the template tag
 * - `getLabel="true"` if present and a metadata property value is an URI, corresponding 
 *   resource's label is used as a value instead of the URI
 * 
 * @author zozlak
 */
class LiveCmdiMetadata implements MetadataInterface {

    /**
     * Stores URI to label cache
     * @var array
     */
    static private $labelCache = [];

    /**
     * Resolves an URI to its label
     * @param string $uri
     * @param Fedora $fedora
     * @param MetadataFormat $format
     * @param string $language
     * @return string
     */
    static private function getLabel(string $uri, Fedora $fedora,
                                     MetadataFormat $format,
                                     string $language = ''): string {
        if (!isset(self::$labelCache[$uri])) {
            self::$labelCache[$uri] = [];

            $query   = new SimpleQuery('SELECT ?label WHERE {?@ ^?@ / ?@ ?label.}');
            $query->setValues([$uri, $format->idProp, $format->labelProp]);
            $results = $fedora->runQuery($query);
            foreach ($results as $i) {
                if ($i->label instanceof Literal) {
                    self::$labelCache[$uri][$i->label->getLang()] = (string) $i->label;
                } else {
                    self::$labelCache[$uri][''] = (string) $i->label;
                }
            }
        }
        return self::$labelCache[$uri][$language] ?? (self::$labelCache[$uri][''] ?? $uri);
    }

    /**
     * Repository resource object
     * @var \acdhOeaw\fedora\FedoraResource
     */
    private $res;

    /**
     * Metadata format descriptor
     * @var \acdhOeaw\oai\data\MetadataFormat
     */
    private $format;

    /**
     * Path to the XML template file
     * @var string
     */
    private $template;

    /**
     * Creates a metadata object for a given repository resource.
     * 
     * @param FedoraResource $resource repository resource object
     * @param stdClass $sparqlResultRow SPARQL search query result row 
     * @param MetadataFormat $format metadata format descriptor
     *   describing this resource
     */
    public function __construct(FedoraResource $resource,
                                stdClass $sparqlResultRow,
                                MetadataFormat $format) {
        $this->res    = $resource;
        $this->format = $format;

        $formats = $this->res->getMetadata()->allResources($this->format->schemaProp);
        foreach ($formats as $i) {
            $i    = preg_replace('|^.*(clarin.eu:[^/]+).*$|', '\\1', (string) $i);
            $path = $this->format->templateDir . '/' . $i . '.xml';
            if (file_exists($path)) {
                $this->template = $path;
                break;
            }
        }
        if ($this->template === null && !empty($this->format->schemaDefault)) {
            $this->template = $this->format->templateDir . '/' . $this->format->schemaDefault . '.xml';
        }
        if (empty($this->template)) {
            throw new RuntimeException('No CMDI template matched');
        }
    }

    /**
     * Creates resource's XML metadata
     * 
     * @return DOMElement 
     */
    public function getXml(): DOMElement {
        $doc                     = new DOMDocument();
        $doc->preserveWhiteSpace = false;
        $doc->load($this->template);
        $this->processElement($doc->documentElement);
        return $doc->documentElement;
    }

    /**
     * This implementation has no need to extend the SPRARQL search query.
     * 
     * @param MetadataFormat $format
     * @param string $resVar
     * @return string
     */
    public static function extendSearchQuery(MetadataFormat $format,
                                             string $resVar): string {
        $query = '';
        if (!empty($format->schemaEnforce)) {
            $param = [$format->schemaProp, $format->schemaEnforce];
            $query = new SimpleQuery('{' . $resVar . ' ?@ ?schemaUri . FILTER regex(str(?schemaUri), ?#)}', $param);
            $query = $query->getQuery();
        } else if (empty($format->schemaDefault)) {
            $query = new SimpleQuery($resVar . ' ?@ ?schemaUri .', [$format->schemaProp]);
            $query = $query->getQuery();
        }
        return $query;
    }

    /**
     * Recursively processes all XML elements
     * @param DOMElement $el DOM element to be processed
     */
    private function processElement(DOMElement $el): bool {
        $toRemove = [];
        foreach ($el->childNodes as $ch) {
            if ($ch instanceof DOMElement) {
                $remove = $this->processElement($ch);
                if ($remove) {
                    $toRemove[] = $ch;
                }
            }
        }
        foreach ($toRemove as $i) {
            $el->removeChild($i);
        }

        $remove = false;
        if ($el->hasAttribute('val')) {
            $remove = $this->insertValue($el);
        }
        return $remove;
    }

    /**
     * Injects metadata values into a given DOM element of the CMDI template.
     * @param DOMElement $el DOM element to be processes
     * @return bool should `$el` DOMElement be removed from the document
     */
    private function insertValue(DOMElement $el): bool {
        $val = $el->getAttribute('val');

        $remove = true;
        if ($val === 'NOW') {
            $el->textContent = date('Y-m-d');
            $remove          = false;
        } else if ($val === 'URI') {
            $el->textContent = $this->res->getMetadata()->getResource($this->format->uriProp)->getUri();
            $remove          = false;
        } else if ($val === 'OAIURI') {
            $id              = urlencode($this->res->getMetadata()->getResource($this->format->uriProp)->getUri());
            $prefix          = urlencode($this->format->metadataPrefix);
            $el->textContent = $this->format->info->baseURL . '?verb=GetRecord&metadataPrefix=' . $prefix . '&identifier=' . $id;
            $remove          = false;
        } else if (substr($val, 0, 1) === '/') {
            $this->insertMetaValues($el, substr($val, 1));
        }

        $el->removeAttribute('val');
        return $remove;
    }

    /**
     * Fetches values from repository resource's metadata and creates corresponding
     * CMDI parts.
     * @param DOMElement $el
     * @param string $val DOMElement's `val` attribute value
     */
    private function insertMetaValues(DOMElement $el, string $val) {
        $prop = $val;
        $nmsp = substr($prop, 0, strpos($prop, ':'));
        if ($nmsp !== '' && isset($this->format->propNmsp[$nmsp])) {
            $prop = str_replace($nmsp . ':', $this->format->propNmsp[$nmsp], $prop);
        }
        $i       = strpos($prop, '[');
        $subprop = null;
        if ($i !== false) {
            $subprop = substr($prop, $i + 1, -1);
            $prop    = substr($prop, 0, $i);
        }

        $lang     = ($el->getAttribute('lang') ?? '' ) === 'true';
        $getLabel = ($el->getAttribute('getLabel') ?? '') == 'true';
        $count    = $el->getAttribute('count');
        if (empty($count)) {
            $count = '1';
        }
        $values = [];
        foreach ($this->res->getMetadata()->all($prop) as $i) {
            $language = '';
            $value    = (string) $i;
            if ($i instanceof Literal) {
                $language = $i->getLang();
            }
            if ($i instanceof Resource && $getLabel) {
                $value    = self::getLabel($value, $this->res->getFedora(), $this->format, $this->format->defaultLang);
                $language = $this->format->defaultLang;
            }
            if (!isset($values[$language])) {
                $values[$language] = [];
            }
            if ($subprop !== null) {
                $value = yaml_parse($value)[$subprop];
            }
            $values[$language][] = $value;
        }

        if (count($values) === 0 && in_array($count, ['1', '+'])) {
            $values[''] = [''];
        }
        if ($count === '1') {
            if (isset($values[$this->format->defaultLang])) {
                $values = [$this->format->defaultLang => [$values[$this->format->defaultLang][0]]];
            } else if (isset($values[''])) {
                $values = ['' => [$values[''][0]]];
            } else {
                $values = ['' => [$values[array_keys($values)[0]][0]]];
            }
        }

        $parent = $el->parentNode;
        foreach ($values as $language => $tmp) {
            foreach ($tmp as $value) {
                $ch              = $el->cloneNode(true);
                $ch->removeAttribute('val');
                $ch->removeAttribute('count');
                $ch->removeAttribute('lang');
                $ch->removeAttribute('getLabel');
                $ch->textContent = $value;
                if ($lang && $language !== '') {
                    $ch->setAttribute('xml:lang', $language);
                }
                $parent->appendChild($ch);
            }
        }
    }

}
