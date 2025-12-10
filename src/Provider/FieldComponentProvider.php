<?php

namespace LumenApiQueryParser\Provider;

use Illuminate\Database\Eloquent\Builder;
use LumenApiQueryParser\Params\Filter;
use LumenApiQueryParser\Utility\ConnectionParser;

class FieldComponentProvider
{

    protected $query;
    protected $filter;
    protected $connections;
    protected $field;

    protected $errors = [];

    public function __construct(Builder $query, Filter $filter)
    {
        $this->setQuery($query);
        $this->setFilter($filter);
        $this->setField($filter->getField());

        // if the field does not have a delimeter: do not check for connection unless the operator is a 'fieldless' type ('has'). 
        if (strstr($filter->getField(), '.') === false && $filter->getOperator() !== 'has') {
            return;
        }

        $parser = new ConnectionParser($query, $this->getField());
        if ($parser->hasConnections()) {
            $this->setConnections($parser->getConnections());
            $parts = (explode('.', $this->getField()) ?: []);
            if (count($parts) > 1) {
                $this->setField(array_pop($parts));
            } else {
                $this->setField(null);
            }
        }
    }

    /**
     * @return Builder
     */
    public function getQuery(): Builder
    {
        return $this->query;
    }

    /**
     * @param Builder $query
     */
    private function setQuery(Builder $query): void
    {
        $this->query = $query;
    }

    /**
     * @return Filter
     */
    public function getFilter(): Filter
    {
        return $this->filter;
    }

    /**
     * @param Filter $filter
     */
    private function setFilter(Filter $filter): void
    {
        $this->filter = $filter;
    }

    /**
     * @return mixed
     */
    public function getField()
    {
        return $this->field;
    }

    /**
     * @param mixed $field
     */
    private function setField($field): void
    {
        $this->field = $field;
    }

    /**
     * @return mixed
     */
    public function getConnections()
    {
        return $this->connections;
    }

    /**
     * @param mixed $connections
     */
    private function setConnections($connections): void
    {
        $this->connections = $connections;
    }

    public function getConnectionString()
    {
        if (!$this->hasConnections()) {
            return null;
        }
        return ConnectionParser::connectionsToString($this->getConnections());
    }

    public function hasConnections()
    {
        $connections = $this->getConnections();
        if ($connections && count($connections)) {
            return true;
        }
        return false;
    }

    public function toArray()
    {
        return [
            'filter_field' => $this->getFilter()->getField(),
            'field' => $this->getField(),
            'connections' => $this->getConnections(),
            'errors' => $this->errors,
        ];
    }
}
